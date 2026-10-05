<?php

namespace Tests\Integration;

use nsql\database\QueryBuilder;
use Tests\Support\DatabaseTestCase;

/**
 * #35: query builder subquery / UNION / having sorgularını gerçek veritabanında çalıştırır.
 */
class QueryBuilderExecutionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['a', 'a', 'b', 'c', 'c', 'c'] as $name) {
            $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => $name]);
        }
    }

    private function qb(): QueryBuilder
    {
        return new QueryBuilder($this->db);
    }

    private function names(array $rows): array
    {
        return array_map(fn ($r) => $r->name, $rows);
    }

    public function test_where_in_subquery_operator(): void
    {
        $sub = $this->qb()->select('id')->from('test_table')->where('name', '=', 'b');

        $rows = $this->qb()->select('name')->from('test_table')->where('id', 'IN', $sub)->get();

        $this->assertSame(['b'], $this->names($rows));
    }

    public function test_where_in_subquery_and_not_in(): void
    {
        $sub = $this->qb()->select('id')->from('test_table')->where('name', '=', 'c');

        $in = $this->qb()->select('name')->from('test_table')->where_in_subquery('id', $sub)->get();
        $not_in = $this->qb()->select('name')->from('test_table')
            ->where('name', '<>', 'a')
            ->where_in_subquery('id', $sub, true)
            ->get();

        $this->assertSame(['c', 'c', 'c'], $this->names($in));
        $this->assertSame(['b'], $this->names($not_in));
    }

    public function test_where_exists_and_not_exists(): void
    {
        $exists = $this->qb()->select('1')->from('test_table')->where('name', '=', 'b');
        $missing = $this->qb()->select('1')->from('test_table')->where('name', '=', 'zzz');

        $this->assertCount(6, $this->qb()->select('id')->from('test_table')->where_exists($exists)->get());
        $this->assertCount(0, $this->qb()->select('id')->from('test_table')->where_exists($missing)->get());
        $this->assertCount(6, $this->qb()->select('id')->from('test_table')->where_not_exists($missing)->get());
    }

    public function test_from_subquery_with_params_on_both_levels(): void
    {
        $sub = $this->qb()->select('id', 'name')->from('test_table')->where('name', '<>', 'b');

        $rows = $this->qb()->select('name')->from($sub, 't')->where('name', '=', 'a')->get();

        $this->assertSame(['a', 'a'], $this->names($rows));
    }

    public function test_select_subquery(): void
    {
        $sub = $this->qb()->select('COUNT(*)')->from('test_table')->where('name', '=', 'c');

        $row = $this->qb()->select('name', $sub)->from('test_table')->where('name', '=', 'b')->first();

        $values = array_values((array) $row);
        $this->assertSame('b', $values[0]);
        $this->assertSame(3, (int) $values[1]);
    }

    public function test_join_subquery(): void
    {
        $sub = $this->qb()->select('name', 'COUNT(*) AS cnt')->from('test_table')->group_by('name');

        $rows = $this->qb()
            ->select('test_table.name', 'counts.cnt')
            ->from('test_table')
            ->join($sub, 'test_table.name', '=', 'counts.name', 'INNER', 'counts')
            ->where('test_table.name', '=', 'a')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $rows[0]->cnt);
    }

    public function test_having_aggregate_and_subquery(): void
    {
        $rows = $this->qb()->select('name', 'COUNT(*) AS cnt')->from('test_table')
            ->group_by('name')
            ->having('COUNT(*)', '>', 1)
            ->order_by('name')
            ->get();
        $this->assertSame(['a', 'c'], $this->names($rows));

        $threshold = $this->qb()->select('COUNT(*)')->from('test_table')->where('name', '=', 'c');
        $rows = $this->qb()->select('name')->from('test_table')
            ->group_by('name')
            ->having('COUNT(*)', '>=', $threshold)
            ->get();
        $this->assertSame(['c'], $this->names($rows));
    }

    public function test_union_and_union_all_with_params(): void
    {
        $first = fn () => $this->qb()->select('name')->from('test_table')->where('name', '=', 'a');
        $second = fn () => $this->qb()->select('name')->from('test_table')->where('name', '=', 'b');

        $union = $first()->union($second())->get();
        $union_all = $first()->union($second(), true)->get();

        $this->assertEqualsCanonicalizing(['a', 'b'], $this->names($union));
        $this->assertEqualsCanonicalizing(['a', 'a', 'b'], $this->names($union_all));
    }

    public function test_many_parameters_do_not_collide(): void
    {
        $sub = $this->qb()->select('id')->from('test_table');
        for ($i = 0; $i < 11; $i++) {
            $sub->where('id', '>', -$i - 1);
        }
        $sub->where('name', '=', 'c');

        $builder = $this->qb()->select('name')->from('test_table');
        for ($i = 0; $i < 11; $i++) {
            $builder->where('id', '<>', -$i - 1);
        }
        $rows = $builder->where_in_subquery('id', $sub)->get();

        $this->assertSame(['c', 'c', 'c'], $this->names($rows));
    }

    public function test_compile_is_idempotent(): void
    {
        $builder = $this->qb()->select('name')->from('test_table')
            ->where('name', '=', 'c')
            ->union($this->qb()->select('name')->from('test_table')->where('name', '=', 'b'), true)
            ->limit(2)
            ->offset(1);

        $sql = $builder->get_query();
        $this->assertSame($sql, $builder->get_query());
        $this->assertSame($builder->get_params(), $builder->get_params());

        $first = $builder->get();
        $second = $builder->get();

        $this->assertCount(2, $first);
        $this->assertEquals($first, $second);
        $this->assertCount(4, $builder->get_params());
    }

    public function test_first_does_not_change_builder_limit(): void
    {
        $builder = $this->qb()->select('name')->from('test_table')->where('name', '=', 'c');

        $this->assertSame('c', $builder->first()->name);
        $this->assertCount(3, $builder->get());
    }

    public function test_offset(): void
    {
        $rows = $this->qb()->select('name')->from('test_table')->order_by('id')->limit(2)->offset(2)->get();

        $this->assertSame(['b', 'c'], $this->names($rows));
    }

    public function test_raw_bindings_in_subquery_and_main_query_do_not_collide(): void
    {
        $sub = $this->qb()->select('id')->from('test_table')->where_raw('name = :name', ['name' => 'c']);

        $rows = $this->qb()->select('name')->from('test_table')
            ->where_raw('name <> :name', ['name' => 'a'])
            ->where_in_subquery('id', $sub)
            ->get();

        $this->assertSame(['c', 'c', 'c'], $this->names($rows));
    }

    public function test_null_value_is_bound(): void
    {
        $rows = $this->qb()->select('id')->from('test_table')->where_raw('(:v IS NULL)', ['v' => null])->get();

        $this->assertCount(6, $rows);
    }
}
