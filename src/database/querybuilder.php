<?php

namespace nsql\database;

class QueryBuilder {
    private string $table;
    private array $selects = ['*'];
    private array $wheres = [];
    private array $orderBy = [];
    private array $params = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $joins = [];
    private array $groupBy = [];
    private array $having = [];
    private nsql $db;

    public function __construct(nsql $db) {
        $this->db = $db;
    }

    public function table(string $table): self {
        $this->table = $table;
        return $this;
    }

    public function select(...$columns): self {
        $this->selects = is_array($columns[0]) ? $columns[0] : $columns;
        return $this;
    }

    public function where(string $column, string $operator = '=', $value = null): self {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $paramName = str_replace('.', '_', $column);
        $this->wheres[] = [$column, $operator, $paramName];
        $this->params[$paramName] = $value;
        
        return $this;
    }

    public function orWhere(string $column, string $operator = '=', $value = null): self {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $paramName = str_replace('.', '_', $column) . '_' . count($this->params);
        $this->wheres[] = ['OR', $column, $operator, $paramName];
        $this->params[$paramName] = $value;
        
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self {
        $this->orderBy[] = [$column, strtoupper($direction)];
        return $this;
    }

    public function limit(int $limit): self {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self {
        $this->offset = $offset;
        return $this;
    }

    public function join(string $table, string $first, string $operator = '=', string $second = null): self {
        $this->joins[] = ['INNER JOIN', $table, $first, $operator, $second ?? $operator];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator = '=', string $second = null): self {
        $this->joins[] = ['LEFT JOIN', $table, $first, $operator, $second ?? $operator];
        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator = '=', string $second = null): self {
        $this->joins[] = ['RIGHT JOIN', $table, $first, $operator, $second ?? $operator];
        return $this;
    }

    public function groupBy(...$columns): self {
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }

    public function having(string $column, string $operator = '=', $value = null): self {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $paramName = 'having_' . str_replace('.', '_', $column);
        $this->having[] = [$column, $operator, $paramName];
        $this->params[$paramName] = $value;
        
        return $this;
    }

    private function buildQuery(): string {
        $query = [];
        $query[] = "SELECT " . implode(', ', $this->selects);
        $query[] = "FROM " . $this->table;

        // JOIN
        if (!empty($this->joins)) {
            foreach ($this->joins as [$type, $table, $first, $operator, $second]) {
                $query[] = "$type $table ON $first $operator $second";
            }
        }

        // WHERE
        if (!empty($this->wheres)) {
            $whereClauses = [];
            foreach ($this->wheres as $where) {
                if ($where[0] === 'OR') {
                    $whereClauses[] = "OR {$where[1]} {$where[2]} :{$where[3]}";
                } else {
                    $whereClauses[] = "{$where[0]} {$where[1]} :{$where[2]}";
                }
            }
            $query[] = "WHERE " . ltrim(implode(' ', $whereClauses), 'OR ');
        }

        // GROUP BY
        if (!empty($this->groupBy)) {
            $query[] = "GROUP BY " . implode(', ', $this->groupBy);
        }

        // HAVING
        if (!empty($this->having)) {
            $havingClauses = [];
            foreach ($this->having as [$column, $operator, $param]) {
                $havingClauses[] = "$column $operator :$param";
            }
            $query[] = "HAVING " . implode(' AND ', $havingClauses);
        }

        // ORDER BY
        if (!empty($this->orderBy)) {
            $orders = array_map(function($order) {
                return "{$order[0]} {$order[1]}";
            }, $this->orderBy);
            $query[] = "ORDER BY " . implode(', ', $orders);
        }

        // LIMIT ve OFFSET
        if ($this->limit !== null) {
            $query[] = "LIMIT " . $this->limit;
            if ($this->offset !== null) {
                $query[] = "OFFSET " . $this->offset;
            }
        }

        return implode(' ', $query);
    }

    public function get(): array {
        return $this->db->get_results($this->buildQuery(), $this->params);
    }

    public function first(): ?object {
        $this->limit(1);
        return $this->db->get_row($this->buildQuery(), $this->params);
    }

    public function count(): int {
        $originalSelects = $this->selects;
        $this->selects = ['COUNT(*) as count'];
        $result = $this->first();
        $this->selects = $originalSelects;
        return $result ? (int)$result->count : 0;
    }

    public function toSql(): string {
        return $this->buildQuery();
    }

    public function getParams(): array {
        return $this->params;
    }
}
