<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArrayPsr16Cache;

/**
 * Query cache tutarlılığı: süreç içi kaydın paylaşılan store'a göre doğrulanması (#103),
 * tablo bağımlılıkları (#104), satır sınırı (#107).
 */
class QueryCacheConsistencyTest extends TestCase
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
        Config::set('query_cache_enabled', true);
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_qc_consistency_' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->connections = [];
        Config::set('query_cache_enabled', false);
        Config::set('query_cache_local_verify', Config::query_cache_local_verify);
        Config::set('query_cache_max_rows', null); // null = QUERY_CACHE_SIZE_LIMIT'e düş
        @unlink($this->file);
    }

    private function connect(): Nsql
    {
        $db = new Nsql(db: $this->file, driver: 'sqlite');
        $this->connections[] = $db;

        return $db;
    }

    private function seed(Nsql $db): void
    {
        $db->query('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $db->query('DELETE FROM users');
        $db->insert('INSERT INTO users (name) VALUES (?)', ['ayse']);
    }

    /**
     * Ayrı süreçleri taklit eder: aynı veritabanı ve store, ayrı Nsql örnekleri (ayrı L1 cache).
     *
     * @return array{0: Nsql, 1: Nsql}
     */
    private function two_processes(): array
    {
        $store = new ArrayPsr16Cache();
        $worker = $this->connect()->set_query_cache_store($store);
        $writer = $this->connect()->set_query_cache_store($store);
        $this->seed($writer);

        return [$worker, $writer];
    }

    public function test_local_hit_is_rejected_after_other_process_invalidates(): void
    {
        [$worker, $writer] = $this->two_processes();
        $sql = 'SELECT name FROM users WHERE id = 1';

        $this->assertSame('ayse', $worker->get_row($sql)?->name);
        $writer->update('UPDATE users SET name = ? WHERE id = 1', ['fatma']);

        $this->assertSame('fatma', $worker->get_row($sql)?->name);
    }

    public function test_local_verify_can_be_disabled(): void
    {
        Config::set('query_cache_local_verify', false);
        [$worker, $writer] = $this->two_processes();
        $sql = 'SELECT name FROM users WHERE id = 1';

        $this->assertSame('ayse', $worker->get_row($sql)?->name);
        $writer->update('UPDATE users SET name = ? WHERE id = 1', ['fatma']);

        // Doğrulama kapalı: TTL dolana kadar süreç içi kayıt döner (2.2 davranışı)
        $this->assertSame('ayse', $worker->get_row($sql)?->name);
    }

    public function test_dependency_invalidates_view_cache(): void
    {
        $db = $this->connect();
        $this->seed($db);
        $db->query('CREATE VIEW IF NOT EXISTS v_users AS SELECT id, name FROM users');
        $sql = 'SELECT name FROM v_users WHERE id = 1';

        $this->assertSame('ayse', $db->get_row($sql)?->name);
        $db->update('UPDATE users SET name = ? WHERE id = 1', ['fatma']);
        $this->assertSame('ayse', $db->get_row($sql)?->name, 'Bağımlılık tanımsızken view kaydı bayat kalır');

        $db->set_cache_dependency('v_users', ['users']);
        $db->update('UPDATE users SET name = ? WHERE id = 1', ['zeynep']);
        $this->assertSame('zeynep', $db->get_row($sql)?->name);
        $this->assertSame(['users' => ['v_users']], $db->get_cache_dependencies());
    }

    public function test_dependencies_are_transitive(): void
    {
        $db = $this->connect();
        $db->set_cache_dependency('order_items', ['orders']);
        $db->set_cache_dependency('v_order_totals', ['order_items']);

        $tables = (fn () => $this->with_cache_dependents(['ORDERS']))->call($db);
        sort($tables);

        $this->assertSame(['order_items', 'orders', 'v_order_totals'], $tables);
    }

    public function test_max_rows_is_separate_from_entry_limit(): void
    {
        $db = $this->connect();
        $this->seed($db);
        $db->insert('INSERT INTO users (name) VALUES (?), (?)', ['ali', 'veli']);
        $sql = 'SELECT name FROM users ORDER BY id';

        Config::set('query_cache_max_rows', 2);
        $db->get_results($sql);
        $this->assertSame(0, $db->get_cache_stats()['size'], '3 satır > QUERY_CACHE_MAX_ROWS=2: cache\'lenmez');

        Config::set('query_cache_max_rows', 3);
        $db->get_results($sql);
        $this->assertSame(1, $db->get_cache_stats()['size']);
    }
}
