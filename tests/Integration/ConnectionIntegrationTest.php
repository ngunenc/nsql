<?php

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use nsql\database\nsql;

class ConnectionIntegrationTest extends DatabaseTestCase
{
    public function testConnection()
    {
        $this->assertInstanceOf(nsql::class, $this->db);
    }

    /**
     * v1.5.5: nsql PDO'yu extend etmez; tek fiziksel bağlantı pool üzerinden gelir.
     */
    public function testUsesCompositionNotExtendingPdo(): void
    {
        $this->assertNotInstanceOf(\PDO::class, $this->db);
        $pdo = $this->db->get_pdo();
        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame($pdo, $this->db->get_pdo());

        $stats = nsql::get_pool_stats();
        $this->assertGreaterThanOrEqual(1, $stats['total_connections']);
        $this->assertGreaterThanOrEqual(1, $stats['active_connections'] + $stats['idle_connections']);
    }

    public function testQueryCache()
    {
        $result1 = $this->db->get_results("SELECT * FROM test_table");
        $result2 = $this->db->get_results("SELECT * FROM test_table");
        $this->assertEquals($result1, $result2);
    }

    public function testConnectionPool()
    {
        $stats = nsql::get_pool_stats();
        $this->assertArrayHasKey('active_connections', $stats);
        $this->assertArrayHasKey('idle_connections', $stats);
    }

    public function testConnectionPoolStats()
    {
        $stats = nsql::get_pool_stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('active_connections', $stats);
        $this->assertArrayHasKey('idle_connections', $stats);
        $this->assertArrayHasKey('total_connections', $stats);
        $this->assertIsInt($stats['active_connections']);
        $this->assertIsInt($stats['idle_connections']);
    }

    public function testMemoryStats()
    {
        $stats = $this->db->get_memory_stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('peak_usage', $stats);
        $this->assertArrayHasKey('warning_count', $stats);
        $this->assertArrayHasKey('critical_count', $stats);
    }

    public function testCacheStats()
    {
        $stats = $this->db->get_all_cache_stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('query_cache', $stats);
        $this->assertArrayHasKey('statement_cache', $stats);
    }

    public function testAllStats()
    {
        $stats = $this->db->get_all_stats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('memory', $stats);
        $this->assertArrayHasKey('cache', $stats);
    }
}
