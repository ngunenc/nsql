<?php

namespace Tests\Integration;

use nsql\database\cache\adapter_simple_cache;
use nsql\database\cache\query_cache_store_factory;
use nsql\database\config;
use nsql\database\nsql;
use Tests\Support\DatabaseTestCase;
use Tests\Support\JsonCacheAdapter;

/**
 * #18: cache adaptörleri (redis/memcached) paylaşılan query cache store olarak.
 */
class SharedQueryCacheIntegrationTest extends DatabaseTestCase
{
    /** @var list<nsql> */
    private array $extra = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DROP TABLE IF EXISTS sq_items');
        $this->db->query('CREATE TABLE sq_items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL) ENGINE=InnoDB');
        $this->db->insert('INSERT INTO sq_items (name) VALUES (?), (?)', ['a', 'b']);
    }

    protected function tearDown(): void
    {
        foreach ($this->extra as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->extra = [];
        config::set('query_cache_driver', config::query_cache_driver);
        config::set('redis_port', 6379);
        config::set('query_cache_enabled', self::query_cache_suite());
        query_cache_store_factory::reset();

        parent::tearDown();
    }

    private function connection(): nsql
    {
        config::set('query_cache_enabled', true);
        $db = new nsql(
            host: config::get('db_host', 'localhost'),
            db: config::get('db_name', 'nsql_test_db'),
            user: config::get('db_user', 'root'),
            pass: config::get('db_pass', '')
        );
        $this->extra[] = $db;

        return $db;
    }

    public function test_rows_from_json_backend_are_objects(): void
    {
        $store = new adapter_simple_cache(new JsonCacheAdapter());
        $writer = $this->connection()->set_query_cache_store($store);
        $reader = $this->connection()->set_query_cache_store($store);
        $queries = 0;
        $reader->on_query(function () use (&$queries): void {
            $queries++;
        });

        $writer->get_results('SELECT * FROM sq_items ORDER BY id');
        $writer->get_row('SELECT * FROM sq_items WHERE id = 1');

        $rows = $reader->get_results('SELECT * FROM sq_items ORDER BY id');
        $row = $reader->get_row('SELECT * FROM sq_items WHERE id = 1');

        $this->assertSame(0, $queries);
        $this->assertIsObject($rows[0]);
        $this->assertSame('b', $rows[1]->name);
        $this->assertIsObject($row);
        $this->assertSame('a', $row->name);
        $this->assertSame('json_test', $reader->get_cache_stats()['store']);
    }

    public function test_write_through_one_instance_invalidates_json_backend(): void
    {
        $store = new adapter_simple_cache(new JsonCacheAdapter());
        $a = $this->connection()->set_query_cache_store($store);
        $a->get_results('SELECT * FROM sq_items');

        $a->statement('DELETE FROM sq_items WHERE id = 1');

        $b = $this->connection()->set_query_cache_store($store);
        $this->assertCount(1, $b->get_results('SELECT * FROM sq_items'));
    }

    public function test_unreachable_redis_falls_back_to_process_cache(): void
    {
        config::set('query_cache_driver', 'redis');
        config::set('redis_port', 1);

        $db = $this->connection();

        $this->assertNull($db->get_cache_stats()['store']);
        $this->assertCount(2, $db->get_results('SELECT * FROM sq_items'));
    }

    public function test_memory_driver_has_no_shared_store(): void
    {
        $this->assertNull($this->connection()->get_cache_stats()['store']);
    }
}
