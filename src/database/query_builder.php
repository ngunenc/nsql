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
 */
class query_builder
{
    private const AGGREGATES = 'COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT';

    private nsql $db;
    private string $table;
    private array $columns = ['*'];
    private array $where = [];
    private array $group_by = [];
    private array $having = [];
    private array $order_by = [];
    private ?int $limit = null;
    private int $offset = 0;
    private array $joins = [];
    private array $unions = [];
    private array $params = [];
    private int $param_counter = 0;
    private bool $allow_empty_string = false;

    public function __construct(nsql $db)
    {
        $this->db = $db;
    }

    /**
     * Tabloyu belirler
     *
     * @param string $table Tablo adı (`tablo` veya `şema.tablo`)
     */
    public function table(string $table): self
    {
        $this->table = $this->compile_reference($table);

        return $this;
    }

    /**
     * FROM clause
     *
     * @param string|query_builder $table Tablo adı veya subquery builder
     * @param string|null $alias Alias adı (subquery kullanılıyorsa zorunlu)
     */
    public function from($table, ?string $alias = null): self
    {
        if ($table instanceof query_builder) {
            if ($alias === null) {
                throw new \InvalidArgumentException('FROM subquery için alias zorunludur.');
            }

            $this->table = '(' . $this->merge_subquery($table) . ') AS ' . $this->db->quote_identifier($alias);

            return $this;
        }

        return $this->table($table);
    }

    /**
     * Seçilecek sütunları belirler
     *
     * @param string|query_builder ...$columns Sütunlar (`ifade AS takma_ad` desteklenir) veya subquery builder
     */
    public function select(...$columns): self
    {
        $this->columns = [];
        foreach ($columns as $column) {
            if ($column instanceof query_builder) {
                $this->columns[] = '(' . $this->merge_subquery($column) . ')';
                continue;
            }

            $this->columns[] = $this->compile_column((string) $column, true);
        }

        return $this;
    }

    /**
     * Doğrulanmadan SELECT listesine eklenen ifade. Kullanıcı girdisi içermemelidir.
     */
    public function select_raw(string $expression): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $this->columns[] = $expression;

        return $this;
    }

    /**
     * WHERE koşulu ekler
     *
     * @param string $column Sütun adı
     * @param string $operator Operatör (=, >, <, etc.)
     * @param mixed $value Değer veya subquery builder
     */
    public function where(string $column, string $operator, $value): self
    {
        $quoted_column = $this->compile_column($column);
        $this->validate_operator($operator);

        if ($value instanceof query_builder) {
            $this->where[] = "$quoted_column $operator (" . $this->merge_subquery($value) . ')';

            return $this;
        }

        [$param_name, $param_value, $param_type] = $this->prepare_param($column, $value);
        $this->where[] = "$quoted_column $operator $param_name";
        $this->params[$param_name] = ['value' => $param_value, 'type' => $param_type];

        return $this;
    }

    /**
     * Doğrulanmadan eklenen WHERE koşulu. Değerler için `:ad` placeholder'ı ve $bindings kullanın.
     *
     * @param array<string, mixed> $bindings ['ad' => değer]
     */
    public function where_raw(string $condition, array $bindings = []): self
    {
        $this->where[] = '(' . $condition . ')';
        $this->add_raw_bindings($bindings);

        return $this;
    }

    /**
     * WHERE IN subquery ekler
     */
    public function where_in_subquery(string $column, query_builder $subquery, bool $not = false): self
    {
        $quoted_column = $this->compile_column($column);
        $operator = $not ? 'NOT IN' : 'IN';
        $this->where[] = "$quoted_column $operator (" . $this->merge_subquery($subquery) . ')';

        return $this;
    }

    /**
     * WHERE EXISTS subquery ekler
     */
    public function where_exists(query_builder $subquery, bool $not = false): self
    {
        $operator = $not ? 'NOT EXISTS' : 'EXISTS';
        $this->where[] = "$operator (" . $this->merge_subquery($subquery) . ')';

        return $this;
    }

    /**
     * WHERE NOT EXISTS subquery ekler (convenience method)
     */
    public function where_not_exists(query_builder $subquery): self
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
        $quoted_column = $this->compile_column($column);
        $this->validate_operator($operator);

        if ($value instanceof query_builder) {
            $this->having[] = "$quoted_column $operator (" . $this->merge_subquery($value) . ')';

            return $this;
        }

        [$param_name, $param_value, $param_type] = $this->prepare_param($column, $value);
        $this->having[] = "$quoted_column $operator $param_name";
        $this->params[$param_name] = ['value' => $param_value, 'type' => $param_type];

        return $this;
    }

    /**
     * Doğrulanmadan eklenen HAVING koşulu. Değerler için `:ad` placeholder'ı ve $bindings kullanın.
     *
     * @param array<string, mixed> $bindings ['ad' => değer]
     */
    public function having_raw(string $condition, array $bindings = []): self
    {
        $this->having[] = '(' . $condition . ')';
        $this->add_raw_bindings($bindings);

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
     * JOIN ekler
     *
     * @param string|query_builder $table Katılım yapılacak tablo veya subquery builder
     * @param string|callable $first Birinci sütun veya closure (raw ON koşulu döndürür)
     * @param string|null $operator Operatör (closure kullanılıyorsa null)
     * @param string|null $second İkinci sütun (closure kullanılıyorsa null)
     * @param string $type Join tipi (INNER, LEFT, RIGHT, FULL, CROSS, LEFT OUTER, RIGHT OUTER, FULL OUTER)
     * @param string|null $alias Alias adı (subquery kullanılıyorsa zorunlu)
     */
    public function join($table, $first, ?string $operator = null, ?string $second = null, string $type = 'INNER', ?string $alias = null): self
    {
        if ($table instanceof query_builder) {
            if ($alias === null) {
                throw new \InvalidArgumentException('JOIN subquery için alias zorunludur.');
            }

            $table_sql = '(' . $this->merge_subquery($table) . ') AS ' . $this->db->quote_identifier($alias);
        } else {
            $table_sql = $this->compile_reference($table);
        }

        $this->validate_join_type($type);

        if (! is_string($first) && is_callable($first)) {
            $condition = call_user_func($first, $this);
            if (! is_string($condition)) {
                throw new \InvalidArgumentException('JOIN closure bir string döndürmelidir.');
            }
            $this->joins[] = ['type' => $type, 'table' => $table_sql, 'condition' => $condition];

            return $this;
        }

        if ($operator === null || $second === null) {
            throw new \InvalidArgumentException('JOIN için operator ve second parametreleri gereklidir (closure kullanmıyorsanız).');
        }

        $this->validate_operator($operator);

        $this->joins[] = [
            'type' => $type,
            'table' => $table_sql,
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
            'table' => $this->compile_reference($table),
            'condition' => null,
        ];

        return $this;
    }

    /**
     * UNION ekler (iki sorguyu birleştirir)
     *
     * @param bool $all UNION ALL kullanılacak mı?
     */
    public function union(query_builder $builder, bool $all = false): self
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
        $query = $this->build_query();

        return $this->db->get_results($query, $this->params);
    }

    /**
     * Sorguyu çalıştırır ve ilk sonucu döndürür
     */
    public function first(): ?object
    {
        $this->limit(1);
        $query = $this->build_query();

        return $this->db->get_row($query, $this->params);
    }

    /**
     * SQL sorgusunu döndürür (test için)
     */
    public function get_query(): string
    {
        return $this->build_query();
    }

    /**
     * SQL sorgusunu oluşturur. Tüm parçalar ekleme sırasında doğrulanıp quote edilmiştir.
     */
    private function build_query(): string
    {
        $query = 'SELECT ' . implode(', ', $this->columns) . " FROM {$this->table}";

        foreach ($this->joins as $join) {
            $join_type = strtoupper($join['type']);
            $query .= $join_type === 'CROSS'
                ? " CROSS JOIN {$join['table']}"
                : " {$join_type} JOIN {$join['table']} ON {$join['condition']}";
        }

        if (! empty($this->where)) {
            $query .= ' WHERE ' . implode(' AND ', $this->where);
        }

        if (! empty($this->group_by)) {
            $query .= ' GROUP BY ' . implode(', ', $this->group_by);
        }

        if (! empty($this->having)) {
            $query .= ' HAVING ' . implode(' AND ', $this->having);
        }

        // UNION'ları ekle (ORDER BY ve LIMIT'ten önce)
        foreach ($this->unions as $union) {
            $union_query = $union['builder']->build_query();
            $union_type = $union['all'] ? 'UNION ALL' : 'UNION';
            $query .= " {$union_type} ({$union_query})";

            foreach ($union['builder']->get_params() as $key => $value) {
                $unique_key = 'union_' . $this->param_counter++ . '_' . $key;
                $this->params[$unique_key] = $value;
            }
        }

        if (! empty($this->order_by)) {
            $query .= ' ORDER BY ' . implode(', ', $this->order_by);
        }

        if ($this->limit !== null) {
            // LIMIT ve OFFSET değerlerini parametre olarak bağla (SQL injection koruması)
            $limit_param = $this->normalize_parameter_name('limit_' . $this->param_counter++);
            $this->params[$limit_param] = ['value' => $this->limit, 'type' => \PDO::PARAM_INT];
            $query .= " LIMIT {$limit_param}";

            if ($this->offset > 0) {
                $offset_param = $this->normalize_parameter_name('offset_' . $this->param_counter++);
                $this->params[$offset_param] = ['value' => $this->offset, 'type' => \PDO::PARAM_INT];
                $query .= " OFFSET {$offset_param}";
            }
        }

        return $query;
    }

    /**
     * Parametreleri döndürür (UNION için gerekli)
     */
    public function get_params(): array
    {
        return $this->params;
    }

    /**
     * Subquery SQL'ini döndürür ve parametrelerini bu builder'a taşır.
     */
    private function merge_subquery(query_builder $subquery): string
    {
        $sql = $subquery->build_query();

        foreach ($subquery->get_params() as $key => $param_data) {
            $unique_key = 'subquery_' . $this->param_counter++ . '_' . $key;
            $this->params[$unique_key] = $param_data;
            $sql = str_replace($key, $unique_key, $sql);
        }

        return $sql;
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
     * @param array<string, mixed> $bindings
     */
    private function add_raw_bindings(array $bindings): void
    {
        foreach ($bindings as $name => $value) {
            if (! is_string($name) || ! preg_match('/^:?[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException('Raw binding adları isimli olmalıdır (ör. [\'status\' => 1]).');
            }

            $this->params[$this->normalize_parameter_name($name)] = [
                'value' => $value,
                'type' => $this->get_param_type($value),
            ];
        }
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
     * Parametre adını normalize eder (yalnızca harf, rakam, alt çizgi)
     */
    private function normalize_parameter_name(string $name): string
    {
        $name = ltrim(trim($name), ':');
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? '';

        return ":{$name}";
    }

    /**
     * Parametre değerini doğrular ve uygun PDO tipini belirler
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

    /**
     * Parametreyi hazırlar ve değerini doğrular
     */
    private function prepare_param(string $column, mixed $value): array
    {
        $param_name = $this->normalize_parameter_name($column . '_' . $this->param_counter++);
        $param_type = $this->get_param_type($value);

        if ($param_type === \PDO::PARAM_STR && ! $this->allow_empty_string && trim((string)$value) === '') {
            throw new \InvalidArgumentException("Boş string değeri kullanılamaz: {$column}");
        }

        return [$param_name, $value, $param_type];
    }
}
