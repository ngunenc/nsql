<?php

namespace Tests\Portable;

use nsql\security\RateLimiter;
use Tests\Support\PortableTestCase;

class RateLimiterPortableTest extends PortableTestCase
{
    private string $table;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = 'p_rl_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->table}");
        parent::tearDown();
    }

    private function limiter(int $max_requests, int $window, int $purge_probability = 0): RateLimiter
    {
        return new RateLimiter(
            $this->db,
            fn (): int => $this->now,
            [
                'table' => $this->table,
                'max_requests' => $max_requests,
                'window' => $window,
                'burst' => 1000,
                'purge_probability' => $purge_probability,
            ]
        );
    }

    private function identifiers(): array
    {
        $rows = $this->db->get_results("SELECT identifier FROM {$this->table} ORDER BY identifier");

        return array_map(fn ($r) => $r->identifier, $rows);
    }

    public function test_purge_removes_only_stale_rows(): void
    {
        $limiter = $this->limiter(5, 10);
        $limiter->check_rate_limit('eski');
        $this->now += 25;
        $limiter->check_rate_limit('yeni');

        $this->assertSame(1, $limiter->purge(20));
        $this->assertSame(['yeni'], $this->identifiers());
        $this->assertSame(0, $limiter->purge(20));

        // Pencereden kısa süre verilse de pencere kadar beklenir (dolmamış kova silinmez)
        $this->now += 5;
        $this->assertSame(0, $limiter->purge(1));
        $this->now += 30;
        $this->assertSame(1, $limiter->purge());
    }

    public function test_probabilistic_purge_on_check(): void
    {
        $limiter = $this->limiter(5, 10, purge_probability: 100);
        $limiter->check_rate_limit('eski');
        $this->now += 100;
        $limiter->check_rate_limit('yeni');

        $this->assertSame(['yeni'], $this->identifiers());
    }

    public function test_capacity_refill_and_separate_identifiers(): void
    {
        $limiter = $this->limiter(2, 10);

        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertFalse($limiter->check_rate_limit('a'));
        $this->assertTrue($limiter->check_rate_limit('b'));

        $this->now += 5;
        $this->assertTrue($limiter->check_rate_limit('a'));
        $this->assertFalse($limiter->check_rate_limit('a'));
    }
}
