<?php

namespace Tests\Integration;

use nsql\database\query_builder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

/**
 * #33: query_builder kolon/tablo doğrulaması ve identifier quoting.
 */
class QueryBuilderIdentifierSecurityTest extends DatabaseTestCase
{
    private function builder(): query_builder
    {
        return new query_builder($this->db);
    }

    public static function malicious_columns(): array
    {
        return [
            'sleep' => ['SLEEP(5)'],
            'benchmark alias' => ['BENCHMARK(1000000,MD5(1)) AS x'],
            'quoted alias subquery' => ['x AS "a, (SELECT 1)"'],
            'backtick breakout' => ['name` FROM users --'],
            'double quote breakout' => ['"name" OR 1=1'],
            'comment' => ['name -- '],
            'union' => ['name UNION SELECT password FROM users'],
            'aggregate injection' => ['COUNT(*) FROM users; --'],
            'nested function in aggregate' => ['SUM(SLEEP(1))'],
            'if expression' => ['IF(1=1,SLEEP(1),0)'],
            'three part' => ['a.b.c'],
            'semicolon' => ['id; DROP TABLE users'],
            'space' => ['first name'],
        ];
    }

    #[DataProvider('malicious_columns')]
    public function test_order_by_rejects_expression(string $column): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->table('test_table')->order_by($column);
    }

    #[DataProvider('malicious_columns')]
    public function test_select_rejects_expression(string $column): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->table('test_table')->select($column);
    }

    #[DataProvider('malicious_columns')]
    public function test_where_group_by_and_having_reject_expression(string $column): void
    {
        foreach (['where', 'group_by', 'having'] as $method) {
            try {
                $builder = $this->builder()->table('test_table');
                $method === 'group_by' ? $builder->group_by($column) : $builder->{$method}($column, '=', 1);
                $this->fail("{$method}() '{$column}' kabul etmemeli");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Geçersiz', $e->getMessage());
            }
        }
    }

    public function test_invalid_table_and_join_identifiers_are_rejected(): void
    {
        $cases = [
            fn () => $this->builder()->table('test_table` WHERE 1=1 --'),
            fn () => $this->builder()->table('test_table')->join('users u', 'test_table.id', '=', 'users.id'),
            fn () => $this->builder()->table('test_table')->join('users', 'test_table.id', '=', 'SLEEP(1)'),
            fn () => $this->builder()->table('test_table')->cross_join('users; DROP TABLE x'),
            fn () => $this->builder()->from($this->builder()->table('users'), 'u`, users'),
            fn () => $this->builder()->table('test_table')->order_by('id', 'DESC, SLEEP(1)'),
        ];

        foreach ($cases as $i => $case) {
            try {
                $case();
                $this->fail("Durum #{$i} exception fırlatmalı");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_quoted_identifiers_are_normalized(): void
    {
        $sql = $this->builder()
            ->select('`test_table`.`name`', '"id"')
            ->from('test_table')
            ->order_by('`id`', 'desc')
            ->get_query();

        $this->assertSame('SELECT `test_table`.`name`, `id` FROM `test_table` ORDER BY `id` DESC', $sql);
    }

    public function test_aggregates_keep_working(): void
    {
        $sql = $this->builder()
            ->select('name', 'COUNT(*) AS cnt', 'SUM(id) as total', 'COUNT(DISTINCT name) AS uniq', 'test_table.*')
            ->from('test_table')
            ->group_by('name')
            ->having('COUNT(*)', '>', 0)
            ->order_by('total', 'DESC')
            ->get_query();

        $this->assertStringContainsString('COUNT(*) AS `cnt`', $sql);
        $this->assertStringContainsString('SUM(`id`) AS `total`', $sql);
        $this->assertStringContainsString('COUNT(DISTINCT `name`) AS `uniq`', $sql);
        $this->assertStringContainsString('`test_table`.*', $sql);
        $this->assertStringContainsString('HAVING COUNT(*) >', $sql);
        $this->assertStringContainsString('ORDER BY `total` DESC', $sql);
    }

    public function test_aggregate_query_executes(): void
    {
        $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'a']);
        $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'a']);
        $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'b']);

        $rows = $this->builder()
            ->select('name', 'COUNT(*) AS cnt')
            ->from('test_table')
            ->group_by('name')
            ->having('COUNT(*)', '>', 1)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]->name);
        $this->assertSame(2, (int) $rows[0]->cnt);
    }

    public function test_raw_methods_allow_explicit_expressions(): void
    {
        $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'beta']);
        $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'alpha']);

        $rows = $this->builder()
            ->select('id')
            ->select_raw('UPPER(name) AS upper_name')
            ->from('test_table')
            ->where_raw('CHAR_LENGTH(name) > :len', ['len' => 3])
            ->order_by_raw("FIELD(name, 'beta', 'alpha')")
            ->get();

        $this->assertSame(['BETA', 'ALPHA'], array_map(fn ($r) => $r->upper_name, $rows));
    }

    public function test_join_column_named_like_php_function_is_not_called(): void
    {
        $sql = $this->builder()
            ->select('*')
            ->from('test_table')
            ->join('users', 'max', '=', 'users.id')
            ->get_query();

        $this->assertStringContainsString('ON `max` = `users`.`id`', $sql);
    }

    public function test_raw_bindings_must_be_named(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->table('test_table')->where_raw('id = ?', [1]);
    }
}
