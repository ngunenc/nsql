<?php

namespace Tests\Integration;

use nsql\database\cache\AdapterSimpleCache;
use nsql\database\Config;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;
use Tests\Support\JsonCacheAdapter;

/**
 * Ortak PSR-16 store kullanan farklı veritabanı bağlantıları birbirinin cache kaydını okumamalı.
 */
class SharedQueryCacheIsolationTest extends TestCase
{
    /** @var list<Nsql> */
    private array $connections = [];
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        Config::set_environment('testing');
        Config::set_project_root(dirname(__DIR__, 2));
        Config::set('query_cache_enabled', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->connections = [];
        foreach ($this->files as $file) {
            @unlink($file);
        }
        Config::set('query_cache_enabled', false);
    }

    private function database(string $name): Nsql
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_iso_' . $name . '_' . getmypid() . '.sqlite';
        @unlink($file);
        $this->files[] = $file;

        $db = new Nsql(db: $file, driver: 'sqlite');
        $this->connections[] = $db;
        $db->query('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $db->insert('INSERT INTO items (name) VALUES (?)', [$name]);

        return $db;
    }

    public function test_same_sql_on_different_databases_does_not_share_entries(): void
    {
        $store = new AdapterSimpleCache(new JsonCacheAdapter());
        $tenant_a = $this->database('tenant_a')->set_query_cache_store($store);
        $tenant_b = $this->database('tenant_b')->set_query_cache_store($store);

        $this->assertSame('tenant_a', $tenant_a->get_row('SELECT name FROM items WHERE id = 1')->name);
        $this->assertSame('tenant_b', $tenant_b->get_row('SELECT name FROM items WHERE id = 1')->name);
    }

    public function test_same_database_still_shares_entries(): void
    {
        $store = new AdapterSimpleCache(new JsonCacheAdapter());
        $writer = $this->database('shared')->set_query_cache_store($store);
        $writer->get_results('SELECT name FROM items');

        $file = end($this->files);
        $reader = new Nsql(db: $file, driver: 'sqlite');
        $this->connections[] = $reader;
        $reader->set_query_cache_store($store);
        $queries = 0;
        $reader->on_query(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame('shared', $reader->get_results('SELECT name FROM items')[0]->name);
        $this->assertSame(0, $queries);
    }
}
