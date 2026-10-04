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
class query_builder
{
    private const AGGREGATES = 'COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT';
    private const PARAM_PREFIX = ':__p';

    private nsql $db;

    /** @var list<string|array> Sorgu parçası: SQL metni, değer, subquery veya raw */
    private array $table = [];
    /** @var list<list<string|array>> */
    private array $columns = [['*']];
    /** @var list<list<string|array>> */
    private array $where = [];
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
    /** @var list<array{builder: query_builder, all: bool}> */
    private array $unions = [];
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
        $this->table = [$this->compile_reference($table)];

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

            $this->table = $this->subquery_part($table, $alias);

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
            $this->columns[] = $column instanceof query_builder
                ? $this->subquery_part($column)
                : [$this->compile_column((string) $column, true)];
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
     * WHERE koşulu ekler
     *
     * @param string $column Sütun adı
     * @param string $operator Operatör (=, >, <, etc.)
     * @param mixed $value Değer veya subquery builder
     */
    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = $this->condition_part($column, $operator, $value);

        return $this;
    }

    /**
     * Doğrulanmadan eklenen WHERE koşulu. Değerler için `:ad` placeholder'ı ve $bindings kullanın.
     *
     * @param array<string, mixed> $bindings ['ad' => değer]
     */
    public function where_raw(string $condition, array $bindings = []): self
    {
        $this->where[] = ['(', $this->raw_part($condition, $bindings), ')'];

        return $this;
    }

    /**
     * WHERE IN subquery ekler
     */
    public function where_in_subquery(string $column, query_builder $subquery, bool $not = false): self
    {
        $operator = $not ? 'NOT IN' : 'IN';
        $this->where[] = [$this->compile_column($column) . " $operator ", ...$this->subquery_part($subquery)];

        return $this;
    }

    /**
     * WHERE EXISTS subquery ekler
     */
    public function where_exists(query_builder $subquery, bool $not = false): self
    {
        $operator = $not ? 'NOT EXISTS' : 'EXISTS';
        $this->where[] = ["$operator ", ...$this->subquery_part($subquery)];

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

            $table_part = $this->subquery_part($table, $alias);
        } else {
            $table_part = [$this->compile_reference($table)];
        }

        $this->validate_join_type($type);

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

        if ($this->where !== []) {
            $query .= ' WHERE ' . $this->render_list($this->where, ' AND ', $counter, $params);
        }

        if ($this->group_by !== []) {
            $query .= ' GROUP BY ' . implode(', ', $this->group_by);
        }

        if ($this->having !== []) {
            $query .= ' HAVING ' . $this->render_list($this->having, ' AND ', $counter, $params);
        }

        // UNION'lar ORDER BY ve LIMIT'ten önce
        foreach ($this->unions as $union) {
            $union_type = $union['all'] ? 'UNION ALL' : 'UNION';
            $query .= " {$union_type} (" . $union['builder']->compile_into($counter, $params) . ')';
        }

        if ($this->order_by !== []) {
            $query .= ' ORDER BY ' . implode(', ', $this->order_by);
        }

        if ($this->limit !== null) {
            $query .= ' LIMIT ' . $this->bind($this->limit, \PDO::PARAM_INT, $counter, $params);

            if ($this->offset > 0) {
                $query .= ' OFFSET ' . $this->bind($this->offset, \PDO::PARAM_INT, $counter, $params);
            }
        }

        return $query;
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

        if ($value instanceof query_builder) {
            return ["$quoted_column $operator ", ...$this->subquery_part($value)];
        }

        return ["$quoted_column $operator ", $this->value_part($column, $value)];
    }

    /**
     * @return list<string|array>
     */
    private function subquery_part(query_builder $builder, ?string $alias = null): array
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
