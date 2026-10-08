<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\security\RateLimiter;
use nsql\security\RateLimiterInterface;
use nsql\security\RedisRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * RedisRateLimiter: argümanlar, anahtar, hata yolları ve fabrika (#111). Lua betiğinin davranışı
 * gerçek Redis ile tests/Integration/RedisRateLimiterIntegrationTest'te doğrulanır.
 */
class RedisRateLimiterTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('RATE_LIMIT_DRIVER', Config::rate_limit_driver);
    }

    /**
     * eval() çağrılarını kaydeden phpredis benzeri istemci.
     */
    private static function client(mixed $result = 1): object
    {
        return new class ($result) {
            /** @var list<array{0: string, 1: array<int, string>, 2: int}> */
            public array $calls = [];

            public function __construct(private mixed $result)
            {
            }

            public function eval(string $script, array $args = [], int $num_keys = 0): mixed
            {
                $this->calls[] = [$script, $args, $num_keys];

                return $this->result instanceof \Throwable ? throw $this->result : $this->result;
            }

            public function getLastError(): ?string
            {
                return 'NOSCRIPT test';
            }
        };
    }

    public function test_passes_bucket_parameters_to_script(): void
    {
        $client = self::client(1);
        $limiter = new RedisRateLimiter($client, fn (): int => 1_700_000_000, ['max_requests' => 30, 'window' => 60, 'burst' => 5]);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        $this->assertTrue($limiter->check_rate_limit('203.0.113.5', 'api'));

        [$script, $args, $num_keys] = $client->calls[0];
        $this->assertSame(1, $num_keys);
        $this->assertStringContainsString("redis.call('HMGET'", $script);
        $this->assertSame('nsql_rl_api:203.0.113.5', $args[0]);
        $this->assertSame(['30', '0.500000000000', '5', '1700000000', '120'], array_slice($args, 1, 5));
        $this->assertSame(0.5, $limiter->refill_rate());
    }

    public function test_uses_server_time_without_clock(): void
    {
        $client = self::client(0);
        $limiter = new RedisRateLimiter($client);

        $this->assertFalse($limiter->check_rate_limit('a'));
        $this->assertSame('', $client->calls[0][1][4], 'Saat yoksa Lua TIME kullanır');
        $this->assertStringContainsString("redis.call('TIME')", $client->calls[0][0]);
    }

    public function test_keys_are_encoded_and_long_identifiers_hashed(): void
    {
        $limiter = new RedisRateLimiter(self::client(), null, ['prefix' => 'app_rl_']);

        $this->assertSame('app_rl_login:user%3Aal%C4%B1', $limiter->key('user:alı', 'login'));
        $long = str_repeat('x', 200);
        $this->assertSame('app_rl_default:h_' . hash('sha256', $long), $limiter->key($long));
        $this->assertSame(0, $limiter->purge(3600), 'Redis kayıtları TTL ile silinir');
    }

    public function test_errors_are_reported(): void
    {
        $limiter = new RedisRateLimiter(self::client(false));
        try {
            $limiter->check_rate_limit('a');
            $this->fail('false sonuç RuntimeException olmalı');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NOSCRIPT test', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bağlantı koptu');
        // phpredis RedisException fırlatır; eklenti olmadan da çalışsın diye genel exception
        (new RedisRateLimiter(self::client(new \RuntimeException('bağlantı koptu'))))->check_rate_limit('a');
    }

    public function test_empty_prefix_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RedisRateLimiter(self::client(), null, ['prefix' => '']);
    }

    public function test_factory_selects_driver(): void
    {
        Config::set('RATE_LIMIT_DRIVER', 'database');
        $this->assertInstanceOf(RateLimiter::class, RateLimiter::create());

        Config::set('RATE_LIMIT_DRIVER', 'memcached');
        $this->expectException(\InvalidArgumentException::class);
        RateLimiter::create();
    }
}
