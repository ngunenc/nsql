<?php

namespace Tests\Portable;

use nsql\security\RateLimiter;
use Tests\Support\PortableTestCase;

class RateLimiterPortableTest extends PortableTestCase
{
    private string $table;

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

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->db, fn (): int => 1_700_000_000, [
            'table' => $this->table,
            'max_requests' => 1,
            'window' => 3600,
        ]);
    }

    /**
     * VARCHAR(255)'e sığmayan kimlik: PostgreSQL'de hata, MySQL'de kesilip ortak önekli kimliklerin
     * aynı kovayı paylaşması. Uzun kimlik özetlenir.
     */
    public function test_long_identifiers_get_separate_buckets(): void
    {
        $limiter = $this->limiter();
        $prefix = str_repeat('Mozilla/5.0 ', 30);

        $this->assertTrue($limiter->check_rate_limit($prefix . 'A'));
        $this->assertTrue($limiter->check_rate_limit($prefix . 'B'));
        $this->assertFalse($limiter->check_rate_limit($prefix . 'A'));
        $this->assertTrue($limiter->check_rate_limit('client', str_repeat('t', 80)));
    }

    public function test_identifiers_are_case_sensitive(): void
    {
        $limiter = $this->limiter();

        $this->assertTrue($limiter->check_rate_limit('User@Example.com'));
        $this->assertTrue($limiter->check_rate_limit('user@example.com'));
        $this->assertFalse($limiter->check_rate_limit('User@Example.com'));
    }
}
