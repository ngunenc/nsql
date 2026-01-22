<?php

namespace nsql\database;

class query_builder
{
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
     * @param string|query_builder $table Tablo adı veya subquery builder
     * @param string|null $alias Alias adı (subquery kullanılıyorsa zorunlu)
     * @return self
     */
    public function from($table, ?string $alias = null): self
    {
        // Subquery desteği
        if ($table instanceof query_builder) {
            if ($alias === null) {
                throw new \InvalidArgumentException('FROM subquery için alias zorunludur.');
            }
            
            $subquery = $table->build_query();
            $subquery_params = $table->get_params();
            
            // Subquery parametrelerini birleştir
            foreach ($subquery_params as $key => $param_data) {
                $unique_key = 'subquery_' . $this->param_counter++ . '_' . $key;
                $this->params[$unique_key] = $param_data;
                $subquery = str_replace($key, $unique_key, $subquery);
            }
            
            $this->table = "($subquery) AS {$alias}";
            return $this;
        }
        
        return $this->table($table);
    }

    /**
     * Seçilecek sütunları belirler
     *
     * @param string|query_builder ...$columns Sütun adları veya subquery builder
     * @return self
     */
    public function select(...$columns): self
    {
        $this->columns = [];
        foreach ($columns as $column) {
            // Subquery desteği
            if ($column instanceof query_builder) {
                $subquery = $column->build_query();
                $subquery_params = $column->get_params();
                
                // Subquery parametrelerini birleştir
                foreach ($subquery_params as $key => $param_data) {
                    $unique_key = 'subquery_' . $this->param_counter++ . '_' . $key;
                    $this->params[$unique_key] = $param_data;
                    $subquery = str_replace($key, $unique_key, $subquery);
                }
                
                $this->columns[] = "($subquery)";
            } else {
                $this->validate_column_name($column);
                $this->columns[] = $column;
            }
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

        // Subquery desteği
        if ($value instanceof query_builder) {
            $subquery = $value->build_query();
            $subquery_params = $value->get_params();
            
            // Subquery parametrelerini birleştir
            foreach ($subquery_params as $key => $param_data) {
                $unique_key = 'subquery_' . $this->param_counter++ . '_' . $key;
                $this->params[$unique_key] = $param_data;
                // Subquery içindeki parametre adlarını güncelle
                $subquery = str_replace($key, $unique_key, $subquery);
            }
            
            $this->where[] = "$column $operator ($subquery)";
            return $this;
        }

        [$param_name, $param_value, $param_type] = $this->prepare_param($column, $value);
        $this->where[] = "$column $operator $param_name";
        $this->params[$param_name] = ['value' => $param_value, 'type' => $param_type];

        return $this;
    }

    /**
     * WHERE IN subquery ekler
     *
     * @param string $column Sütun adı
     * @param query_builder $subquery Subquery builder
     * @param bool $not NOT IN kullanılacak mı?
     * @return self
     */
    public function where_in_subquery(string $column, query_builder $subquery, bool $not = false): self
    {
        $this->validate_column_name($column);
        
        $subquery_sql = $subquery->build_query();
        $subquery_params = $subquery->get_params();
        
        // Subquery parametrelerini birleştir
        foreach ($subquery_params as $key => $param_data) {
            $unique_key = 'subquery_' . $this->param_counter++ . '_' . $key;
            $this->params[$unique_key] = $param_data;
            $subquery_sql = str_replace($key, $unique_key, $subquery_sql);
        }
        
        $operator = $not ? 'NOT IN' : 'IN';
        $this->where[] = "$column $operator ($subquery_sql)";
        
        return $this;
    }

    /**
     * WHERE EXISTS subquery ekler
     *
     * @param query_builder $subquery Subquery builder
     * @param bool $not NOT EXISTS kullanılacak mı?
     * @return self
     */
    public function where_exists(query_builder $subquery, bool $not = false): self
    {
        $subquery_sql = $subquery->build_query();
        $subquery_params = $subquery->get_params();
        
        // Subquery parametrelerini birleştir
        foreach ($subquery_params as $key => $param_data) {
            $unique_key = 'subquery_' . $this->param_counter++ . '_' . $key;
            $this->params[$unique_key] = $param_data;
            $subquery_sql = str_replace($key, $unique_key, $subquery_sql);
        }
        
        $operator = $not ? 'NOT EXISTS' : 'EXISTS';
        $this->where[] = "$operator ($subquery_sql)";
        
        return $this;
    }

    /**
     * WHERE NOT EXISTS subquery ekler (convenience method)
     *
     * @param query_builder $subquery Subquery builder
     * @return self
     */
    public function where_not_exists(query_builder $subquery): self
    {
        return $this->where_exists($subquery, true);
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
     * HAVING koşulu ekler (GROUP BY ile birlikte kullanılır)
     *
     * @param string $column Sütun adı veya aggregate fonksiyon (örn: COUNT(*))
     * @param string $operator Operatör (=, >, <, >=, <=, etc.)
     * @param mixed $value Değer veya subquery builder
     * @return self
     */
    public function having(string $column, string $operator, $value): self
    {
        $this->validate_operator($operator);

        // Subquery desteği
        if ($value instanceof query_builder) {
            $subquery = $value->build_query();
            $subquery_params = $value->get_params();
            
            // Subquery parametrelerini birleştir
            foreach ($subquery_params as $key => $param_data) {
                $unique_key = 'subquery_' . $this->param_counter++ . '_' . $key;
                $this->params[$unique_key] = $param_data;
                $subquery = str_replace($key, $unique_key, $subquery);
            }
            
            $this->having[] = "$column $operator ($subquery)";
            return $this;
        }

        [$param_name, $param_value, $param_type] = $this->prepare_param($column, $value);
        $this->having[] = "$column $operator $param_name";
        $this->params[$param_name] = ['value' => $param_value, 'type' => $param_type];

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
     * @param string|query_builder $table Katılım yapılacak tablo veya subquery builder (alias ile: 'table AS alias' veya 'table alias')
     * @param string|callable $first Birinci sütun veya closure (karmaşık ON condition için)
     * @param string|null $operator Operatör (closure kullanılıyorsa null)
     * @param string|null $second İkinci sütun (closure kullanılıyorsa null)
     * @param string $type Join tipi (INNER, LEFT, RIGHT, FULL, CROSS, LEFT OUTER, RIGHT OUTER, FULL OUTER)
     * @param string|null $alias Alias adı (subquery kullanılıyorsa zorunlu)
     * @return self
     */
    public function join($table, $first, ?string $operator = null, ?string $second = null, string $type = 'INNER', ?string $alias = null): self
    {
        // Subquery desteği
        if ($table instanceof query_builder) {
            if ($alias === null) {
                throw new \InvalidArgumentException('JOIN subquery için alias zorunludur.');
            }
            
            $subquery = $table->build_query();
            $subquery_params = $table->get_params();
            
            // Subquery parametrelerini birleştir
            foreach ($subquery_params as $key => $param_data) {
                $unique_key = 'subquery_' . $this->param_counter++ . '_' . $key;
                $this->params[$unique_key] = $param_data;
                $subquery = str_replace($key, $unique_key, $subquery);
            }
            
            $table = "($subquery) AS {$alias}";
        } else {
            $this->validate_table_name($table);
        }
        
        $this->validate_join_type($type);

        // Closure ile karmaşık ON condition
        if (is_callable($first)) {
            $condition = call_user_func($first, $this);
            if (! is_string($condition)) {
                throw new \InvalidArgumentException('JOIN closure bir string döndürmelidir.');
            }
            $this->joins[] = [
                'type' => $type,
                'table' => $table,
                'condition' => $condition,
            ];

            return $this;
        }

        // Normal ON condition (first operator second)
        if ($operator === null || $second === null) {
            throw new \InvalidArgumentException('JOIN için operator ve second parametreleri gereklidir (closure kullanmıyorsanız).');
        }

        $this->validate_column_name($first);
        $this->validate_column_name($second);
        $this->validate_operator($operator);

        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'condition' => "$first $operator $second",
        ];

        return $this;
    }

    /**
     * LEFT JOIN ekler (convenience method)
     *
     * @param string $table Katılım yapılacak tablo
     * @param string|callable $first Birinci sütun veya closure
     * @param string|null $operator Operatör
     * @param string|null $second İkinci sütun
     * @return self
     */
    public function left_join(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * RIGHT JOIN ekler (convenience method)
     *
     * @param string $table Katılım yapılacak tablo
     * @param string|callable $first Birinci sütun veya closure
     * @param string|null $operator Operatör
     * @param string|null $second İkinci sütun
     * @return self
     */
    public function right_join(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /**
     * FULL JOIN ekler (convenience method)
     *
     * @param string $table Katılım yapılacak tablo
     * @param string|callable $first Birinci sütun veya closure
     * @param string|null $operator Operatör
     * @param string|null $second İkinci sütun
     * @return self
     */
    public function full_join(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'FULL');
    }

    /**
     * INNER JOIN ekler (convenience method)
     *
     * @param string $table Katılım yapılacak tablo
     * @param string|callable $first Birinci sütun veya closure
     * @param string|null $operator Operatör
     * @param string|null $second İkinci sütun
     * @return self
     */
    public function inner_join(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'INNER');
    }

    /**
     * CROSS JOIN ekler
     *
     * @param string $table Katılım yapılacak tablo
     * @return self
     */
    public function cross_join(string $table): self
    {
        $this->validate_table_name($table);

        $this->joins[] = [
            'type' => 'CROSS',
            'table' => $table,
            'condition' => null, // CROSS JOIN'de ON condition yok
        ];

        return $this;
    }

    /**
     * UNION ekler (iki sorguyu birleştirir)
     *
     * @param query_builder $builder Birleştirilecek Query Builder
     * @param bool $all UNION ALL kullanılacak mı? (varsayılan: false, UNION kullanır)
     * @return self
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
     * SQL sorgusunu oluşturur
     */
    private function build_query(): string
    {
        $query = "SELECT " . implode(", ", $this->columns);
        $query .= " FROM {$this->table}";

        if (! empty($this->joins)) {
            $join_clauses = [];
            foreach ($this->joins as $join) {
                $join_type = strtoupper($join['type']);
                $table = $join['table'];
                
                // CROSS JOIN için ON condition yok
                if ($join_type === 'CROSS') {
                    $join_clauses[] = "CROSS JOIN {$table}";
                } else {
                    // OUTER JOIN'ler için OUTER kelimesini ekle
                    if (in_array($join_type, ['LEFT', 'RIGHT', 'FULL']) && strpos($join_type, 'OUTER') === false) {
                        // LEFT, RIGHT, FULL için OUTER eklenebilir ama opsiyonel
                        // SQL standardında LEFT JOIN = LEFT OUTER JOIN
                        $join_clauses[] = "{$join_type} JOIN {$table} ON {$join['condition']}";
                    } else {
                        // FULL OUTER, LEFT OUTER, RIGHT OUTER gibi açık yazımlar
                        $join_clauses[] = "{$join_type} JOIN {$table} ON {$join['condition']}";
                    }
                }
            }
            $query .= " " . implode(" ", $join_clauses);
        }

        if (! empty($this->where)) {
            $query .= " WHERE " . implode(" AND ", $this->where);
        }

        if (! empty($this->group_by)) {
            $query .= " GROUP BY " . implode(", ", $this->group_by);
        }

        if (! empty($this->having)) {
            $query .= " HAVING " . implode(" AND ", $this->having);
        }

        // UNION'ları ekle (ORDER BY ve LIMIT'ten önce)
        if (! empty($this->unions)) {
            foreach ($this->unions as $union) {
                $union_query = $union['builder']->build_query();
                $union_type = $union['all'] ? 'UNION ALL' : 'UNION';
                $query .= " {$union_type} ({$union_query})";
                
                // UNION'daki parametreleri de ekle
                $union_params = $union['builder']->get_params();
                foreach ($union_params as $key => $value) {
                    // Parametre adı çakışmasını önlemek için unique key oluştur
                    $unique_key = 'union_' . $this->param_counter++ . '_' . $key;
                    $this->params[$unique_key] = $value;
                }
            }
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
     * Parametreleri döndürür (UNION için gerekli)
     *
     * @return array
     */
    public function get_params(): array
    {
        return $this->params;
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
     * 
     * Desteklenen formatlar:
     * - Basit sütun: name, id, user_id
     * - Tablo prefix: users.name, test_table.id
     * - Wildcard: *, test_table.*
     * - Aggregate fonksiyonlar: COUNT(*), SUM(price), AVG(column), MAX(column), MIN(column)
     * - Alias: column as alias, COUNT(*) as count, SUM(price) as total
     * - Karmaşık: test_table.*, COUNT(*) as count, SUM(price) as total
     */
    private function validate_column_name(string $column): void
    {
        $column = trim($column);
        
        // Boş string kontrolü
        if ($column === '') {
            throw new \InvalidArgumentException("Sütun adı boş olamaz");
        }

        // Yıldız (*) karakterine izin ver
        if ($column === '*') {
            return;
        }

        // Tablo prefix + wildcard: test_table.*
        if (preg_match('/^[a-zA-Z0-9_]+\.\*$/', $column)) {
            return;
        }

        // Alias içeren ifadeler: column as alias, COUNT(*) as count
        if (preg_match('/\s+as\s+/i', $column)) {
            // Alias'ı ayır ve her iki kısmı da kontrol et
            $parts = preg_split('/\s+as\s+/i', $column, 2);
            if (count($parts) === 2) {
                $this->validate_column_expression(trim($parts[0]));
                $this->validate_column_alias(trim($parts[1]));
                return;
            }
        }

        // Aggregate fonksiyonlar veya parantez içeren ifadeler
        if (preg_match('/\(/', $column)) {
            $this->validate_column_expression($column);
            return;
        }

        // Basit sütun adı veya tablo.sütun formatı
        if (preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $column)) {
            return;
        }

        // Eğer hiçbir pattern eşleşmezse, daha esnek kontrol yap
        // SQL injection'a karşı temel güvenlik: sadece güvenli karakterlere izin ver
        if (preg_match('/^[a-zA-Z0-9_\.\s\(\)\*,\'"]+$/i', $column)) {
            // Tehlikeli SQL keyword'lerini kontrol et
            $dangerous_keywords = ['DROP', 'DELETE', 'TRUNCATE', 'ALTER', 'CREATE', 'INSERT', 'UPDATE', 'EXEC', 'EXECUTE'];
            $column_upper = strtoupper($column);
            foreach ($dangerous_keywords as $keyword) {
                if (strpos($column_upper, $keyword) !== false && 
                    !preg_match('/\b(COUNT|SUM|AVG|MAX|MIN|GROUP_CONCAT)\s*\(/i', $column)) {
                    throw new \InvalidArgumentException("Geçersiz sütun adı: $column (tehlikeli keyword içeriyor)");
                }
            }
            return;
        }

        throw new \InvalidArgumentException("Geçersiz sütun adı: $column");
    }

    /**
     * Sütun ifadesini doğrular (aggregate fonksiyonlar, parantez içeren ifadeler)
     */
    private function validate_column_expression(string $expression): void
    {
        $expression = trim($expression);
        
        // Aggregate fonksiyonlar: COUNT(*), SUM(price), AVG(column), MAX(column), MIN(column)
        if (preg_match('/^(COUNT|SUM|AVG|MAX|MIN|GROUP_CONCAT)\s*\(/i', $expression)) {
            // Parantez eşleşmesini kontrol et
            $open_count = substr_count($expression, '(');
            $close_count = substr_count($expression, ')');
            if ($open_count !== $close_count) {
                throw new \InvalidArgumentException("Geçersiz sütun ifadesi: $expression (parantez eşleşmiyor)");
            }
            
            // İçerik kontrolü: sadece güvenli karakterler
            // COUNT(*) veya COUNT(column) veya COUNT(DISTINCT column)
            if (preg_match('/^(COUNT|SUM|AVG|MAX|MIN|GROUP_CONCAT)\s*\(\s*(DISTINCT\s+)?([a-zA-Z0-9_\.\*]+)\s*\)$/i', $expression)) {
                return;
            }
            
            // Daha karmaşık ifadeler için genel kontrol
            if (preg_match('/^(COUNT|SUM|AVG|MAX|MIN|GROUP_CONCAT)\s*\([^)]+\)$/i', $expression)) {
                return;
            }
        }

        // Subquery pattern: (SELECT ...)
        if (preg_match('/^\(\s*SELECT\s+/i', $expression)) {
            // Subquery'ler zaten ayrı handle ediliyor, buraya gelmemeli
            // Ama yine de güvenlik kontrolü yapalım
            return;
        }

        // Basit parantez içeren ifadeler
        if (preg_match('/^[a-zA-Z0-9_\.\s\(\)\*]+$/', $expression)) {
            return;
        }

        throw new \InvalidArgumentException("Geçersiz sütun ifadesi: $expression");
    }

    /**
     * Alias adını doğrular
     */
    private function validate_column_alias(string $alias): void
    {
        $alias = trim($alias);
        
        // Alias boş olamaz
        if ($alias === '') {
            throw new \InvalidArgumentException("Alias adı boş olamaz");
        }

        // Alias sadece alfanumerik, underscore ve nokta içerebilir
        // Tırnak içinde olabilir: "alias name", 'alias name'
        if (preg_match('/^["\']/', $alias) && preg_match('/["\']$/', $alias)) {
            // Tırnak içindeki alias
            return;
        }

        // Normal alias: sadece alfanumerik ve underscore
        if (preg_match('/^[a-zA-Z0-9_]+$/', $alias)) {
            return;
        }

        throw new \InvalidArgumentException("Geçersiz alias adı: $alias");
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
        $valid_types = [
            'INNER',
            'LEFT',
            'RIGHT',
            'FULL',
            'CROSS',
            'LEFT OUTER',
            'RIGHT OUTER',
            'FULL OUTER',
        ];
        $type_upper = strtoupper($type);
        if (! in_array($type_upper, $valid_types)) {
            throw new \InvalidArgumentException("Geçersiz JOIN tipi: $type. Geçerli tipler: " . implode(', ', $valid_types));
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
