<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

class StatementCacheLruTest extends TestCase
{
    private Nsql $db;
    private string $file;
    private mixed $previous_limit;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        Config::set_environment('testing');
        $this->previous_limit = Config::get('statement_cache_limit');
        Config::set('statement_cache_limit', 2);
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_stmt_' . getmypid() . '.sqlite';
        $this->db = new Nsql(db: $this->file, driver: 'sqlite');
        $this->db->clear_statement_cache();
    }

    protected function tearDown(): void
    {
        Config::set('statement_cache_limit', $this->previous_limit);
        (fn () => $this->disconnect())->call($this->db);
        @unlink($this->file);
    }

    private function cached(string $sql): bool
    {
        return (fn () => isset($this->statement_cache[$this->get_statement_cache_key($sql, [])]))->call($this->db);
    }

    public function test_recently_used_statement_survives_eviction(): void
    {
        [$a, $b, $c] = ['SELECT 1 AS a', 'SELECT 2 AS b', 'SELECT 3 AS c'];

        foreach ([$a, $b, $a, $c] as $sql) {
            $this->db->get_results($sql);
        }

        $this->assertTrue($this->cached($a));
        $this->assertFalse($this->cached($b));
        $this->assertTrue($this->cached($c));

        $stats = $this->db->get_statement_cache_stats();
        $this->assertSame(2, $stats['size']);
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(3, $stats['misses']);
    }
}
