<?php

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

/**
 * `composer test` cache kapalı, `composer test:cache` cache açık koşar; ikisinin de
 * gerçekten istenen modda çalıştığını doğrular (#40).
 */
class QueryCacheSuiteTest extends DatabaseTestCase
{
    public function test_suite_runs_in_requested_cache_mode(): void
    {
        $this->assertSame(self::query_cache_suite(), $this->db->get_cache_stats()['enabled']);
    }

    public function test_reads_reflect_writes_in_both_modes(): void
    {
        $id = $this->db->insert('INSERT INTO test_table (name) VALUES (:n)', ['n' => 'v1']);
        $read = fn () => $this->db->get_row('SELECT name FROM test_table WHERE id = :id', ['id' => $id])->name;

        $this->assertSame('v1', $read());
        $this->db->update('UPDATE test_table SET name = :n WHERE id = :id', ['n' => 'v2', 'id' => $id]);
        $this->assertSame('v2', $read());
    }
}
