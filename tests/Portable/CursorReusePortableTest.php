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
