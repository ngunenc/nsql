<?php

namespace Tests\Integration;

use nsql\database\exceptions\QueryException;
use nsql\database\query_builder;
use Tests\Support\DatabaseTestCase;

/**
 * #49: query builder yazma işlemleri ve yardımcılar.
 */
class QueryBuilderWriteIntegrationTest extends DatabaseTestCase
{
    private static bool $schema_ready = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schema_ready) {
            $this->db->query('DROP TABLE IF EXISTS qb_items');
            $this->db->query('CREATE TABLE qb_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(20) NOT NULL UNIQUE,
                name VARCHAR(50) NOT NULL,
                qty INT NOT NULL DEFAULT 0,
                category VARCHAR(20) NULL
            ) ENGINE=InnoDB');
            self::$schema_ready = true;
        }

        $this->db->query('TRUNCATE TABLE qb_items');
    }

    private function items(): query_builder
    {
        return (new query_builder($this->db))->table('qb_items');
    }

    private function seed(): void
    {
        $this->items()->insert_many([
            ['sku' => 'A1', 'name' => 'apple', 'qty' => 5, 'category' => 'fruit'],
            ['sku' => 'B1', 'name' => 'banana', 'qty' => 0, 'category' => 'fruit'],
            ['sku' => 'C1', 'name' => 'carrot', 'qty' => 12, 'category' => 'veg'],
            ['sku' => 'D1', 'name' => 'donut', 'qty' => 3, 'category' => null],
        ]);
    }

    public function test_insert_returns_id(): void
    {
        $id = $this->items()->insert(['sku' => 'X1', 'name' => 'x', 'qty' => 1]);

        $this->assertSame(1, (int) $id);
        $this->assertSame('x', $this->items()->where('id', '=', $id)->value('name'));
    }

    public function test_insert_many_returns_row_count(): void
    {
        $this->assertSame(0, $this->items()->insert_many([]));
        $this->seed();
        $this->assertSame(4, $this->items()->count());
    }

    public function test_insert_many_rejects_mismatched_rows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->items()->insert_many([
            ['sku' => 'A', 'name' => 'a'],
            ['sku' => 'B', 'title' => 'b'],
        ]);
    }

    public function test_update_returns_affected_rows(): void
    {
        $this->seed();

        $affected = $this->items()->where('category', '=', 'fruit')->update(['qty' => 99]);

        $this->assertSame(2, $affected);
        $this->assertSame([99, 99], array_map('intval', $this->items()->where('category', '=', 'fruit')->pluck('qty')));
    }

    public function test_update_with_raw_expression(): void
    {
        $this->seed();

        $this->items()->where('sku', '=', 'A1')->update(['qty' => query_builder::raw('qty + :inc', ['inc' => 10])]);

        $this->assertSame(15, (int) $this->items()->where('sku', '=', 'A1')->value('qty'));
    }

    public function test_update_and_delete_without_where_require_confirmation(): void
    {
        $this->seed();

        try {
            $this->items()->update(['qty' => 0]);
            $this->fail('LogicException bekleniyordu');
        } catch (\LogicException $e) {
        }
        try {
            $this->items()->delete();
            $this->fail('LogicException bekleniyordu');
        } catch (\LogicException $e) {
        }

        $this->assertSame(4, $this->items()->update(['qty' => 1], true));
        $this->assertSame(4, $this->items()->delete(true));
    }

    public function test_delete_returns_deleted_rows(): void
    {
        $this->seed();

        $this->assertSame(1, $this->items()->where_null('category')->delete());
        $this->assertSame(3, $this->items()->count());
    }

    public function test_upsert_inserts_and_updates(): void
    {
        $this->seed();

        $this->items()->upsert(
            [
                ['sku' => 'A1', 'name' => 'apple2', 'qty' => 50],
                ['sku' => 'E1', 'name' => 'egg', 'qty' => 6],
            ],
            ['name', 'qty'],
            ['sku']
        );

        $this->assertSame(5, $this->items()->count());
        $this->assertSame('apple2', $this->items()->where('sku', '=', 'A1')->value('name'));
        $this->assertSame(6, (int) $this->items()->where('sku', '=', 'E1')->value('qty'));

        $this->items()->upsert(['sku' => 'E1', 'name' => 'egg', 'qty' => 1], ['qty' => query_builder::raw('qty + 100')]);
        $this->assertSame(106, (int) $this->items()->where('sku', '=', 'E1')->value('qty'));
    }

    public function test_write_errors_throw_query_exception(): void
    {
        $this->seed();

        $this->expectException(QueryException::class);
        $this->items()->insert(['sku' => 'A1', 'name' => 'duplicate']);
    }

    public function test_writes_reject_joins_and_limits(): void
    {
        $this->expectException(\LogicException::class);
        $this->items()->where('id', '=', 1)->limit(1)->update(['qty' => 1]);
    }

    public function test_count_exists_pluck_value(): void
    {
        $this->seed();

        $this->assertSame(4, $this->items()->count());
        $this->assertSame(3, $this->items()->count('category'));
        $this->assertSame(2, $this->items()->where('category', '=', 'fruit')->count());
        $this->assertSame(2, $this->items()->select('category')->group_by('category')->where_not_null('category')->count());
        $this->assertSame(2, $this->items()->order_by('id')->limit(2)->count());

        $this->assertTrue($this->items()->where('sku', '=', 'C1')->exists());
        $this->assertFalse($this->items()->where('sku', '=', 'ZZ')->exists());

        $this->assertSame(['apple', 'banana', 'carrot', 'donut'], $this->items()->order_by('id')->pluck('name'));
        $this->assertSame(['A1' => 'apple', 'C1' => 'carrot'], $this->items()->where_in('sku', ['A1', 'C1'])->pluck('name', 'sku'));
        $this->assertSame('carrot', $this->items()->order_by('qty', 'DESC')->value('name'));
        $this->assertNull($this->items()->where('sku', '=', 'ZZ')->value('name'));
    }

    public function test_paginate(): void
    {
        $this->seed();

        $page = $this->items()->order_by('id')->paginate(3, 2);

        $this->assertSame(4, $page['total']);
        $this->assertSame(3, $page['per_page']);
        $this->assertSame(2, $page['current_page']);
        $this->assertSame(2, $page['last_page']);
        $this->assertSame(['donut'], array_map(fn ($r) => $r->name, $page['data']));

        $empty = $this->items()->where('sku', '=', 'ZZ')->paginate(10);
        $this->assertSame(0, $empty['total']);
        $this->assertSame([], $empty['data']);
        $this->assertSame(1, $empty['last_page']);
    }

    public function test_or_where_and_groups(): void
    {
        $this->seed();

        $names = $this->items()
            ->where('category', '=', 'veg')
            ->or_where('qty', '=', 0)
            ->order_by('id')
            ->pluck('name');
        $this->assertSame(['banana', 'carrot'], $names);

        $grouped = $this->items()
            ->where('qty', '>', 1)
            ->where(fn (query_builder $q) => $q->where('category', '=', 'fruit')->or_where_null('category'))
            ->order_by('id')
            ->pluck('name');
        $this->assertSame(['apple', 'donut'], $grouped);
        $this->assertStringContainsString('AND (', $this->items()->where('qty', '>', 1)
            ->where(fn (query_builder $q) => $q->where('a', '=', 1)->or_where('b', '=', 2))->get_query());
    }

    public function test_where_between_and_when(): void
    {
        $this->seed();

        $this->assertSame(['apple', 'donut'], $this->items()->where_between('qty', 1, 5)->order_by('id')->pluck('name'));
        $this->assertSame(['banana', 'carrot'], $this->items()->where_not_between('qty', 1, 5)->order_by('id')->pluck('name'));

        $filter = 'veg';
        $this->assertSame(['carrot'], $this->items()->when($filter, fn ($q, $v) => $q->where('category', '=', $v))->pluck('name'));
        $this->assertSame(4, $this->items()->when(null, fn ($q) => $q->where('id', '=', 0))->count());
        $this->assertSame(1, $this->items()->when(false, fn ($q) => $q, fn ($q) => $q->where('sku', '=', 'A1'))->count());
    }

    public function test_raw_in_select_and_where(): void
    {
        $this->seed();

        $row = $this->items()
            ->select('sku', query_builder::raw('qty * :m AS doubled', ['m' => 2]))
            ->where('qty', '>', query_builder::raw('(SELECT MIN(qty) FROM qb_items WHERE qty > :min)', ['min' => 3]))
            ->order_by('id')
            ->first();

        $this->assertSame('C1', $row->sku);
        $this->assertSame(24, (int) $row->doubled);
    }
}
