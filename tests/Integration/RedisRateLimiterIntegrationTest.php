<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\Nsql;
use nsql\security\RateLimiter;
use nsql\security\RedisRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * RedisRateLimiter Lua betiği gerçek Redis'te (#111). Redis yoksa atlanır (CI'da Redis servisi var).
 */
class RedisRateLimiterIntegrationTest extends TestCase
{
    private object $redis;
    private string $prefix;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis eklentisi yok');
        }

        $redis = new \Redis();
        try {
            if (! @$redis->connect((string) Config::get('redis_host', '127.0.0.1'), (int) Config::get('redis_port', 6379), 1.0)) {
                $this->markTestSkipped('Redis sunucusu yok');
            }
        } catch (\RedisException) {
            $this->markTestSkipped('Redis sunucusu yok');
        }

        $this->redis = $redis;
        $this->prefix = 'nsql_rl_test_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = $this->redis->keys($this->prefix . '*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        }
    }

    /**
     * @param array<string, int> $options
     */
    private function limiter(array $options, bool $clock = true): RedisRateLimiter
    {
        return new RedisRateLimiter($this->redis, $clock ? fn (): int => $this->now : null, ['prefix' => $this->prefix] + $options);
    }

    public function test_capacity_refill_and_separate_identifiers(): void
    {
        $limiter = $this->limiter(['max_requests' => 2, 'window' => 10, 'burst' => 1000]);

        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertFalse($limiter->check_rate_limit('a'));
        $this->assertTrue($limiter->check_rate_limit('b'));

        $this->now += 5;
        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertFalse($limiter->check_rate_limit('a'));

        $ttl = $this->redis->ttl($limiter->key('a'));
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(20, $ttl);
    }

    public function test_burst_limit_per_second(): void
    {
        $limiter = $this->limiter(['max_requests' => 100, 'window' => 60, 'burst' => 2]);

        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertFalse($limiter->check_rate_limit('a'));

        $this->now++;
        $this->assertTrue($limiter->check_rate_limit('a'));
    }

    public function test_server_time_is_used_without_clock(): void
    {
        $limiter = $this->limiter(['max_requests' => 1, 'window' => 3600, 'burst' => 10], clock: false);

        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertFalse($limiter->check_rate_limit('a'));
    }

    public function test_same_decisions_as_database_limiter(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite yok');
        }
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_rl_parity_' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Nsql(db: $file, driver: 'sqlite');

        try {
            $options = ['max_requests' => 3, 'window' => 9, 'burst' => 2];
            $database = new RateLimiter($db, fn (): int => $this->now, $options + ['table' => 'rl_parity']);
            $redis = $this->limiter($options);

            $steps = [0, 0, 0, 1, 0, 1, 2, 0, 5, 0, 0, 0, 30, 0, 0, 0, 0];
            $from_db = [];
            $from_redis = [];
            foreach ($steps as $advance) {
                $this->now += $advance;
                $from_db[] = $database->check_rate_limit('ip');
                $from_redis[] = $redis->check_rate_limit('ip');
            }

            $this->assertSame($from_db, $from_redis);
            $this->assertContains(false, $from_db, 'Dizi sınırlamayı da sınamalı');
        } finally {
            (fn () => $this->disconnect())->call($db);
            @unlink($file);
        }
    }
}
