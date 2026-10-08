<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\ConnectionPool;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArrayLogger;

/**
 * Replica bağlantısı çalışma sırasında koptuğunda okumalar primary'ye düşer (#105).
 *
 * SQLite ile: replica aynı veritabanı dosyasıdır; kopma, reader'ın bağlantısının bırakılıp
 * havuzunun açılamayan bir DSN'e yönlendirilmesiyle taklit edilir.
 */
class ReadReplicaFallbackTest extends TestCase
{
    private string $file;

    /** @var list<Nsql> */
    private array $connections = [];

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        Config::set_environment('testing');
        Config::set_project_root(dirname(__DIR__, 2));
        Config::set('query_cache_enabled', false);
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_replica_fallback_' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->connections = [];
        @unlink($this->file);
    }

    private function primary_with_replica(ArrayLogger $logger): Nsql
    {
        $db = new Nsql(db: $this->file, driver: 'sqlite');
        $this->connections[] = $db;
        $db->set_logger($logger);
        $db->query('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $db->insert('INSERT INTO items (name) VALUES (?)', ['kalem']);
        $db->set_read_replica(['host' => 'replica', 'db' => $this->file]);

        return $db;
    }

    private static function break_reader(Nsql $db): void
    {
        $reader = (fn () => $this->reader)->call($db);
        self::assertInstanceOf(Nsql::class, $reader, 'Okuma replica üzerinden yapılmış olmalı');

        $dead_pool = ConnectionPool::initialize([
            'dsn' => 'sqlite:' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_missing_dir_' . bin2hex(random_bytes(4))
                . DIRECTORY_SEPARATOR . 'replica.sqlite',
            'username' => '',
            'password' => '',
            'options' => [],
        ], 0, 1);
        (function () use ($dead_pool): void {
            $this->pdo = null;
            $this->pool_key = $dead_pool;
        })->call($reader);
    }

    public function test_read_falls_back_to_primary_when_replica_is_lost(): void
    {
        $logger = new ArrayLogger();
        $db = $this->primary_with_replica($logger);

        $this->assertSame('kalem', $db->get_row('SELECT name FROM items WHERE id = 1')?->name);
        self::break_reader($db);

        $this->assertSame('kalem', $db->get_row('SELECT name FROM items WHERE id = 1')?->name);
        $this->assertFalse($db->uses_read_replica());
        $this->assertNotEmpty($logger->matching('warning', 'replica'));
        $this->assertCount(1, $db->get_results('SELECT name FROM items'));
    }

    public function test_replica_can_be_re_enabled(): void
    {
        $db = $this->primary_with_replica(new ArrayLogger());
        $db->get_row('SELECT name FROM items WHERE id = 1');
        self::break_reader($db);
        $db->get_row('SELECT name FROM items WHERE id = 1');

        $db->set_read_replica(['host' => 'replica', 'db' => $this->file]);
        $this->assertTrue($db->uses_read_replica());
        $this->assertSame('kalem', $db->get_row('SELECT name FROM items WHERE id = 1')?->name);
        $this->assertNotNull((fn () => $this->reader)->call($db));
    }
}
