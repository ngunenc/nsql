<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

/**
 * READ_WRITE_STICKY_SECONDS: yazmadan sonra primary'ye yapışma süresi (#90).
 *
 * Primary ve replica ayrı SQLite dosyalarıdır; okunan değer sorgunun nereye gittiğini gösterir.
 */
class ReadWriteStickySecondsTest extends TestCase
{
    private string $primary_file;
    private string $replica_file;

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
        $suffix = bin2hex(random_bytes(6));
        $this->primary_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "nsql_sticky_primary_{$suffix}.sqlite";
        $this->replica_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "nsql_sticky_replica_{$suffix}.sqlite";
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->connections = [];
        Config::set('read_write_sticky_seconds', Config::read_write_sticky_seconds);
        @unlink($this->primary_file);
        @unlink($this->replica_file);
    }

    private function database(string $file, string $name): Nsql
    {
        $db = new Nsql(db: $file, driver: 'sqlite');
        $this->connections[] = $db;
        $db->query('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $db->insert('INSERT INTO items (name) VALUES (?)', [$name]);

        return $db;
    }

    private function split_db(): Nsql
    {
        $this->database($this->replica_file, 'replica');
        $db = $this->database($this->primary_file, 'primary');
        $db->set_read_replica(['host' => 'replica', 'db' => $this->replica_file]);

        return $db;
    }

    private static function read(Nsql $db): string
    {
        return (string) $db->get_row('SELECT name FROM items WHERE id = 1')?->name;
    }

    private static function age_sticky(Nsql $db, float $seconds): void
    {
        (function () use ($seconds): void {
            $this->sticky_since = microtime(true) - $seconds;
        })->call($db);
    }

    public function test_default_keeps_primary_for_instance_lifetime(): void
    {
        $db = $this->split_db();
        $this->assertSame('replica', self::read($db));

        $db->query("UPDATE items SET name = 'primary-2' WHERE id = 1");
        $this->assertSame('primary-2', self::read($db));

        self::age_sticky($db, 100_000);
        $this->assertSame('primary-2', self::read($db), 'READ_WRITE_STICKY_SECONDS=0: süresiz');
    }

    public function test_reads_return_to_replica_after_sticky_seconds(): void
    {
        Config::set('read_write_sticky_seconds', 10);
        $db = $this->split_db();

        $db->query("UPDATE items SET name = 'primary-2' WHERE id = 1");
        self::age_sticky($db, 5);
        $this->assertSame('primary-2', self::read($db));

        self::age_sticky($db, 11);
        $this->assertSame('replica', self::read($db));

        // Yeni yazma süreyi yeniden başlatır
        $db->query("UPDATE items SET name = 'primary-3' WHERE id = 1");
        $this->assertSame('primary-3', self::read($db));
    }

    public function test_manual_stick_does_not_expire(): void
    {
        Config::set('read_write_sticky_seconds', 1);
        $db = $this->split_db();

        $db->stick_to_primary();
        usleep(1_100_000); // READ_WRITE_STICKY_SECONDS=1 geçer; elle sabitleme süresizdir
        $this->assertSame('primary', self::read($db));

        $db->stick_to_primary(false);
        $this->assertSame('replica', self::read($db));
    }
}
