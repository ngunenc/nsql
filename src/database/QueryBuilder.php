<?php

namespace nsql\database;

/**
 * Akıcı SELECT sorgu oluşturucu.
 *
 * Kolon/tablo argümanları yalnızca şu biçimleri kabul eder ve driver'a göre quote edilir:
 * `kolon`, `tablo.kolon`, `tablo.*`, `*`, tamsayı (yalnızca select), izinli aggregate
 * (`COUNT(*)`, `SUM(kolon)`, `COUNT(DISTINCT kolon)` …) ve select'te `ifade AS takma_ad`.
 *
 * Serbest SQL ifadeleri için *_raw() metodlarını kullanın; raw içerik doğrulanmaz ve
 * kullanıcı girdisi içermemelidir. JOIN closure'ının döndürdüğü koşul da raw kabul edilir.
 *
 * Sorgu parçaları eklendikleri anda doğrulanır ama placeholder'lar yalnızca compile()
 * sırasında tek bir sayaçla (`:__p0`, `:__p1` …) üretilir; subquery, UNION ve raw
 * binding'ler aynı sayaçla yeniden adlandırılır. compile() yan etkisizdir.
 */
class QueryBuilder
{
    private const AGGREGATES = 'COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT';
    private const PARAM_PREFIX = ':__p';

    private Nsql $db;

    /** @var list<string|array> Sorgu parçası: SQL metni, değer, subquery veya raw */
    private array $table = [];
    /** @var list<list<string|array>> */
    private array $columns = [['*']];
    /** @var list<list<string|array>> */
    private array $where = [];
    /** @var list<'AND'|'OR'> where[] ile aynı indeks; ilk eleman yok sayılır */
    private array $where_bools = [];
    /** @var 'AND'|'OR' */
    private string $next_where_bool = 'AND';
    /** table() ile verilen düz tablo adı (yazma işlemleri için); subquery FROM'da null */
    private ?string $table_name = null;
    /** @var list<string> */
    private array $group_by = [];
    /** @var list<list<string|array>> */
    private array $having = [];
    /** @var list<string> */
    private array $order_by = [];
    private ?int $limit = null;
    private int $offset = 0;
    /** @var list<array{type: string, table: list<string|array>, condition: ?string}> */
    private array $joins = [];
    /** @var list<array{builder: QueryBuilder, all: bool}> */
    private array $unions = [];
    private bool $allow_empty_string;

    public function __construct(Nsql $db)
    {
        $this->db = $db;
        $this->allow_empty_string = (bool) Config::get('query_builder_allow_empty_string', true);
    }

    /**
     * Boş string ('') değerlerin koşullarda kullanılmasına izin verir veya engeller.
     * Varsayılan: QUERY_BUILDER_ALLOW_EMPTY_STRING (true).
     */
    public function allow_empty_strings(bool $allow = true): self
    {
        $this->allow_empty_string = $allow;

        return $this;
    }

    /**
     * Tabloyu belirler
     *
     * @param string $table Tablo adı (`tablo` veya `şema.tablo`)
     */
    public function table(string $table): self
    {
        $this->table = [$this->compile_reference($table)];
        $this->table_name = $table;

        return $this;
    }

    /**
     * FROM clause
     *
     * @param string|QueryBuilder $table Tablo adı veya subquery builder
     * @param string|null $alias Alias adı (subquery kullanılıyorsa zorunlu)
     */
    public function from($table, ?string $alias = null): self
    {
        if ($table instanceof QueryBuilder) {
            if ($alias === null) {
                throw new \InvalidArgumentException('FROM subquery için alias zorunludur.');
            }

            $this->table = $this->subquery_part($table, $alias);
            $this->table_name = null;

            return $this;
        }

        return $this->table($table);
    }

    /**
     * Seçilecek sütunları belirler
     *
     * @param string|QueryBuilder ...$columns Sütunlar (`ifade AS takma_ad` desteklenir) veya subquery builder
     */
    public function select(...$columns): self
    {
        $this->columns = [];
        foreach ($columns as $column) {
            $this->columns[] = match (true) {
                $column instanceof QueryBuilder => $this->subquery_part($column),
                $column instanceof RawExpression => [$this->raw_part($column->sql, $column->bindings)],
                default => [$this->compile_column((string) $column, true)],
            };
        }

        return $this;
    }

    /**
     * Doğrulanmadan SELECT listesine eklenen ifade. Kullanıcı girdisi içermemelidir.
     */
    public function select_raw(string $expression): self
    {
        if ($this->columns === [['*']]) {
            $this->columns = [];
        }
        $this->columns[] = [$expression];

        return $this;
    }

    /**
     * WHERE koşulu ekler. Callable verilirse parantezli koşul grubu oluşturur.
     *
     * @param string|callable(self): mixed $column Sütun adı veya grup callable'ı
     * @param string|null $operator Operatör (=, >, <, etc.)
     * @param mixed $value Değer veya subquery builder
     */
    public function where(string|callable $column, ?string $operator = null, $value = null): self
    {
        if (! is_string($column)) {
            return $this->where_group($column);
        }
        if ($operator === null) {
            throw new \InvalidArgumentException('where() için operatör gerekli.');
        }

        $this->add_where($this->condition_part($column, $operator, $value));

        return $this;
    }

    /**
     * OR ile bağlanan WHERE koşulu. Callable verilirse parantezli grup oluşturur.
     */
    public function or_where(string|callable $column, ?string $operator = null, mixed $value = null): self
    {
        return $this->with_or(fn () => $this->where($column, $operator, $value));
    }

    public function or_where_in(string $column, array $values): self
    {
        return $this->with_or(fn () => $this->where_in($column, $values));
    }

    public function or_where_null(string $column): self
    {
        return $this->with_or(fn () => $this->where_null($column));
    }

    /**
     * WHERE kolon BETWEEN min AND max
     */
    public function where_between(string $column, mixed $min, mixed $max): self
    {
        return $this->between($column, $min, $max, false);
    }

    public function where_not_between(string $column, mixed $min, mixed $max): self
    {
        return $this->between($column, $min, $max, true);
    }

    public function or_where_between(string $column, mixed $min, mixed $max): self
    {
        return $this->with_or(fn () => $this->between($column, $min, $max, false));
    }

    /**
     * Koşul doğruysa $callback, değilse (varsa) $default çalıştırılır. Callable builder'ı ve koşulu alır.
     *
     * @param callable(self, mixed): mixed $callback
     * @param (callable(self, mixed): mixed)|null $default
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): self
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default !== null) {
            $default($this, $condition);
        }

        return $this;
    }

    /**
     * Doğrulanmadan SQL'e eklenen ifade: where() değeri, insert/update/upsert değeri veya select()
     * kolonu olarak kullanılabilir. Değerler için `:ad` placeholder'ı ve $bindings kullanın;
     * kullanıcı girdisini SQL metnine eklemeyin.
     *
     * @param array<string, mixed> $bindings
     */
    public static function raw(string $sql, array $bindings = []): RawExpression
    {
        return new RawExpression($sql, $bindings);
    }

    /**
     * Parantezli WHERE grubu: $fn koşulları geçici bir builder'a ekler.
     */
    private function where_group(callable $fn): self
    {
        $group = new self($this->db);
        $group->allow_empty_string = $this->allow_empty_string;
        $fn($group);

        if ($group->where === []) {
            return $this;
        }

        $part = ['('];
        foreach ($group->where as $i => $condition) {
            if ($i > 0) {
                $part[] = ' ' . $group->where_bools[$i] . ' ';
            }
            array_push($part, ...$condition);
        }
        $part[] = ')';
        $this->add_where($part);

        return $this;
    }

    private function between(string $column, mixed $min, mixed $max, bool $not): self
    {
        $this->add_where([
            $this->compile_column($column) . ($not ? ' NOT BETWEEN ' : ' BETWEEN '),
            $this->value_part($column, $min),
            ' AND ',
            $this->value_part($column, $max),
        ]);

        return $this;
    }

    private function with_or(callable $fn): self
    {
        $this->next_where_bool = 'OR';
        try {
            $fn();
        } finally {
            $this->next_where_bool = 'AND';
        }

        return $this;
    }

    /**
     * @param list<string|array> $part
     */
    private function add_where(array $part): void
    {
        $this->where[] = $part;
        $this->where_bools[] = $this->next_where_bool;
    }

    /**
     * Doğrulanmadan eklenen WHERE koşulu. Değerler için `:ad` placeholder'ı ve $bindings kullanın.
     *
     * @param array<string, mixed> $bindings ['ad' => değer]
     */
    public function where_raw(string $condition, array $bindings = []): self
    {
        $this->add_where(['(', $this->raw_part($condition, $bindings), ')']);

        return $this;
    }

    /**
     * WHERE kolon IN (...) ekler. Boş dizi hiçbir satırla eşleşmez (`1 = 0`).
     *
     * @param array<int|string, mixed> $values
     */
    public function where_in(string $column, array $values): self
    {
        $this->add_where($this->in_part($this->compile_column($column), $values, false));

        return $this;
    }

    /**
     * WHERE kolon NOT IN (...) ekler. Boş dizi tüm satırlarla eşleşir (`1 = 1`).
     *
     * @param array<int|string, mixed> $values
     */
    public function where_not_in(string $column, array $values): self
    {
        $this->add_where($this->in_part($this->compile_column($column), $values, true));

        return $this;
    }

    public function where_null(string $column): self
    {
        $this->add_where([$this->compile_column($column) . ' IS NULL']);

        return $this;
    }

    public function where_not_null(string $column): self
    {
        $this->add_where([$this->compile_column($column) . ' IS NOT NULL']);

        return $this;
    }

    /**
     * WHERE IN subquery ekler
     */
    public function where_in_subquery(string $column, QueryBuilder $subquery, bool $not = false): self
    {
        $operator = $not ? 'NOT IN' : 'IN';
        $this->add_where([$this->compile_column($column) . " $operator ", ...$this->subquery_part($subquery)]);

        return $this;
    }

    /**
     * WHERE EXISTS subquery ekler
     */
    public function where_exists(QueryBuilder $subquery, bool $not = false): self
    {
        $operator = $not ? 'NOT EXISTS' : 'EXISTS';
        $this->add_where(["$operator ", ...$this->subquery_part($subquery)]);

        return $this;
    }

    /**
     * WHERE NOT EXISTS subquery ekler (convenience method)
     */
    public function where_not_exists(QueryBuilder $subquery): self
    {
        return $this->where_exists($subquery, true);
    }

    /**
     * Sıralama ekler
     *
     * @param string $column Sütun adı, select takma adı veya aggregate
     * @param string $direction Sıralama yönü (ASC/DESC)
     */
    public function order_by(string $column, string $direction = 'ASC'): self
    {
        $this->order_by[] = $this->compile_column($column) . ' ' . $this->normalize_direction($direction);

        return $this;
    }

    /**
     * Doğrulanmadan eklenen ORDER BY ifadesi (ör. `FIELD(status, 'a', 'b')`). Kullanıcı girdisi içermemelidir.
     */
    public function order_by_raw(string $expression): self
    {
        $this->order_by[] = $expression;

        return $this;
    }

    /**
     * GROUP BY ekler
     */
    public function group_by(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->group_by[] = $this->compile_column($column);
        }

        return $this;
    }

    /**
     * Doğrulanmadan eklenen GROUP BY ifadesi. Kullanıcı girdisi içermemelidir.
     */
    public function group_by_raw(string $expression): self
    {
        $this->group_by[] = $expression;

        return $this;
    }

    /**
     * HAVING koşulu ekler (GROUP BY ile birlikte kullanılır)
     *
     * @param string $column Sütun adı veya aggregate (örn: COUNT(*))
     * @param mixed $value Değer veya subquery builder
     */
    public function having(string $column, string $operator, $value): self
    {
        $this->having[] = $this->condition_part($column, $operator, $value);

        return $this;
    }

    /**
     * Doğrulanmadan eklenen HAVING koşulu. Değerler için `:ad` placeholder'ı ve $bindings kullanın.
     *
     * @param array<string, mixed> $bindings ['ad' => değer]
     */
    public function having_raw(string $condition, array $bindings = []): self
    {
        $this->having[] = ['(', $this->raw_part($condition, $bindings), ')'];

        return $this;
    }

    /**
     * Limit belirler
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit değeri negatif olamaz.');
        }
        $this->limit = $limit;

        return $this;
    }

    /**
     * Offset belirler (LIMIT ile birlikte kullanılır)
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset değeri negatif olamaz.');
        }
        $this->offset = $offset;

        return $this;
    }

    /**
     * JOIN ekler
     *
     * @param string|QueryBuilder $table Katılım yapılacak tablo veya subquery builder
     * @param string|callable $first Birinci sütun veya closure (raw ON koşulu döndürür)
     * @param string|null $operator Operatör (closure kullanılıyorsa null)
     * @param string|null $second İkinci sütun (closure kullanılıyorsa null)
     * @param string $type Join tipi (INNER, LEFT, RIGHT, FULL, CROSS, LEFT OUTER, RIGHT OUTER, FULL OUTER)
     * @param string|null $alias Alias adı (subquery kullanılıyorsa zorunlu)
     */
    public function join($table, $first, ?string $operator = null, ?string $second = null, string $type = 'INNER', ?string $alias = null): self
    {
        if ($table instanceof QueryBuilder) {
            if ($alias === null) {
                throw new \InvalidArgumentException('JOIN subquery için alias zorunludur.');
            }

            $table_part = $this->subquery_part($table, $alias);
        } else {
            $table_part = [$this->compile_reference($table)];
        }

        $this->validate_join_type($type);
        $this->assert_join_supported($type);

        if (! is_string($first) && is_callable($first)) {
            $condition = call_user_func($first, $this);
            if (! is_string($condition)) {
                throw new \InvalidArgumentException('JOIN closure bir string döndürmelidir.');
            }
            $this->joins[] = ['type' => $type, 'table' => $table_part, 'condition' => $condition];

            return $this;
        }

        if ($operator === null || $second === null) {
            throw new \InvalidArgumentException('JOIN için operator ve second parametreleri gereklidir (closure kullanmıyorsanız).');
        }

        $this->validate_operator($operator);

        $this->joins[] = [
            'type' => $type,
            'table' => $table_part,
            'condition' => $this->compile_reference($first) . " $operator " . $this->compile_reference($second),
        ];

        return $this;
    }

    public function left_join(string $table, string|callable $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function right_join(string $table, string|callable $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function full_join(string $table, string|callable $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'FULL');
    }

    public function inner_join(string $table, string|callable $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'INNER');
    }

    /**
     * CROSS JOIN ekler
     */
    public function cross_join(string $table): self
    {
        $this->joins[] = [
            'type' => 'CROSS',
            'table' => [$this->compile_reference($table)],
            'condition' => null,
        ];

        return $this;
    }

    /**
     * UNION ekler (iki sorguyu birleştirir)
     *
     * @param bool $all UNION ALL kullanılacak mı?
     */
    public function union(QueryBuilder $builder, bool $all = false): self
    {
        $this->unions[] = [
            'builder' => $builder,
            'all' => $all,
        ];

        return $this;
    }

    /**
     * Sorguyu çalıştırır ve tüm sonuçları döndürür
     */
    public function get(): array
    {
        [$sql, $params] = $this->compile();

        return $this->db->get_results($sql, $params);
    }

    /**
     * Sorguyu çalıştırır ve ilk sonucu döndürür (builder'ın kendi LIMIT'ini değiştirmez)
     */
    public function first(): ?object
    {
        $single = clone $this;
        $single->limit = 1;
        [$sql, $params] = $single->compile();

        return $this->db->get_row($sql, $params);
    }

    /**
     * Satır sayısını döndürür. GROUP BY, UNION veya LIMIT/OFFSET varsa sorgu alt sorgu olarak sayılır.
     */
    public function count(string $column = '*'): int
    {
        $aggregate = 'COUNT(' . ($column === '*' ? '*' : $this->compile_reference($column)) . ') AS '
            . $this->db->quote_identifier('aggregate');

        if ($this->group_by !== [] || $this->unions !== [] || $this->limit !== null || $this->offset > 0) {
            $outer = new self($this->db);
            $outer->from(clone $this, 'nsql_count');
            $outer->columns = [[$aggregate]];
            $row = $outer->first();
        } else {
            $query = clone $this;
            $query->columns = [[$aggregate]];
            $query->order_by = [];
            $row = $query->first();
        }

        return (int) ($row->aggregate ?? 0);
    }

    /**
     * Koşullara uyan en az bir satır var mı?
     */
    public function exists(): bool
    {
        $query = clone $this;
        $query->columns = [['1 AS ' . $this->db->quote_identifier('nsql_exists')]];
        $query->order_by = [];

        return $query->first() !== null;
    }

    /**
     * Tek kolonun değerlerini liste olarak döndürür; $key verilirse o kolon dizi anahtarı olur.
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $query = clone $this;
        $key === null ? $query->select($column) : $query->select($column, $key);

        $value_property = $this->result_property($column);
        $key_property = $key === null ? null : $this->result_property($key);

        $result = [];
        foreach ($query->get() as $row) {
            if ($key_property === null) {
                $result[] = $row->{$value_property} ?? null;
            } else {
                $result[(string) ($row->{$key_property} ?? '')] = $row->{$value_property} ?? null;
            }
        }

        return $result;
    }

    /**
     * İlk satırın tek kolon değerini döndürür (satır yoksa null).
     */
    public function value(string $column): mixed
    {
        $query = clone $this;
        $row = $query->select($column)->first();

        return $row->{$this->result_property($column)} ?? null;
    }

    /**
     * Sayfalı sonuç.
     *
     * @return array{data: array<int, object>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(int $per_page = 15, int $page = 1): array
    {
        if ($per_page < 1 || $page < 1) {
            throw new \InvalidArgumentException('per_page ve page en az 1 olmalıdır.');
        }

        $total = $this->count();
        $data = $total === 0 ? [] : (clone $this)->limit($per_page)->offset(($page - 1) * $per_page)->get();

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $per_page,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $per_page)),
        ];
    }

    /**
     * Tek satır ekler, eklenen kaydın ID'sini döndürür. Hata → QueryException.
     *
     * PostgreSQL'de id `INSERT ... RETURNING *` ile `$primary_key` kolonundan okunur (trigger'ların
     * ilerlettiği başka sequence'ler sonucu etkilemez); kolon yoksa lastval()'e düşülür.
     *
     * @param array<string, mixed> $data kolon => değer (değer QueryBuilder::raw() olabilir)
     */
    public function insert(array $data, string $primary_key = 'id'): int|string
    {
        $this->assert_writable('insert');
        [$sql, $params] = $this->compile_insert([$data]);

        if ($this->db->get_driver_name() === 'pgsql') {
            return $this->db->insert_returning($sql . ' RETURNING *', $params, $primary_key);
        }

        $this->db->statement($sql, $params);

        return $this->db->insert_id();
    }

    /**
     * Çok satır ekler, eklenen satır sayısını döndürür. Placeholder sınırını aşan veri parçalara
     * bölünür ve tek transaction içinde eklenir.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insert_many(array $rows): int
    {
        $this->assert_writable('insert_many');
        if ($rows === []) {
            return 0;
        }

        $first = reset($rows);
        $per_chunk = max(1, intdiv($this->db->max_bound_params(), max(1, is_array($first) ? count($first) : 1)));
        $chunks = array_chunk($rows, $per_chunk);

        $run = function () use ($chunks): int {
            $total = 0;
            foreach ($chunks as $chunk) {
                [$sql, $params] = $this->compile_insert($chunk);
                $total += $this->db->statement($sql, $params);
            }

            return $total;
        };

        return count($chunks) > 1 ? $this->db->transaction($run) : $run();
    }

    /**
     * WHERE koşullarına uyan satırları günceller, etkilenen satır sayısını döndürür.
     * Koşulsuz güncelleme yalnızca $allow_without_where=true ile yapılır.
     *
     * @param array<string, mixed> $data kolon => değer (değer QueryBuilder::raw() olabilir)
     */
    public function update(array $data, bool $allow_without_where = false): int
    {
        $this->assert_writable('update', $allow_without_where);
        if ($data === [] || array_is_list($data)) {
            throw new \InvalidArgumentException('update() kolon => değer dizisi bekler.');
        }

        $counter = 0;
        $params = [];
        $sets = [];
        foreach ($data as $column => $value) {
            $sets[] = $this->compile_reference((string) $column) . ' = '
                . $this->render([$this->value_part((string) $column, $value)], $counter, $params);
        }

        $sql = 'UPDATE ' . $this->write_table() . ' SET ' . implode(', ', $sets) . $this->compile_where($counter, $params);

        return $this->db->statement($sql, $params);
    }

    /**
     * WHERE koşullarına uyan satırları siler, silinen satır sayısını döndürür.
     * Koşulsuz silme yalnızca $allow_without_where=true ile yapılır.
     */
    public function delete(bool $allow_without_where = false): int
    {
        $this->assert_writable('delete', $allow_without_where);

        $counter = 0;
        $params = [];
        $sql = 'DELETE FROM ' . $this->write_table() . $this->compile_where($counter, $params);

        return $this->db->statement($sql, $params);
    }

    /**
     * Ekle; benzersiz anahtar çakışırsa $update_columns kolonlarını güncelle.
     * MySQL: ON DUPLICATE KEY UPDATE; PostgreSQL/SQLite: ON CONFLICT ($unique_by) DO UPDATE ($unique_by zorunlu).
     *
     * @param array<string, mixed>|list<array<string, mixed>> $rows Tek satır veya satır listesi
     * @param array<int|string, mixed> $update_columns ['kolon', …] (yeni değerle) veya ['kolon' => değer]
     * @param list<string> $unique_by Çakışma hedefi kolonları
     * @return int Sürücünün bildirdiği etkilenen satır sayısı (MySQL: ekleme 1, güncelleme 2)
     */
    public function upsert(array $rows, array $update_columns, array $unique_by = []): int
    {
        $this->assert_writable('upsert');
        $rows = array_is_list($rows) ? $rows : [$rows];
        if ($update_columns === []) {
            throw new \InvalidArgumentException('upsert() için en az bir güncellenecek kolon gerekli.');
        }

        $driver = $this->db->get_driver_name();
        if ($driver !== 'mysql' && $unique_by === []) {
            throw new \InvalidArgumentException("upsert(): {$driver} için \$unique_by (çakışma kolonları) zorunludur.");
        }

        $counter = 0;
        $params = [];
        [$sql] = $this->compile_insert($rows, $counter, $params);

        $sets = [];
        foreach ($update_columns as $key => $value) {
            if (is_int($key)) {
                $column = $this->compile_reference((string) $value);
                $sets[] = $column . ' = ' . ($driver === 'mysql' ? "VALUES({$column})" : "EXCLUDED.{$column}");
            } else {
                $sets[] = $this->compile_reference($key) . ' = '
                    . $this->render([$this->value_part($key, $value)], $counter, $params);
            }
        }

        if ($driver === 'mysql') {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        } else {
            $targets = implode(', ', array_map(fn ($c) => $this->compile_reference($c), $unique_by));
            $sql .= " ON CONFLICT ({$targets}) DO UPDATE SET " . implode(', ', $sets);
        }

        return $this->db->statement($sql, $params);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array{value: mixed, type: int}> $params
     * @return array{0: string, 1: array<string, array{value: mixed, type: int}>}
     */
    private function compile_insert(array $rows, int &$counter = 0, array &$params = []): array
    {

        $first = reset($rows);
        if (! is_array($first) || $first === [] || array_is_list($first)) {
            throw new \InvalidArgumentException('insert: her satır kolon => değer dizisi olmalıdır.');
        }

        $columns = array_map('strval', array_keys($first));
        $quoted = array_map(fn ($c) => $this->compile_reference($c), $columns);

        $values = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || count($row) !== count($columns)) {
                throw new \InvalidArgumentException("insert: {$index}. satırın kolonları ilk satırla aynı olmalıdır.");
            }

            $placeholders = [];
            foreach ($columns as $column) {
                if (! array_key_exists($column, $row)) {
                    throw new \InvalidArgumentException("insert: {$index}. satırda '{$column}' kolonu yok.");
                }
                $placeholders[] = $this->render([$this->value_part($column, $row[$column])], $counter, $params);
            }
            $values[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = 'INSERT INTO ' . $this->write_table() . ' (' . implode(', ', $quoted) . ') VALUES ' . implode(', ', $values);

        return [$sql, $params];
    }

    /**
     * Yazma işlemleri yalnızca düz tablo ve WHERE ile çalışır.
     */
    private function assert_writable(string $operation, bool $allow_without_where = true): void
    {
        if ($this->table_name === null) {
            throw new \LogicException("{$operation}(): table() ile düz bir tablo adı belirtilmeli.");
        }

        if (
            $this->joins !== [] || $this->unions !== [] || $this->group_by !== [] || $this->having !== []
            || $this->order_by !== [] || $this->limit !== null || $this->offset > 0
        ) {
            throw new \LogicException("{$operation}(): JOIN, UNION, GROUP BY, HAVING, ORDER BY, LIMIT ve OFFSET desteklenmez.");
        }

        if (in_array($operation, ['update', 'delete'], true) && ! $allow_without_where && $this->where === []) {
            throw new \LogicException(
                "{$operation}(): WHERE koşulu yok; tüm tabloyu etkilemek için {$operation}(…, true) kullanın."
            );
        }
    }

    private function write_table(): string
    {
        $table = $this->table[0] ?? null;
        if ($this->table_name === null || ! is_string($table)) {
            throw new \LogicException('Yazma işlemleri için table() ile düz bir tablo adı belirtilmeli.');
        }

        return $table;
    }

    /**
     * Sonuç nesnesindeki özellik adı: `x AS takma_ad` → takma_ad, `tablo.kolon` → kolon.
     */
    private function result_property(string $column): string
    {
        if (preg_match('/\s+AS\s+(\S+)$/i', trim($column), $m)) {
            return $this->unquote($m[1]);
        }

        $parts = explode('.', trim($column));

        return $this->unquote((string) end($parts));
    }

    /**
     * SQL sorgusunu döndürür
     */
    public function get_query(): string
    {
        return $this->compile()[0];
    }

    /**
     * get_query() ile aynı placeholder adlarını kullanan parametreleri döndürür
     *
     * @return array<string, array{value: mixed, type: int}>
     */
    public function get_params(): array
    {
        return $this->compile()[1];
    }

    /**
     * SQL ve parametreleri birlikte üretir. Yan etkisizdir; art arda çağrılabilir.
     *
     * @return array{0: string, 1: array<string, array{value: mixed, type: int}>}
     */
    public function compile(): array
    {
        $counter = 0;
        $params = [];
        $sql = $this->compile_into($counter, $params);

        return [$sql, $params];
    }

    /**
     * @param array<string, array{value: mixed, type: int}> $params
     */
    private function compile_into(int &$counter, array &$params): string
    {
        if ($this->table === []) {
            throw new \LogicException('Sorgu için tablo belirtilmedi (table() veya from()).');
        }

        $query = 'SELECT ' . $this->render_list($this->columns, ', ', $counter, $params)
            . ' FROM ' . $this->render($this->table, $counter, $params);

        foreach ($this->joins as $join) {
            $join_type = strtoupper($join['type']);
            $table = $this->render($join['table'], $counter, $params);
            $query .= $join_type === 'CROSS'
                ? " CROSS JOIN {$table}"
                : " {$join_type} JOIN {$table} ON {$join['condition']}";
        }

        $query .= $this->compile_where($counter, $params);

        if ($this->group_by !== []) {
            $query .= ' GROUP BY ' . implode(', ', $this->group_by);
        }

        if ($this->having !== []) {
            $query .= ' HAVING ' . $this->render_list($this->having, ' AND ', $counter, $params);
        }

        // UNION'lar ORDER BY ve LIMIT'ten önce. SQLite bileşik SELECT'te parantezli alt sorguyu
        // kabul etmez; alt sorgu türetilmiş tabloya sarılır, kendi ORDER BY / LIMIT'i korunur (#97).
        $sqlite = $this->unions !== [] && $this->db->get_driver_name() === 'sqlite';
        foreach ($this->unions as $index => $union) {
            $union_type = $union['all'] ? 'UNION ALL' : 'UNION';
            $compiled = $union['builder']->compile_into($counter, $params);
            $query .= $sqlite
                ? " {$union_type} SELECT * FROM ({$compiled}) AS " . $this->db->quote_identifier('nsql_union_' . $index)
                : " {$union_type} ({$compiled})";
        }

        if ($this->order_by !== []) {
            $query .= ' ORDER BY ' . implode(', ', $this->order_by);
        }

        if ($this->limit !== null) {
            $query .= ' LIMIT ' . $this->bind($this->limit, \PDO::PARAM_INT, $counter, $params);
        } elseif ($this->offset > 0) {
            // MySQL ve SQLite OFFSET için LIMIT ister; "sınırsız" değeri sürücüye göre.
            $query .= match ($this->db->get_driver_name()) {
                'mysql' => ' LIMIT 18446744073709551615',
                'sqlite' => ' LIMIT -1',
                default => '',
            };
        }

        if ($this->offset > 0) {
            $query .= ' OFFSET ' . $this->bind($this->offset, \PDO::PARAM_INT, $counter, $params);
        }

        return $query;
    }

    /**
     * @param array<string, array{value: mixed, type: int}> $params
     */
    private function compile_where(int &$counter, array &$params): string
    {
        if ($this->where === []) {
            return '';
        }

        $sql = '';
        foreach ($this->where as $i => $part) {
            if ($i > 0) {
                $sql .= ' ' . $this->where_bools[$i] . ' ';
            }
            $sql .= $this->render($part, $counter, $params);
        }

        return ' WHERE ' . $sql;
    }

    /**
     * @param list<list<string|array>> $parts
     * @param array<string, array{value: mixed, type: int}> $params
     */
    private function render_list(array $parts, string $glue, int &$counter, array &$params): string
    {
        $rendered = [];
        foreach ($parts as $part) {
            $rendered[] = $this->render($part, $counter, $params);
        }

        return implode($glue, $rendered);
    }

    /**
     * Sorgu parçasını SQL'e çevirir; değer, subquery ve raw binding'lere placeholder atar.
     *
     * @param list<string|array> $part
     * @param array<string, array{value: mixed, type: int}> $params
     */
    private function render(array $part, int &$counter, array &$params): string
    {
        $sql = '';

        foreach ($part as $piece) {
            if (is_string($piece)) {
                $sql .= $piece;
                continue;
            }

            $sql .= match ($piece['kind']) {
                'value' => $this->bind($piece['value'], $piece['type'], $counter, $params),
                'subquery' => '(' . $piece['builder']->compile_into($counter, $params) . ')',
                'raw' => $this->render_raw($piece['sql'], $piece['bindings'], $counter, $params),
                default => throw new \LogicException('Bilinmeyen sorgu parçası'),
            };
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $bindings
     * @param array<string, array{value: mixed, type: int}> $params
     */
    private function render_raw(string $sql, array $bindings, int &$counter, array &$params): string
    {
        foreach ($bindings as $name => $value) {
            $placeholder = $this->bind($value, $this->get_param_type($value), $counter, $params);
            $sql = preg_replace('/:' . preg_quote($name, '/') . '\b/', $placeholder, $sql) ?? $sql;
        }

        return $sql;
    }

    /**
     * @param array<string, array{value: mixed, type: int}> $params
     */
    private function bind(mixed $value, int $type, int &$counter, array &$params): string
    {
        $placeholder = self::PARAM_PREFIX . $counter++;
        $params[$placeholder] = ['value' => $value, 'type' => $type];

        return $placeholder;
    }

    /**
     * @return list<string|array>
     */
    private function condition_part(string $column, string $operator, mixed $value): array
    {
        $quoted_column = $this->compile_column($column);
        $this->validate_operator($operator);
        $op = strtoupper(trim($operator));

        if ($value instanceof QueryBuilder) {
            return ["$quoted_column $operator ", ...$this->subquery_part($value)];
        }

        if ($value instanceof RawExpression) {
            return ["$quoted_column $operator ", $this->raw_part($value->sql, $value->bindings)];
        }

        if ($op === 'IN' || $op === 'NOT IN') {
            if (! is_array($value)) {
                throw new \InvalidArgumentException("$op operatörü dizi veya subquery bekler: {$column}");
            }

            return $this->in_part($quoted_column, $value, $op === 'NOT IN');
        }

        if ($value === null) {
            return match ($op) {
                '=', 'IS' => ["$quoted_column IS NULL"],
                '!=', '<>', 'IS NOT' => ["$quoted_column IS NOT NULL"],
                default => throw new \InvalidArgumentException("NULL değeri '$operator' operatörüyle kullanılamaz: {$column}"),
            };
        }

        if ($op === 'IS' || $op === 'IS NOT') {
            throw new \InvalidArgumentException("$op operatörü yalnızca NULL ile kullanılabilir: {$column}");
        }

        if (is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException("'$operator' operatörü için skaler değer gerekli: {$column}");
        }

        return ["$quoted_column $operator ", $this->value_part($column, $value)];
    }

    /**
     * @param array<int|string, mixed> $values
     * @return list<string|array>
     */
    private function in_part(string $quoted_column, array $values, bool $not): array
    {
        if ($values === []) {
            return [$not ? '1 = 1' : '1 = 0'];
        }

        $part = [$quoted_column . ($not ? ' NOT IN (' : ' IN (')];
        $first = true;
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException('IN listesi yalnızca skaler değer içerebilir.');
            }
            if (! $first) {
                $part[] = ', ';
            }
            $part[] = ['kind' => 'value', 'value' => $value, 'type' => $this->get_param_type($value)];
            $first = false;
        }
        $part[] = ')';

        return $part;
    }

    /**
     * @return list<string|array>
     */
    private function subquery_part(QueryBuilder $builder, ?string $alias = null): array
    {
        $part = [['kind' => 'subquery', 'builder' => $builder]];

        if ($alias !== null) {
            $part[] = ' AS ' . $this->db->quote_identifier($alias);
        }

        return $part;
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function raw_part(string $sql, array $bindings): array
    {
        $normalized = [];
        foreach ($bindings as $name => $value) {
            if (! is_string($name) || ! preg_match('/^:?([A-Za-z_][A-Za-z0-9_]*)$/', $name, $m)) {
                throw new \InvalidArgumentException('Raw binding adları isimli olmalıdır (ör. [\'status\' => 1]).');
            }
            $normalized[$m[1]] = $value;
        }

        return ['kind' => 'raw', 'sql' => $sql, 'bindings' => $normalized];
    }

    private function value_part(string $column, mixed $value): array
    {
        if ($value instanceof RawExpression) {
            return $this->raw_part($value->sql, $value->bindings);
        }

        $type = $this->get_param_type($value);

        if ($type === \PDO::PARAM_STR && ! $this->allow_empty_string && trim((string) $value) === '') {
            throw new \InvalidArgumentException("Boş string değeri kullanılamaz: {$column}");
        }

        return ['kind' => 'value', 'value' => $value, 'type' => $type];
    }

    /**
     * Kolon ifadesini doğrular ve quote edilmiş SQL'e çevirir.
     *
     * @param bool $allow_alias `ifade AS takma_ad` (yalnızca select)
     * @throws \InvalidArgumentException
     */
    private function compile_column(string $column, bool $allow_alias = false): string
    {
        $column = trim($column);

        if ($column === '') {
            throw new \InvalidArgumentException('Sütun adı boş olamaz');
        }

        if (preg_match('/^(.+?)\s+AS\s+(\S+)$/is', $column, $m)) {
            if (! $allow_alias) {
                throw new \InvalidArgumentException('Geçersiz sütun ifadesi: ' . $this->excerpt($column));
            }

            return $this->compile_expression(trim($m[1]), true)
                . ' AS ' . $this->db->quote_identifier($this->unquote($m[2]));
        }

        return $this->compile_expression($column, $allow_alias);
    }

    /**
     * @param bool $allow_literal Tamsayı literal (ör. `SELECT 1`)
     */
    private function compile_expression(string $expression, bool $allow_literal): string
    {
        if ($expression === '*') {
            return '*';
        }

        if ($allow_literal && preg_match('/^\d+$/', $expression)) {
            return $expression;
        }

        if (preg_match('/^([`"]?)([A-Za-z0-9_]+)\1\.\*$/', $expression, $m)) {
            return $this->db->quote_identifier($m[2]) . '.*';
        }

        if (preg_match('/^(' . self::AGGREGATES . ')\s*\(\s*(DISTINCT\s+)?(.+?)\s*\)$/is', $expression, $m)) {
            $function = strtoupper($m[1]);
            $distinct = $m[2] !== '' ? 'DISTINCT ' : '';

            if ($m[3] === '*') {
                if ($function !== 'COUNT' || $distinct !== '') {
                    throw new \InvalidArgumentException('Geçersiz sütun ifadesi: ' . $this->excerpt($expression));
                }

                return 'COUNT(*)';
            }

            return $function . '(' . $distinct . $this->compile_reference($m[3]) . ')';
        }

        return $this->compile_reference($expression);
    }

    /**
     * `ad`, `tablo.ad` (isteğe bağlı backtick/çift tırnaklı parçalarla) doğrular ve quote eder.
     */
    private function compile_reference(string $reference): string
    {
        $parts = explode('.', trim($reference));

        if (count($parts) > 2) {
            throw new \InvalidArgumentException('Geçersiz tanımlayıcı: ' . $this->excerpt($reference));
        }

        try {
            return $this->db->quote_identifier(implode('.', array_map(fn ($part) => $this->unquote($part), $parts)));
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Geçersiz tanımlayıcı: ' . $this->excerpt($reference), 0, $e);
        }
    }

    /**
     * Tek parça tanımlayıcıdan çevreleyen backtick/çift tırnağı kaldırır.
     */
    private function unquote(string $part): string
    {
        if (preg_match('/^([`"])(.*)\1$/s', $part, $m)) {
            return $m[2];
        }

        return $part;
    }

    private function excerpt(string $value): string
    {
        return substr($value, 0, 64);
    }

    private function normalize_direction(string $direction): string
    {
        $direction = strtoupper(trim($direction));

        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Geçersiz sıralama yönü. Sadece ASC veya DESC kullanılabilir.');
        }

        return $direction;
    }

    /**
     * Operatörü doğrular
     */
    private function validate_operator(string $operator): void
    {
        $valid_operators = ['=', '>', '<', '>=', '<=', '<>', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];
        if (! in_array(strtoupper($operator), $valid_operators, true)) {
            throw new \InvalidArgumentException("Geçersiz operatör: $operator");
        }
    }

    /**
     * Join tipini doğrular
     */
    private function validate_join_type(string $type): void
    {
        $valid_types = ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'LEFT OUTER', 'RIGHT OUTER', 'FULL OUTER'];
        if (! in_array(strtoupper($type), $valid_types, true)) {
            throw new \InvalidArgumentException("Geçersiz JOIN tipi: $type. Geçerli tipler: " . implode(', ', $valid_types));
        }
    }

    /**
     * FULL JOIN'i desteklemeyen sürücülerde anlaşılmaz SQL hatası yerine açıklayıcı hata (#101).
     * MySQL/MariaDB hiç desteklemez; SQLite 3.39.0 ile destekler.
     */
    private function assert_join_supported(string $type): void
    {
        if (! str_starts_with(strtoupper(trim($type)), 'FULL')) {
            return;
        }

        $driver = $this->db->get_driver_name();
        $supported = match ($driver) {
            'mysql' => false,
            'sqlite' => version_compare((string) $this->db->get_pdo()?->getAttribute(\PDO::ATTR_SERVER_VERSION), '3.39.0', '>='),
            default => true,
        };

        if (! $supported) {
            throw new \LogicException(
                "FULL JOIN {$driver} sürücüsünde desteklenmiyor. LEFT JOIN ve RIGHT JOIN sonuçlarını union() ile birleştirin."
            );
        }
    }

    /**
     * Parametre değerine uygun PDO tipini belirler
     */
    private function get_param_type(mixed $value): int
    {
        if (is_int($value)) {
            return \PDO::PARAM_INT;
        }
        if (is_bool($value)) {
            return \PDO::PARAM_BOOL;
        }
        if (is_null($value)) {
            return \PDO::PARAM_NULL;
        }

        return \PDO::PARAM_STR;
    }
}
