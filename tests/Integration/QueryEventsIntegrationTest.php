<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\events\QueryEvent;
use nsql\database\Nsql;
use Psr\Log\LogLevel;
use Tests\Support\ArrayLogger;
use Tests\Support\ArrayPsr16Cache;
use Tests\Support\DatabaseTestCase;

/**
 * #52: on_query dinleyicileri, yavaş sorgu logu, PSR-3 logger ve PSR-16 query cache store.
 */
class QueryEventsIntegrationTest extends DatabaseTestCase
{
    private static bool $schema_ready = false;

    /** @var list<Nsql> */
    private array $extra = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schema_ready) {
            $this->db->query('DROP TABLE IF EXISTS ev_items');
            $this->db->query('CREATE TABLE ev_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL
            ) ENGINE=InnoDB');
            self::$schema_ready = true;
        }

        $this->db->query('TRUNCATE TABLE ev_items');
        $this->db->insert('INSERT INTO ev_items (name) VALUES (?), (?)', ['a', 'b']);
    }

    protected function tearDown(): void
    {
        foreach ($this->extra as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->extra = [];
        Config::set('slow_query_threshold_ms', 0);
        Config::set('query_cache_enabled', self::query_cache_suite());

        parent::tearDown();
    }

    private function cached_connection(ArrayPsr16Cache $store): Nsql
    {
        Config::set('query_cache_enabled', true);
        $db = new Nsql(
            host: Config::get('db_host', 'localhost'),
            db: Config::get('db_name', 'nsql_test_db'),
            user: Config::get('db_user', 'root'),
            pass: Config::get('db_pass', '')
        );
        $db->set_query_cache_store($store);
        $this->extra[] = $db;

        return $db;
    }

    /**
     * @return \ArrayObject<int, QueryEvent>
     */
    private function record(Nsql $db): \ArrayObject
    {
        $events = new \ArrayObject();
        $db->on_query(function (QueryEvent $e) use ($events): void {
            $events[] = $e;
        });

        return $events;
    }

    public function test_listener_receives_sql_masked_params_duration_and_rows(): void
    {
        $events = $this->record($this->db);

        $this->db->get_results('SELECT * FROM ev_items WHERE name <> :password', ['password' => 'secret']);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('SELECT * FROM ev_items WHERE name <> :password', $event->sql);
        $this->assertNotSame('secret', $event->params['password']);
        $this->assertGreaterThanOrEqual(0, $event->duration_ms);
        $this->assertSame(2, $event->row_count);
        $this->assertTrue($event->success);
        $this->assertNull($event->error);
        $this->assertSame('mysql', $event->driver);
    }

    public function test_listener_receives_failed_query(): void
    {
        $events = $this->record($this->db);

        $this->db->get_results('SELECT missing_column FROM ev_items');

        $this->assertCount(1, $events);
        $this->assertFalse($events[0]->success);
        $this->assertInstanceOf(\PDOException::class, $events[0]->error);
        $this->assertNull($events[0]->row_count);
    }

    public function test_clear_query_listeners(): void
    {
        $events = $this->record($this->db);
        $this->db->clear_query_listeners();

        $this->db->get_results('SELECT * FROM ev_items');

        $this->assertCount(0, $events);
    }

    public function test_unbuffered_stream_reports_unknown_row_count(): void
    {
        $events = $this->record($this->db);

        iterator_to_array($this->db->get_yield('SELECT * FROM ev_items', [], unbuffered: true));

        $this->assertCount(1, $events);
        $this->assertNull($events[0]->row_count);
        $this->assertTrue($events[0]->success);
    }

    public function test_slow_query_is_logged_to_psr_logger_with_masked_params(): void
    {
        $logger = new ArrayLogger();
        $this->db->set_logger($logger);
        Config::set('slow_query_threshold_ms', 0.000001);

        $this->db->get_row('SELECT * FROM ev_items WHERE name = :token', ['token' => 'abc123']);

        $slow = $logger->matching(LogLevel::WARNING, 'Yavaş sorgu');
        $this->assertCount(1, $slow);
        $this->assertStringContainsString('ev_items', $slow[0]['context']['sql']);
        $this->assertNotSame('abc123', $slow[0]['context']['params']['token']);
        $this->assertArrayHasKey('duration_ms', $slow[0]['context']);
    }

    public function test_no_slow_log_when_threshold_disabled(): void
    {
        $logger = new ArrayLogger();
        $this->db->set_logger($logger);

        $this->db->get_results('SELECT * FROM ev_items');

        $this->assertSame([], $logger->matching(LogLevel::WARNING, 'Yavaş sorgu'));
    }

    public function test_errors_are_routed_to_psr_logger(): void
    {
        $logger = new ArrayLogger();
        $this->db->set_logger($logger);

        $this->db->get_results('SELECT missing_column FROM ev_items');

        $this->assertNotSame([], $logger->matching(LogLevel::ERROR, 'missing_column'));
    }

    public function test_shared_store_serves_other_instances_and_invalidates_on_write(): void
    {
        $store = new ArrayPsr16Cache();
        $writer = $this->cached_connection($store);
        $reader = $this->cached_connection($store);
        $reader_events = $this->record($reader);

        $this->assertCount(2, $writer->get_results('SELECT * FROM ev_items ORDER BY id'));

        $this->assertCount(2, $reader->get_results('SELECT * FROM ev_items ORDER BY id'));
        $this->assertCount(0, $reader_events, 'İkinci örnek sonucu paylaşılan store\'dan almalı');
        $this->assertSame(ArrayPsr16Cache::class, $reader->get_cache_stats()['store']);

        $writer->insert('INSERT INTO ev_items (name) VALUES (?)', ['c']);

        $other = $this->cached_connection($store);
        $this->assertCount(3, $other->get_results('SELECT * FROM ev_items ORDER BY id'));
    }

    public function test_tag_and_global_invalidation_reach_store(): void
    {
        $store = new ArrayPsr16Cache();
        $a = $this->cached_connection($store);
        $this->assertTrue($a->preload_query('SELECT * FROM ev_items', [], ['items']));

        $b = $this->cached_connection($store);
        $b_events = $this->record($b);
        $b->get_results('SELECT * FROM ev_items');
        $this->assertCount(0, $b_events);

        $a->invalidate_cache_by_tag('items');
        $c = $this->cached_connection($store);
        $c_events = $this->record($c);
        $c->get_results('SELECT * FROM ev_items');
        $this->assertCount(1, $c_events);

        $a->invalidate_all_cache();
        $d = $this->cached_connection($store);
        $d_events = $this->record($d);
        $d->get_results('SELECT * FROM ev_items');
        $this->assertCount(1, $d_events);
    }

    public function test_commit_reinvalidates_entries_cached_by_others_during_transaction(): void
    {
        $store = new ArrayPsr16Cache();
        $writer = $this->cached_connection($store);
        $reader = $this->cached_connection($store);

        $writer->begin();
        $writer->statement('UPDATE ev_items SET name = ? WHERE id = 1', ['changed']);

        // Commit öncesi başka bir süreç eski veriyi cache'ler
        $this->assertSame('a', $reader->get_row('SELECT name FROM ev_items WHERE id = 1')->name);

        $writer->commit();

        $fresh = $this->cached_connection($store);
        $this->assertSame('changed', $fresh->get_row('SELECT name FROM ev_items WHERE id = 1')->name);
    }

    public function test_broken_store_does_not_break_queries(): void
    {
        $store = new class () implements \Psr\SimpleCache\CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new \RuntimeException('down');
            }
            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                throw new \RuntimeException('down');
            }
            public function delete(string $key): bool
            {
                return false;
            }
            public function clear(): bool
            {
                return false;
            }
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                throw new \RuntimeException('down');
            }
            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                throw new \RuntimeException('down');
            }
            public function deleteMultiple(iterable $keys): bool
            {
                return false;
            }
            public function has(string $key): bool
            {
                return false;
            }
        };

        Config::set('query_cache_enabled', true);
        $db = new Nsql(
            host: Config::get('db_host', 'localhost'),
            db: Config::get('db_name', 'nsql_test_db'),
            user: Config::get('db_user', 'root'),
            pass: Config::get('db_pass', '')
        );
        $this->extra[] = $db;
        $db->set_query_cache_store($store);

        $this->assertCount(2, $db->get_results('SELECT * FROM ev_items'));
        $db->insert('INSERT INTO ev_items (name) VALUES (?)', ['z']);
        $this->assertCount(3, $db->get_results('SELECT * FROM ev_items'));
    }
}
