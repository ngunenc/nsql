<?php

namespace Tests\Portable;

use Tests\Support\PortableTestCase;

class QueryBuilderPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_products', [
            'sku VARCHAR(20) NOT NULL UNIQUE',
            'name VARCHAR(50) NOT NULL',
            'category VARCHAR(20) NULL',
            'price INT NOT NULL',
        ]);
        $this->create_table('p_orders', ['product_id INT NOT NULL', 'qty INT NOT NULL']);

        $this->db->table('p_products')->insert_many([
            ['sku' => 'A1', 'name' => 'Kalem', 'category' => 'kirtasiye', 'price' => 10],
            ['sku' => 'A2', 'name' => 'Defter', 'category' => 'kirtasiye', 'price' => 25],
            ['sku' => 'B1', 'name' => 'Kupa', 'category' => 'mutfak', 'price' => 40],
            ['sku' => 'C1', 'name' => 'Lamba', 'category' => null, 'price' => 120],
        ]);
        $this->db->table('p_orders')->insert_many([
            ['product_id' => 1, 'qty' => 3],
            ['product_id' => 1, 'qty' => 2],
            ['product_id' => 3, 'qty' => 1],
        ]);
    }

    public function test_where_variants(): void
    {
        $names = fn ($builder) => $builder->order_by('id')->pluck('name');

        $this->assertSame(['Defter', 'Kupa'], $names($this->db->table('p_products')->where_between('price', 20, 50)));
        $this->assertSame(['Kalem', 'Kupa'], $names($this->db->table('p_products')->where_in('sku', ['A1', 'B1'])));
        $this->assertSame(['Lamba'], $names($this->db->table('p_products')->where_null('category')));
        $this->assertSame(
            ['Kalem', 'Lamba'],
            $names($this->db->table('p_products')->where('price', '<', 15)->or_where('price', '>', 100))
        );
        $this->assertSame(
            ['Defter'],
            $names($this->db->table('p_products')->where('category', '=', 'kirtasiye')->where(fn ($q) => $q->where('price', '>', 20)))
        );
    }

    public function test_aggregates_and_helpers(): void
    {
        $products = fn () => $this->db->table('p_products');

        $this->assertSame(4, $products()->count());
        $this->assertSame(2, $products()->where('category', '=', 'kirtasiye')->count());
        $this->assertTrue($products()->where('sku', '=', 'B1')->exists());
        $this->assertFalse($products()->where('sku', '=', 'ZZ')->exists());
        $this->assertSame('Kupa', $products()->where('sku', '=', 'B1')->value('name'));
        $this->assertSame(['A1' => 'Kalem', 'A2' => 'Defter'], $products()->where('category', '=', 'kirtasiye')->order_by('id')->pluck('name', 'sku'));
        $this->assertSame('Lamba', $products()->order_by('price', 'DESC')->first()->name);
    }

    public function test_limit_offset_and_paginate(): void
    {
        $page = $this->db->table('p_products')->order_by('id')->paginate(3, 2);

        $this->assertSame(4, $page['total']);
        $this->assertCount(1, $page['data']);
        $this->assertSame('Lamba', $page['data'][0]->name);

        $rows = $this->db->table('p_products')->order_by('id')->limit(2)->offset(1)->get();
        $this->assertSame(['Defter', 'Kupa'], array_map(fn ($r) => $r->name, $rows));
    }

    public function test_join_group_by_having(): void
    {
        $rows = $this->db->table('p_products')
            ->select('p_products.name')
            ->select_raw('SUM(p_orders.qty) AS total_qty')
            ->join('p_orders', 'p_orders.product_id', '=', 'p_products.id')
            ->group_by('p_products.id', 'p_products.name')
            ->having_raw('SUM(p_orders.qty) > :min_qty', ['min_qty' => 1])
            ->order_by('p_products.id')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Kalem', $rows[0]->name);
        $this->assertSame(5, (int) $rows[0]->total_qty);

        $left = $this->db->table('p_products')
            ->select('p_products.sku')
            ->left_join('p_orders', 'p_orders.product_id', '=', 'p_products.id')
            ->where_null('p_orders.id')
            ->order_by('p_products.id')
            ->pluck('sku');
        $this->assertSame(['A2', 'C1'], $left);
    }

    public function test_subqueries(): void
    {
        $ordered = $this->db->table('p_orders')->select('product_id');

        $this->assertSame(
            ['A1', 'B1'],
            $this->db->table('p_products')->where_in_subquery('id', $ordered)->order_by('id')->pluck('sku')
        );
    }

    public function test_union(): void
    {
        $category = fn (string $c) => $this->db->table('p_products')->select('name')->where('category', '=', $c);

        $union = $category('kirtasiye')->union($category('mutfak'))->order_by('name');
        $this->assertSame(['Defter', 'Kalem', 'Kupa'], array_column($union->get(), 'name'));
        $this->assertSame(3, $category('kirtasiye')->union($category('mutfak'))->count());

        $sku = fn (string $s) => $this->db->table('p_products')->select('name')->where('sku', '=', $s);
        $this->assertCount(1, $sku('A1')->union($sku('A1'))->get());
        $this->assertCount(2, $sku('A1')->union($sku('A1'), true)->get());

        // Alt sorgunun kendi ORDER BY / LIMIT'i korunur
        $most_expensive = $this->db->table('p_products')->select('name')->order_by('price', 'DESC')->limit(1);
        $names = array_column($sku('A1')->union($most_expensive)->get(), 'name');
        sort($names);
        $this->assertSame(['Kalem', 'Lamba'], $names);
    }

    public function test_full_join_support_matches_driver(): void
    {
        $build = fn () => $this->db->table('p_products')->full_join('p_orders', 'p_products.id', '=', 'p_orders.product_id');
        $sqlite_version = (string) $this->db->get_pdo()?->getAttribute(\PDO::ATTR_SERVER_VERSION);
        $supported = match (self::driver()) {
            'mysql' => false,
            'sqlite' => version_compare($sqlite_version, '3.39.0', '>='),
            default => true,
        };

        if (! $supported) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('FULL JOIN');
            $build();

            return;
        }

        // A1 iki sipariş, B1 bir sipariş, A2 ve C1 siparişsiz → 5 satır
        $this->assertCount(5, $build()->select('p_products.sku', 'p_orders.qty')->get());
    }

    public function test_table_alias_and_self_join(): void
    {
        $this->assertSame(
            ['Kalem'],
            $this->db->table('p_products AS p')->where('p.sku', '=', 'A1')->pluck('p.name')
        );

        // Self-join: aynı kategorideki daha pahalı ürünler
        $pairs = $this->db->table('p_products a')
            ->join('p_products b', 'a.category', '=', 'b.category')
            ->where_column('a.price', '<', 'b.price')
            ->select('a.name AS cheaper', 'b.name AS pricier')
            ->get();
        $this->assertSame([['Kalem', 'Defter']], array_map(fn ($r) => [$r->cheaper, $r->pricier], $pairs));

        $this->expectException(\LogicException::class);
        $this->db->table('p_products AS p')->where('p.id', '=', 1)->update(['price' => 1]);
    }

    public function test_distinct_and_count(): void
    {
        $categories = $this->db->table('p_products')->distinct()->select('category')->where_not_null('category')->order_by('category');

        $this->assertSame(['kirtasiye', 'mutfak'], $categories->pluck('category'));
        $this->assertSame(2, $categories->count());
        $this->assertSame(3, $this->db->table('p_orders')->count());
        $this->assertSame(2, $this->db->table('p_orders')->distinct()->select('product_id')->count());
    }

    public function test_where_column_and_or_variants(): void
    {
        $names = fn ($builder) => $builder->order_by('id')->pluck('name');

        $this->assertSame(
            ['Kalem', 'Lamba'],
            $names($this->db->table('p_products')->where('price', '<', 20)->or_where_not_in('sku', ['A1', 'A2', 'B1']))
        );
        $this->assertSame(
            ['Kalem', 'Defter', 'Kupa'],
            $names($this->db->table('p_products')->where('price', '<', 0)->or_where_not_null('category'))
        );
        $this->assertSame(
            ['Kalem', 'Defter', 'Kupa', 'Lamba'],
            $names($this->db->table('p_products')->where_column('id', '<=', 'price'))
        );
        $this->assertSame([], $names($this->db->table('p_products')->where_column('id', 'price')));

        $this->expectException(\InvalidArgumentException::class);
        $this->db->table('p_products')->where_column('id', 'IN', 'price');
    }

    public function test_increment_and_decrement(): void
    {
        $products = fn () => $this->db->table('p_products')->where('sku', '=', 'A1');

        $this->assertSame(1, $products()->increment('price'));
        $this->assertSame(1, $products()->increment('price', 5, ['name' => 'Kalem+']));
        $this->assertSame(16, (int) $products()->value('price'));
        $this->assertSame('Kalem+', $products()->value('name'));

        $this->assertSame(1, $products()->decrement('price', 6));
        $this->assertSame(10, (int) $products()->value('price'));

        $this->expectException(\LogicException::class);
        $this->db->table('p_products')->increment('price');
    }

    public function test_locks_compile_per_driver_and_run_in_transaction(): void
    {
        $sql = $this->db->table('p_products')->where('id', '=', 1)->lock_for_update()->get_query();
        $shared = $this->db->table('p_products')->where('id', '=', 1)->shared_lock()->get_query();

        match (self::driver()) {
            'sqlite' => [$this->assertStringNotContainsString('FOR UPDATE', $sql), $this->assertStringNotContainsString('SHARE', $shared)],
            'mysql' => [$this->assertStringEndsWith('FOR UPDATE', $sql), $this->assertStringEndsWith('LOCK IN SHARE MODE', $shared)],
            default => [$this->assertStringEndsWith('FOR UPDATE', $sql), $this->assertStringEndsWith('FOR SHARE', $shared)],
        };

        $name = $this->db->transaction(
            fn () => $this->db->table('p_products')->where('id', '=', 1)->lock_for_update()->value('name')
        );
        $this->assertSame('Kalem', $name);
    }

    public function test_chunk_walks_all_rows_by_key(): void
    {
        $seen = [];
        $pages = [];
        $done = $this->db->table('p_products')->select('id', 'name')->chunk(3, function (array $rows, int $page) use (&$seen, &$pages) {
            $pages[] = $page;
            foreach ($rows as $row) {
                $seen[] = $row->name;
            }
        });

        $this->assertTrue($done);
        $this->assertSame([1, 2], $pages);
        $this->assertSame(['Kalem', 'Defter', 'Kupa', 'Lamba'], $seen);

        $stopped = $this->db->table('p_products')->where('price', '>', 0)->chunk(1, fn () => false);
        $this->assertFalse($stopped);
    }

    public function test_write_operations(): void
    {
        $id = $this->db->table('p_products')->insert(['sku' => 'D1', 'name' => 'Masa', 'category' => 'mobilya', 'price' => 900]);
        $this->assertSame(5, (int) $id);

        $this->assertSame(2, $this->db->table('p_products')->where('category', '=', 'kirtasiye')->update(['price' => 30]));
        $this->assertSame(1, $this->db->table('p_products')->where('sku', '=', 'D1')->delete());
        $this->assertSame(
            [30, 30],
            array_map('intval', $this->db->table('p_products')->where('category', '=', 'kirtasiye')->pluck('price'))
        );
    }

    public function test_upsert_inserts_and_updates(): void
    {
        $this->db->table('p_products')->upsert(
            [
                ['sku' => 'A1', 'name' => 'Kalem v2', 'category' => 'kirtasiye', 'price' => 12],
                ['sku' => 'E1', 'name' => 'Silgi', 'category' => 'kirtasiye', 'price' => 5],
            ],
            ['name', 'price'],
            ['sku']
        );

        $this->assertSame(5, $this->db->table('p_products')->count());
        $this->assertSame('Kalem v2', $this->db->table('p_products')->where('sku', '=', 'A1')->value('name'));
        $this->assertSame(5, (int) $this->db->table('p_products')->where('sku', '=', 'E1')->value('price'));
    }
}
