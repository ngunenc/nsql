<?php

namespace Tests\Integration;

use nsql\database\query_builder;
use Tests\Support\DatabaseTestCase;

/**
 * Query builder sorgularını gerçek veritabanında çalıştırır ve sonuçları doğrular (#40).
 */
class QueryBuilderIntegrationTest extends DatabaseTestCase
{
    private static bool $schema_ready = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schema_ready) {
            $this->db->query('DROP TABLE IF EXISTS qb_products');
            $this->db->query('DROP TABLE IF EXISTS qb_users');
            $this->db->query('DROP TABLE IF EXISTS qb_categories');
            $this->db->query('CREATE TABLE qb_users (
                id INT PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                active TINYINT NOT NULL DEFAULT 1
            ) ENGINE=InnoDB');
            $this->db->query('CREATE TABLE qb_categories (
                id INT PRIMARY KEY,
                title VARCHAR(50) NOT NULL
            ) ENGINE=InnoDB');
            $this->db->query('CREATE TABLE qb_products (
                id INT PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                category VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                user_id INT NULL,
                category_id INT NULL
            ) ENGINE=InnoDB');
            self::$schema_ready = true;
        }

        $this->db->query('DELETE FROM qb_products');
        $this->db->query('DELETE FROM qb_users');
        $this->db->query('DELETE FROM qb_categories');

        $this->db->batch_insert('qb_users', [
            ['id' => 1, 'name' => 'ali', 'active' => 1],
            ['id' => 2, 'name' => 'veli', 'active' => 0],
            ['id' => 3, 'name' => 'ayse', 'active' => 1],
        ]);
        $this->db->batch_insert('qb_categories', [
            ['id' => 10, 'title' => 'Books'],
            ['id' => 20, 'title' => 'Games'],
        ]);
        $this->db->batch_insert('qb_products', [
            ['id' => 1, 'name' => 'p1', 'category' => 'book', 'status' => 'on', 'price' => 100, 'user_id' => 1, 'category_id' => 10],
            ['id' => 2, 'name' => 'p2', 'category' => 'book', 'status' => 'off', 'price' => 300, 'user_id' => 1, 'category_id' => 10],
            ['id' => 3, 'name' => 'p3', 'category' => 'game', 'status' => 'on', 'price' => 700, 'user_id' => 2, 'category_id' => 20],
            ['id' => 4, 'name' => 'p4', 'category' => 'game', 'status' => 'on', 'price' => 900, 'user_id' => null, 'category_id' => null],
            ['id' => 5, 'name' => 'p5', 'category' => 'toy', 'status' => 'on', 'price' => 50, 'user_id' => 3, 'category_id' => null],
        ]);
    }

    private function qb(): query_builder
    {
        return new query_builder($this->db);
    }

    /**
     * @param array<int, object> $rows
     * @return list<mixed>
     */
    private function col(array $rows, string $column): array
    {
        return array_map(fn ($r) => $r->{$column}, $rows);
    }

    public function test_select_where_order_limit(): void
    {
        $builder = $this->qb()->select('*')->from('qb_products')
            ->where('category', '=', 'book')
            ->order_by('id', 'DESC')
            ->limit(10);

        $this->assertStringContainsString('ORDER BY `id` DESC', $builder->get_query());
        $this->assertSame(['p2', 'p1'], $this->col($builder->get(), 'name'));
    }

    public function test_multiple_where_are_combined_with_and(): void
    {
        $rows = $this->qb()->select('name')->from('qb_products')
            ->where('category', '=', 'game')
            ->where('price', '>', 800)
            ->get();

        $this->assertSame(['p4'], $this->col($rows, 'name'));
    }

    public function test_inner_join(): void
    {
        $rows = $this->qb()->select('qb_products.name', 'qb_users.name AS user_name')
            ->from('qb_products')
            ->join('qb_users', 'qb_products.user_id', '=', 'qb_users.id')
            ->order_by('qb_products.id')
            ->get();

        $this->assertSame(['p1', 'p2', 'p3', 'p5'], $this->col($rows, 'name'));
        $this->assertSame(['ali', 'ali', 'veli', 'ayse'], $this->col($rows, 'user_name'));
    }

    public function test_inner_join_alias_method(): void
    {
        $rows = $this->qb()->select('qb_products.id')->from('qb_products')
            ->inner_join('qb_users', 'qb_products.user_id', '=', 'qb_users.id')
            ->get();

        $this->assertCount(4, $rows);
    }

    public function test_left_join_keeps_unmatched_rows(): void
    {
        $rows = $this->qb()->select('qb_products.name', 'qb_users.name AS user_name')
            ->from('qb_products')
            ->left_join('qb_users', 'qb_products.user_id', '=', 'qb_users.id')
            ->order_by('qb_products.id')
            ->get();

        $this->assertCount(5, $rows);
        $this->assertNull($rows[3]->user_name);
    }

    public function test_right_join_keeps_unmatched_right_rows(): void
    {
        $rows = $this->qb()->select('qb_categories.title', 'qb_products.name')
            ->from('qb_products')
            ->right_join('qb_categories', 'qb_products.category_id', '=', 'qb_categories.id')
            ->order_by('qb_categories.id')
            ->get();

        $this->assertSame(['Books', 'Books', 'Games'], $this->col($rows, 'title'));
    }

    public function test_full_join_sql_is_generated(): void
    {
        // MySQL FULL JOIN desteklemez; yalnızca SQL üretimi doğrulanır.
        $query = $this->qb()->select('*')->from('qb_products')
            ->full_join('qb_users', 'qb_products.user_id', '=', 'qb_users.id')
            ->get_query();

        $this->assertStringContainsString('FULL JOIN', $query);
    }

    public function test_cross_join(): void
    {
        $builder = $this->qb()->select('qb_products.id')->from('qb_products')->cross_join('qb_categories');

        $this->assertStringNotContainsString(' ON ', $builder->get_query());
        $this->assertCount(10, $builder->get());
    }

    public function test_multiple_joins(): void
    {
        $rows = $this->qb()->select('qb_products.name', 'qb_users.name AS user_name', 'qb_categories.title')
            ->from('qb_products')
            ->left_join('qb_users', 'qb_products.user_id', '=', 'qb_users.id')
            ->left_join('qb_categories', 'qb_products.category_id', '=', 'qb_categories.id')
            ->where('qb_products.id', '=', 3)
            ->get();

        $this->assertSame('veli', $rows[0]->user_name);
        $this->assertSame('Games', $rows[0]->title);
    }

    public function test_join_with_closure_condition(): void
    {
        $rows = $this->qb()->select('qb_products.name')
            ->from('qb_products')
            ->join('qb_users', function () {
                return 'qb_products.user_id = qb_users.id AND qb_users.active = 1';
            })
            ->order_by('qb_products.id')
            ->get();

        $this->assertSame(['p1', 'p2', 'p5'], $this->col($rows, 'name'));
    }

    public function test_group_by_with_count(): void
    {
        $rows = $this->qb()->select('category', 'COUNT(*) AS cnt')
            ->from('qb_products')
            ->group_by('category')
            ->order_by('category')
            ->get();

        $this->assertSame(['book', 'game', 'toy'], $this->col($rows, 'category'));
        $this->assertSame([2, 2, 1], array_map('intval', $this->col($rows, 'cnt')));
    }

    public function test_group_by_multiple_columns(): void
    {
        $rows = $this->qb()->select('category', 'status', 'COUNT(*) AS cnt')
            ->from('qb_products')
            ->group_by('category', 'status')
            ->get();

        $this->assertCount(4, $rows);
    }

    public function test_having(): void
    {
        $rows = $this->qb()->select('category', 'COUNT(*) AS cnt')
            ->from('qb_products')
            ->group_by('category')
            ->having('COUNT(*)', '>', 1)
            ->order_by('category')
            ->get();

        $this->assertSame(['book', 'game'], $this->col($rows, 'category'));
    }

    public function test_group_by_having_and_order_by_alias(): void
    {
        $rows = $this->qb()->select('category', 'SUM(price) AS total')
            ->from('qb_products')
            ->group_by('category')
            ->having('SUM(price)', '>', 300)
            ->order_by('total', 'DESC')
            ->get();

        $this->assertSame(['game', 'book'], $this->col($rows, 'category'));
    }

    public function test_union_removes_duplicates_and_union_all_keeps_them(): void
    {
        $first = fn () => $this->qb()->select('name')->from('qb_users')->where('active', '=', 1);
        $second = fn () => $this->qb()->select('name')->from('qb_users')->where('id', '=', 1);

        $this->assertEqualsCanonicalizing(['ali', 'ayse'], $this->col($first()->union($second())->get(), 'name'));
        $this->assertEqualsCanonicalizing(
            ['ali', 'ayse', 'ali'],
            $this->col($first()->union($second(), true)->get(), 'name')
        );
    }

    public function test_where_in_subquery(): void
    {
        $active = $this->qb()->select('id')->from('qb_users')->where('active', '=', 1);

        $rows = $this->qb()->select('name')->from('qb_products')
            ->where('user_id', 'IN', $active)
            ->order_by('id')
            ->get();
        $this->assertSame(['p1', 'p2', 'p5'], $this->col($rows, 'name'));

        $rows = $this->qb()->select('name')->from('qb_products')
            ->where_in_subquery('user_id', $active)
            ->order_by('id')
            ->get();
        $this->assertSame(['p1', 'p2', 'p5'], $this->col($rows, 'name'));
    }

    public function test_where_exists_and_not_exists(): void
    {
        $inactive = $this->qb()->select('1')->from('qb_users')->where('active', '=', 0);
        $none = $this->qb()->select('1')->from('qb_users')->where('id', '=', 999);

        $this->assertCount(5, $this->qb()->select('id')->from('qb_products')->where_exists($inactive)->get());
        $this->assertCount(0, $this->qb()->select('id')->from('qb_products')->where_exists($none)->get());
        $this->assertCount(5, $this->qb()->select('id')->from('qb_products')->where_not_exists($none)->get());
    }

    public function test_select_subquery(): void
    {
        $count = $this->qb()->select('COUNT(*)')->from('qb_users')->where('active', '=', 1);

        $row = $this->qb()->select('name', $count)->from('qb_products')->where('id', '=', 1)->first();
        $values = array_values((array) $row);

        $this->assertSame('p1', $values[0]);
        $this->assertSame(2, (int) $values[1]);
    }

    public function test_from_subquery(): void
    {
        $active = $this->qb()->select('*')->from('qb_users')->where('active', '=', 1);

        $rows = $this->qb()->select('name')->from($active, 'active_users')->order_by('id')->get();

        $this->assertSame(['ali', 'ayse'], $this->col($rows, 'name'));
    }

    public function test_having_subquery(): void
    {
        $avg = $this->qb()->select('AVG(price)')->from('qb_products');

        $rows = $this->qb()->select('category', 'SUM(price) AS total')
            ->from('qb_products')
            ->group_by('category')
            ->having('SUM(price)', '>', $avg)
            ->order_by('category')
            ->get();

        $this->assertStringContainsString('AVG(`price`)', $this->qb()->select('AVG(price)')->from('qb_products')->get_query());
        $this->assertSame(['game'], $this->col($rows, 'category'));
    }
}
