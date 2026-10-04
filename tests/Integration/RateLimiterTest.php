<?php

namespace Tests\Integration;

use nsql\database\security\rate_limiter;
use nsql\database\security\security_manager;
use Tests\Support\DatabaseTestCase;

class RateLimiterTest extends DatabaseTestCase
{
    private string $table;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = 'nsql_rl_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->table}");
        parent::tearDown();
    }

    private function limiter(int $max_requests, int $window, int $burst = 1000): rate_limiter
    {
        return new rate_limiter(
            $this->db,
            fn (): int => $this->now,
            ['table' => $this->table, 'max_requests' => $max_requests, 'window' => $window, 'burst' => $burst]
        );
    }

    /**
     * @return array<bool>
     */
    private function hit(rate_limiter $limiter, int $times, string $id = 'client'): array
    {
        $results = [];
        for ($i = 0; $i < $times; $i++) {
            $results[] = $limiter->check_rate_limit($id);
        }

        return $results;
    }

    public function test_constructor_does_not_touch_database(): void
    {
        $this->limiter(5, 60);

        $this->assertSame([], $this->db->get_results('SHOW TABLES LIKE ' . $this->db->get_pdo()->quote($this->table)));
    }

    public function test_capacity_is_enforced(): void
    {
        $limiter = $this->limiter(3, 60);

        $this->assertSame([true, true, true, false, false], $this->hit($limiter, 5));
    }

    public function test_tokens_refill_gradually_instead_of_resetting(): void
    {
        $limiter = $this->limiter(2, 60);
        $this->hit($limiter, 2);

        $this->now += 1;
        $this->assertFalse($limiter->check_rate_limit('client'), 'Bir saniyede 2/60 token dolar; tam sıfırlama olmamalı');

        $this->now += 29;
        $this->assertSame([true, false], $this->hit($limiter, 2));
    }

    public function test_refill_is_capped_at_capacity(): void
    {
        $limiter = $this->limiter(3, 3);
        $this->hit($limiter, 3);

        $this->now += 1000;
        $this->assertSame([true, true, true, false], $this->hit($limiter, 4));
    }

    public function test_burst_limit_per_second(): void
    {
        $limiter = $this->limiter(100, 60, 2);

        $this->assertSame([true, true, false], $this->hit($limiter, 3));

        $this->now += 1;
        $this->assertSame([true, true, false], $this->hit($limiter, 3));
    }

    public function test_denied_requests_do_not_consume_or_count(): void
    {
        $limiter = $this->limiter(1, 10);
        $this->hit($limiter, 5);

        $row = $this->db->get_row("SELECT tokens, total_requests FROM {$this->table} WHERE identifier = 'client'");
        $this->assertSame(1, (int) $row->total_requests);
        $this->assertEqualsWithDelta(0.0, (float) $row->tokens, 0.0001);

        $this->now += 10;
        $this->assertTrue($limiter->check_rate_limit('client'));
    }

    public function test_identifiers_and_request_types_are_isolated(): void
    {
        $limiter = $this->limiter(1, 60);

        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertFalse($limiter->check_rate_limit('a'));
        $this->assertTrue($limiter->check_rate_limit('b'));
        $this->assertTrue($limiter->check_rate_limit('a', 'login'));
        $this->assertFalse($limiter->check_rate_limit('a', 'login'));
    }

    public function test_state_is_shared_between_instances_and_connections(): void
    {
        $this->hit($this->limiter(2, 60), 1);

        $other_db = new \nsql\database\nsql(
            host: \nsql\database\config::get('db_host', 'localhost'),
            db: \nsql\database\config::get('db_name', 'nsql_test_db'),
            user: \nsql\database\config::get('db_user', 'root'),
            pass: \nsql\database\config::get('db_pass', '')
        );
        $other = new rate_limiter($other_db, fn (): int => $this->now, ['table' => $this->table, 'max_requests' => 2, 'window' => 60]);

        $this->assertSame([true, false], $this->hit($other, 2));
    }

    public function test_row_is_locked_while_another_request_holds_it(): void
    {
        $limiter = $this->limiter(5, 60);
        $limiter->check_rate_limit('locked');

        $other_db = new \nsql\database\nsql(
            host: \nsql\database\config::get('db_host', 'localhost'),
            db: \nsql\database\config::get('db_name', 'nsql_test_db'),
            user: \nsql\database\config::get('db_user', 'root'),
            pass: \nsql\database\config::get('db_pass', '')
        );
        $other_db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $other = new rate_limiter($other_db, fn (): int => $this->now, ['table' => $this->table, 'max_requests' => 5, 'window' => 60]);

        $this->db->begin();
        $this->db->get_row("SELECT tokens FROM {$this->table} WHERE identifier = 'locked' FOR UPDATE");

        $started = microtime(true);
        try {
            $other->check_rate_limit('locked');
            $this->fail('Kilitli satır beklenmeden güncellendi');
        } catch (\PDOException $e) {
            $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started, 'İkinci istek kilidi beklemeli');
            $this->assertStringContainsString('1205', $e->getMessage());
        } finally {
            $this->db->rollback();
        }

        $this->assertSame(0, $other_db->get_transaction_level());
        $this->assertTrue($other->check_rate_limit('locked'));
    }

    public function test_runs_inside_outer_transaction(): void
    {
        $limiter = $this->limiter(1, 60);
        $limiter->install();

        $this->db->begin();
        $this->assertTrue($limiter->check_rate_limit('tx'));
        $this->assertSame(1, $this->db->get_transaction_level());
        $this->db->commit();

        $this->assertFalse($limiter->check_rate_limit('tx'));
    }

    public function test_requires_database(): void
    {
        $this->expectException(\RuntimeException::class);
        (new rate_limiter())->check_rate_limit('x');
    }

    public function test_invalid_table_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new rate_limiter($this->db, null, ['table' => 'x; DROP TABLE users']);
    }

    public function test_security_manager_passes_connection(): void
    {
        $existed = $this->db->get_results("SHOW TABLES LIKE 'rate_limits'") !== [];

        try {
            $manager = new security_manager($this->db);
            $this->assertTrue($manager->check_rate_limit('nsql-sm-test', 'api'));
        } finally {
            if ($existed) {
                $this->db->delete("DELETE FROM rate_limits WHERE identifier = 'nsql-sm-test'");
            } else {
                $this->db->query('DROP TABLE IF EXISTS rate_limits');
            }
        }
    }
}
