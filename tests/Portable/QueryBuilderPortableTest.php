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
