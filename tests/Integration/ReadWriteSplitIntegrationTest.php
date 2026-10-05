<?php

namespace Tests\Integration;

use nsql\database\config;
use nsql\database\connection_manager;
use nsql\database\exceptions\QueryException;
use nsql\database\nsql;
use Tests\Support\DatabaseTestCase;

/**
 * #51: isimlendirilmiş bağlantılar ve okuma/yazma ayrımı.
 *
 * Replica olarak primary ile aynı sunucu kullanılır; yönlendirme, okumanın primary'nin
 * PDO'sundan farklı bir oturumda (CONNECTION_ID) çalışmasıyla doğrulanır.
 */
class ReadWriteSplitIntegrationTest extends DatabaseTestCase
{
    private static bool $schema_ready = false;

    /** @var list<nsql> */
    private array $extra = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schema_ready) {
            $this->db->query('DROP TABLE IF EXISTS rw_items');
            $this->db->query('CREATE TABLE rw_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL
            ) ENGINE=InnoDB');
            self::$schema_ready = true;
        }

        $this->db->query('TRUNCATE TABLE rw_items');
        config::set('query_cache_enabled', false);
    }

    protected function tearDown(): void
    {
        foreach ($this->extra as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->extra = [];
        foreach (connection_manager::names() as $name) {
            (fn () => $this->disconnect())->call(connection_manager::get($name));
        }
        connection_manager::reset();
        config::set('query_cache_enabled', self::query_cache_suite());
        config::set('read_write_split', false);
        config::set('read_write_sticky', true);

        parent::tearDown();
    }

    /**
     * @return array{host: string, db: string, user: string, pass: string}
     */
    private static function credentials(): array
    {
        return [
            'host' => (string) config::get('db_host', 'localhost'),
            'db' => (string) config::get('db_name', 'nsql_test_db'),
            'user' => (string) config::get('db_user', 'root'),
            'pass' => (string) config::get('db_pass', ''),
        ];
    }

    private function split_connection(): nsql
    {
        $c = self::credentials();
        $db = new nsql(host: $c['host'], db: $c['db'], user: $c['user'], pass: $c['pass']);
        $db->set_read_replica(['host' => $c['host']]);
        $this->extra[] = $db;

        return $db;
    }

    private static function primary_id(nsql $db): int
    {
        return (int) $db->get_pdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    }

    private static function routed_id(nsql $db, string $suffix = ''): int
    {
        return (int) $db->get_row('SELECT CONNECTION_ID() AS id' . $suffix)->id;
    }

    public function test_reads_go_to_replica_session(): void
    {
        $db = $this->split_connection();

        $this->assertTrue($db->uses_read_replica());
        $this->assertNotSame(self::primary_id($db), self::routed_id($db));
    }

    public function test_transaction_and_locking_reads_stay_on_primary(): void
    {
        $db = $this->split_connection();
        $primary = self::primary_id($db);

        $db->begin();
        $this->assertSame($primary, self::routed_id($db));
        $db->commit();

        $this->assertSame($primary, (int) $db->get_row('SELECT CONNECTION_ID() AS id FROM DUAL FOR UPDATE')->id);
    }

    public function test_write_makes_connection_sticky(): void
    {
        $db = $this->split_connection();
        $primary = self::primary_id($db);
        $this->assertNotSame($primary, self::routed_id($db));

        $db->insert('INSERT INTO rw_items (name) VALUES (?)', ['x']);

        $this->assertSame($primary, self::routed_id($db));
        $this->assertCount(1, $db->get_results('SELECT * FROM rw_items'));

        $db->stick_to_primary(false);
        $this->assertNotSame($primary, self::routed_id($db));
    }

    public function test_sticky_can_be_disabled(): void
    {
        config::set('read_write_sticky', false);
        $db = $this->split_connection();
        $primary = self::primary_id($db);

        $db->insert('INSERT INTO rw_items (name) VALUES (?)', ['x']);

        $this->assertNotSame($primary, self::routed_id($db));
    }

    public function test_replica_errors_surface_on_primary(): void
    {
        $db = $this->split_connection();

        $this->assertSame([], $db->get_results('SELECT * FROM rw_missing_table'));
        $this->assertNotNull($db->get_last_error());

        $db->set_throw_on_error(true);
        $this->expectException(QueryException::class);
        $db->get_results('SELECT * FROM rw_missing_table');
    }

    public function test_streaming_reads_use_replica(): void
    {
        $db = $this->split_connection();
        $primary = self::primary_id($db);

        $ids = [];
        foreach ($db->get_yield('SELECT CONNECTION_ID() AS id') as $row) {
            $ids[] = (int) $row->id;
        }

        $this->assertCount(1, $ids);
        $this->assertNotSame($primary, $ids[0]);
    }

    public function test_unreachable_replica_falls_back_to_primary(): void
    {
        $c = self::credentials();
        $db = new nsql(host: $c['host'], db: $c['db'], user: $c['user'], pass: $c['pass']);
        $this->extra[] = $db;
        $db->set_read_replica(['host' => $c['host'], 'user' => 'nsql_no_such_user', 'pass' => 'x']);
        $primary = self::primary_id($db);

        $this->assertSame($primary, self::routed_id($db));
        $this->assertFalse($db->uses_read_replica());
    }

    public function test_env_configuration_enables_split(): void
    {
        $c = self::credentials();
        config::set('read_write_split', true);
        config::set('db_read_host', $c['host'] . ', ' . $c['host']);

        try {
            $db = new nsql(host: $c['host'], db: $c['db'], user: $c['user'], pass: $c['pass']);
            $this->extra[] = $db;

            $this->assertTrue($db->uses_read_replica());
            $this->assertNotSame(self::primary_id($db), self::routed_id($db));
        } finally {
            config::set('db_read_host', '');
        }
    }

    public function test_named_connections_are_independent(): void
    {
        $c = self::credentials();
        connection_manager::add('reporting', $c);

        $main = nsql::connection();
        $reporting = nsql::connection('reporting');

        $this->assertSame($main, nsql::connection('default'));
        $this->assertSame($reporting, nsql::connection('REPORTING'));
        $this->assertNotSame($main, $reporting);
        $this->assertNotSame(self::primary_id($main), self::primary_id($reporting));

        $main->begin();
        $main->insert('INSERT INTO rw_items (name) VALUES (?)', ['uncommitted']);
        $this->assertSame(1, $main->get_transaction_level());
        $this->assertSame(0, $reporting->get_transaction_level());
        $this->assertSame(0, (int) $reporting->get_row('SELECT COUNT(*) AS c FROM rw_items')->c);

        $reporting->get_results('SELECT * FROM rw_missing_table');
        $this->assertNotNull($reporting->get_last_error());

        $main->rollback();
        $this->assertSame(0, (int) $main->get_row('SELECT COUNT(*) AS c FROM rw_items')->c);
    }

    public function test_named_connection_from_env(): void
    {
        $c = self::credentials();
        config::set('db_analytics_host', $c['host']);
        config::set('db_analytics_name', $c['db']);

        $this->assertTrue(connection_manager::has('analytics'));
        $db = nsql::connection('analytics');
        $this->assertSame(1, (int) $db->get_row('SELECT 1 AS one')->one);
    }

    public function test_unknown_named_connection_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        nsql::connection('nsql_unknown_conn');
    }

    public function test_add_rejects_unknown_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        connection_manager::add('bad', ['hostname' => 'x']);
    }
}
