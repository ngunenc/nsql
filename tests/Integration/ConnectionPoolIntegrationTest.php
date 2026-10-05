<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\ConnectionPool;
use nsql\database\exceptions\ConnectionException;
use nsql\database\Nsql;
use PDO;
use Tests\Support\DatabaseTestCase;

/**
 * #30 bağlantı havuzu (DSN başına havuz, süreç içi state) ve #31 kopan bağlantıyı yenileme.
 */
class ConnectionPoolIntegrationTest extends DatabaseTestCase
{
    private const ALT_DB = 'nsql_test_db_alt';

    private function credentials(): array
    {
        return [
            'host' => (string) Config::get('db_host', 'localhost'),
            'db' => (string) Config::get('db_name', 'nsql_test_db'),
            'user' => (string) Config::get('db_user', 'root'),
            'pass' => (string) Config::get('db_pass', ''),
        ];
    }

    private function new_db(?string $db = null): Nsql
    {
        $c = $this->credentials();

        return new Nsql(host: $c['host'], db: $db ?? $c['db'], user: $c['user'], pass: $c['pass']);
    }

    /**
     * Havuzdan bağımsız yönetici bağlantısı (KILL için).
     */
    private function admin_pdo(): PDO
    {
        $c = $this->credentials();
        $port = (int) Config::get('db_port', 3306);

        return new PDO("mysql:host={$c['host']};port={$port}", $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function connection_id(Nsql $db): int
    {
        return (int) $db->get_row('SELECT CONNECTION_ID() AS id')->id;
    }

    private function kill(int $connection_id): void
    {
        $this->admin_pdo()->exec('KILL ' . $connection_id);
        usleep(100000);
    }

    public function testInstanceUsesSingleConnectionAndReleasedOneIsReused(): void
    {
        $created_before = ConnectionPool::get_stats()['created_connections'];
        $active_before = $this->db->get_instance_pool_stats()['active_connections'];

        $a = $this->new_db();
        $a->get_results('SELECT 1 AS x');
        $a->get_results('SELECT 2 AS x');
        $this->assertSame($active_before + 1, $a->get_instance_pool_stats()['active_connections']);

        $created_after_a = ConnectionPool::get_stats()['created_connections'];
        $this->assertLessThanOrEqual($created_before + 1, $created_after_a, 'Bir nsql örneği en fazla bir fiziksel bağlantı açmalı');

        // Xdebug develop modunda unset() yıkıcıyı hemen tetiklemeyebilir.
        (fn () => $this->disconnect())->call($a);
        unset($a);

        $b = $this->new_db();
        $b->get_results('SELECT 1 AS x');
        $this->assertSame($created_after_a, ConnectionPool::get_stats()['created_connections'], 'Bırakılan bağlantı yeniden kullanılmalı');
    }

    public function testDifferentDatabasesUseSeparatePools(): void
    {
        try {
            $this->admin_pdo()->exec('CREATE DATABASE IF NOT EXISTS `' . self::ALT_DB . '`');
        } catch (\PDOException $e) {
            $this->markTestSkipped('İkinci test veritabanı oluşturulamadı: ' . $e->getMessage());
        }

        try {
            $alt = $this->new_db(self::ALT_DB);

            $this->assertSame($this->credentials()['db'], $this->db->get_row('SELECT DATABASE() AS d')->d);
            $this->assertSame(self::ALT_DB, $alt->get_row('SELECT DATABASE() AS d')->d);

            $main_pool = $this->db->get_instance_pool_stats()['pools'];
            $alt_pool = $alt->get_instance_pool_stats()['pools'];
            $this->assertNotSame(array_keys($main_pool), array_keys($alt_pool));
            $this->assertGreaterThanOrEqual(2, ConnectionPool::get_stats()['pool_count']);

            unset($alt);
        } finally {
            $this->admin_pdo()->exec('DROP DATABASE IF EXISTS `' . self::ALT_DB . '`');
        }
    }

    public function testPoolDoesNotCreateLockFile(): void
    {
        $lock_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_connection_pool.lock';
        if (file_exists($lock_file)) {
            @unlink($lock_file);
        }

        $db = $this->new_db();
        $db->get_results('SELECT 1 AS x');
        unset($db);

        $this->assertFileDoesNotExist($lock_file);
    }

    public function testReconnectsAfterConnectionIsKilled(): void
    {
        $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'before']);
        $this->db->get_row('SELECT name FROM test_table WHERE name = :name', ['name' => 'before']);

        $old_id = $this->connection_id($this->db);
        $this->kill($old_id);

        $row = $this->db->get_row('SELECT name FROM test_table WHERE name = :name', ['name' => 'before']);
        $this->assertNotNull($row);
        $this->assertSame('before', $row->name);
        $this->assertNotSame($old_id, $this->connection_id($this->db));
    }

    private function questions(Nsql $db): int
    {
        return (int) $db->get_row("SHOW SESSION STATUS LIKE 'Questions'")->Value;
    }

    public function testQueriesDoNotPingBeforeEachExecution(): void
    {
        $before = $this->questions($this->db);
        for ($i = 0; $i < 3; $i++) {
            $this->db->get_row('SELECT 1 AS x');
        }
        $after = $this->questions($this->db);

        // 3 SELECT + sondaki SHOW; her sorgudan önce SELECT 1 atılsaydı fark 7 olurdu
        $this->assertSame(4, $after - $before);
    }

    public function testIdlePingThresholdIsConfigurable(): void
    {
        Config::set('connection_ping_idle_seconds', 0);
        try {
            $before = $this->questions($this->db);
            $this->db->get_row('SELECT 1 AS x');
            $after = $this->questions($this->db);

            // SELECT 1 ping + SELECT + ping + SHOW
            $this->assertSame(4, $after - $before);
        } finally {
            Config::set('connection_ping_idle_seconds', 30);
        }
    }

    public function testBeginReconnectsAfterConnectionIsKilled(): void
    {
        $this->db->get_row('SELECT 1 AS x');
        $old_id = $this->connection_id($this->db);
        $this->kill($old_id);

        $this->db->begin();
        $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'after_kill']);
        $this->db->commit();

        $this->assertNotSame($old_id, $this->connection_id($this->db));
        $this->assertNotNull($this->db->get_row('SELECT id FROM test_table WHERE name = :name', ['name' => 'after_kill']));
    }

    public function testExplicitReconnectReplacesConnection(): void
    {
        $old_pdo = $this->db->get_pdo();

        $this->db->reconnect();

        $this->assertNotSame($old_pdo, $this->db->get_pdo());
        $this->assertSame(1, (int) $this->db->get_row('SELECT 1 AS x')->x);
    }

    public function testConnectionLossInsideTransactionThrows(): void
    {
        $this->db->begin();
        $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'in_tx']);

        $this->kill($this->connection_id($this->db));

        try {
            $this->db->insert('INSERT INTO test_table (name) VALUES (:name)', ['name' => 'in_tx_2']);
            $this->fail('Transaction içinde bağlantı kaybı exception fırlatmalı');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('Transaction', $e->getMessage());
        }

        $this->assertSame(0, $this->db->get_transaction_level());
        $this->assertSame([], $this->db->get_results("SELECT id FROM test_table WHERE name LIKE 'in_tx%'"));
    }
}
