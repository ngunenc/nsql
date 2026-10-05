<?php

namespace Tests\Portable;

use nsql\database\security\rate_limiter;
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

    private function limiter(int $max_requests, int $window): rate_limiter
    {
        return new rate_limiter(
            $this->db,
            fn (): int => $this->now,
            ['table' => $this->table, 'max_requests' => $max_requests, 'window' => $window, 'burst' => 1000]
        );
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
