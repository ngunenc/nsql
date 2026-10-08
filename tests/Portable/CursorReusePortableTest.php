<?php

namespace Tests\Portable;

use nsql\database\Config;
use Tests\Support\PortableTestCase;

class CursorReusePortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_cursor', ['name VARCHAR(50) NOT NULL']);
        $this->db->insert('INSERT INTO p_cursor (name) VALUES (?), (?), (?)', ['a', 'b', 'c']);
    }

    public function test_cached_statement_is_reusable_after_get_results(): void
    {
        $sql = 'SELECT name FROM p_cursor WHERE id >= ? ORDER BY id';

        for ($i = 0; $i < 3; $i++) {
            $this->assertCount(3, $this->db->get_results($sql, [1]));
            $this->db->statement('UPDATE p_cursor SET name = name WHERE id = ?', [1]);
            $this->assertSame('a', $this->db->get_row('SELECT name FROM p_cursor WHERE id = ?', [1])->name);
            $this->assertCount(2, $this->db->get_results($sql, [2]));
        }
    }

    public function test_held_query_statement_survives_same_sql(): void
    {
        $sql = 'SELECT name FROM p_cursor ORDER BY id';

        $first = $this->db->query($sql);
        $this->assertNotFalse($first);
        $this->assertSame('a', $first->fetch(\PDO::FETCH_OBJ)->name);

        $second = $this->db->query($sql);
        $this->assertNotSame($first, $second);
        $this->assertSame('a', $second->fetch(\PDO::FETCH_OBJ)->name);
        $second->closeCursor();

        $this->assertSame('b', $first->fetch(\PDO::FETCH_OBJ)->name);
        $this->assertSame('c', $first->fetch(\PDO::FETCH_OBJ)->name);
        $first->closeCursor();
    }

    public function test_buffered_get_yield_with_same_query_inside_loop(): void
    {
        $sql = 'SELECT name FROM p_cursor ORDER BY id';
        $outer = [];

        foreach ($this->db->get_yield($sql, [], unbuffered: false) as $row) {
            $outer[] = $row->name;
            // Aynı sorgu döngü içinde: dış akış bozulmamalı
            $inner = iterator_to_array($this->db->get_yield($sql, [], unbuffered: false), false);
            $this->assertCount(3, $inner);
        }

        $this->assertSame(['a', 'b', 'c'], $outer);
    }

    public function test_warm_cache_force_reloads_existing_entry(): void
    {
        $previous = Config::get('query_cache_enabled');
        Config::set('query_cache_enabled', true);
        try {
            $db = $this->connect();
        } finally {
            Config::set('query_cache_enabled', $previous);
        }
        $sql = 'SELECT name FROM p_cursor WHERE id = 1';
        $db->register_warm_query($sql);
        $this->assertSame(1, $db->warm_cache()['loaded']);

        // Cache'i atlayan yazma: eski kayıt cache'te kalır
        $this->assertNotNull($db->get_pdo());
        $db->get_pdo()->exec("UPDATE p_cursor SET name = 'z' WHERE id = 1");

        $this->assertSame(1, $db->warm_cache()['loaded']);
        $this->assertSame('a', $db->get_results($sql)[0]->name);

        $this->assertSame(1, $db->warm_cache(true)['loaded']);
        $this->assertSame('z', $db->get_results($sql)[0]->name);
    }

    public function test_preload_then_reuse(): void
    {
        $previous = Config::get('query_cache_enabled');
        Config::set('query_cache_enabled', true);
        try {
            $db = $this->connect();
        } finally {
            Config::set('query_cache_enabled', $previous);
        }
        $sql = 'SELECT name FROM p_cursor ORDER BY id';

        $this->assertTrue($db->preload_query($sql));
        $db->invalidate_all_cache();
        $this->assertTrue($db->preload_query($sql));
        $this->assertCount(3, $db->get_results('SELECT name FROM p_cursor WHERE id > ?', [0]));
    }
}
