<?php

namespace nsql\database;

class query_builder
{
    private nsql $db;
    private string $table;
    private array $columns = ['*'];
    private array $where = [];
    private array $group_by = [];
    private array $order_by = [];
    private ?int $limit = null;
    private int $offset = 0;
    private array $joins = [];
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
     * @param string $table Tablo adı
     * @return self
     */
    public function table(string $table): self
    {
        $this->validate_table_name($table);
        $this->table = $table;

        return $this;
    }

    /**
     * FROM clause için alias
     *
     * @param string $table Tablo adı
     * @return self
     */
    public function from(string $table): self
    {
        return $this->table($table);
    }

    /**
     * Seçilecek sütunları belirler
     *
     * @param string ...$columns Sütun adları
     * @return self
     */
    public function select(...$columns): self
    {
        $this->columns = $columns;
        foreach ($columns as $column) {
            $this->validate_column_name($column);
        }

        return $this;
    }

    /**
     * WHERE koşulu ekler
     *
     * @param string $column Sütun adı
     * @param string $operator Operatör (=, >, <, etc.)
     * @param mixed $value Değer
     * @return self
     */
    public function where(string $column, string $operator, $value): self
    {
        $this->validate_column_name($column);
        $this->validate_operator($operator);

        [$param_name, $param_value, $param_type] = $this->prepare_param($column, $value);
        $this->where[] = "$column $operator $param_name";
        $this->params[$param_name] = ['value' => $param_value, 'type' => $param_type];

        return $this;
    }

    /**
     * Sıralama ekler
     *
     * @param string $column Sütun adı
     * @param string $direction Sıralama yönü (ASC/DESC)
     * @return self
     */
    public function order_by(string $column, string $direction = 'ASC'): self
    {
        $this->validate_column_name($column);
        $direction = strtoupper($direction);

        if (! in_array($direction, ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException('Geçersiz sıralama yönü. Sadece ASC veya DESC kullanılabilir.');
        }

        $this->order_by[] = "$column $direction";

        return $this;
    }

    /**
     * GROUP BY ekler
     *
     * @param string ...$columns Gruplanacak sütunlar
     * @return self
     */
    public function group_by(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->validate_column_name($column);
            $this->group_by[] = $column;
        }

        return $this;
    }

    /**
     * Limit belirler
     *
     * @param int $limit Limit değeri
     * @return self
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
     * @param string $table Katılım yapılacak tablo
     * @param string $first Birinci sütun
     * @param string $operator Operatör
     * @param string $second İkinci sütun
     * @param string $type Join tipi (INNER, LEFT, RIGHT)
     * @return self
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->validate_table_name($table);
        $this->validate_column_name($first);
        $this->validate_column_name($second);
        $this->validate_join_type($type);

        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'condition' => "$first $operator $second",
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
     * SQL sorgusunu oluşturur
     */
    private function build_query(): string
    {
        $query = "SELECT " . implode(", ", $this->columns);
        $query .= " FROM {$this->table}";

        if (! empty($this->joins)) {
            $join_clauses = [];
            foreach ($this->joins as $join) {
                $join_clauses[] = "{$join['type']} JOIN {$join['table']} ON {$join['condition']}";
            }
            $query .= " " . implode(" ", $join_clauses);
        }

        if (! empty($this->where)) {
            $query .= " WHERE " . implode(" AND ", $this->where);
        }

        if (! empty($this->group_by)) {
            $query .= " GROUP BY " . implode(", ", $this->group_by);
        }

        if (! empty($this->order_by)) {
            $query .= " ORDER BY " . implode(", ", $this->order_by);
        }

        if ($this->limit !== null) {
            $query .= " LIMIT {$this->limit}";

            if ($this->offset > 0) {
                $query .= " OFFSET {$this->offset}";
            }
        }

        return $query;
    }

    /**
     * Tablo adını doğrular
     */
    private function validate_table_name(string $table): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Geçersiz tablo adı: $table");
        }
    }

    /**
     * Sütun adını doğrular
     */
    private function validate_column_name(string $column): void
    {
        // Yıldız (*) karakterine izin ver
        if ($column === '*') {
            return;
        }

        if (! preg_match('/^[a-zA-Z0-9_\.]+$/', $column)) {
            throw new \InvalidArgumentException("Geçersiz sütun adı: $column");
        }
    }

    /**
     * Operatörü doğrular
     */
    private function validate_operator(string $operator): void
    {
        $valid_operators = ['=', '>', '<', '>=', '<=', '<>', 'LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];
        if (! in_array(strtoupper($operator), $valid_operators)) {
            throw new \InvalidArgumentException("Geçersiz operatör: $operator");
        }
    }

    /**
     * Join tipini doğrular
     */
    private function validate_join_type(string $type): void
    {
        $valid_types = ['INNER', 'LEFT', 'RIGHT', 'FULL'];
        if (! in_array(strtoupper($type), $valid_types)) {
            throw new \InvalidArgumentException("Geçersiz JOIN tipi: $type");
        }
    }

    /**
     * Parametre adını normalize eder
     */
    private function normalize_parameter_name(string $name): string
    {
        $name = trim($name);
        $name = str_replace(['.', ' '], '_', $name);

        return strpos($name, ':') === 0 ? $name : ":{$name}";
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

        // String değer kontrolü
        if ($param_type === \PDO::PARAM_STR && ! $this->allow_empty_string && trim((string)$value) === '') {
            throw new \InvalidArgumentException("Boş string değeri kullanılamaz: {$column}");
        }

        return [$param_name, $value, $param_type];
    }
}
