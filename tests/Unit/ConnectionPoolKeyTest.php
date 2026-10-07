<?php

namespace Tests\Unit;

use nsql\database\ConnectionPool;
use PHPUnit\Framework\TestCase;

class ConnectionPoolKeyTest extends TestCase
{
    private string $dsn;

    protected function setUp(): void
    {
        // Benzersiz DSN: diğer testlerin havuzlarıyla karışmaz; bağlantı açılmaz
        $this->dsn = 'sqlite:' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_key_' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    private function pool(string $password): string
    {
        return ConnectionPool::initialize(
            ['dsn' => $this->dsn, 'username' => 'app', 'password' => $password, 'options' => []],
            0,
            2
        );
    }

    public function test_pools_differing_only_by_password_stay_separate_and_share_label(): void
    {
        $first = $this->pool('secret-1');
        $second = $this->pool('secret-2');

        $this->assertNotSame($first, $second);
        $this->assertSame($first, $this->pool('secret-1'));

        $label = substr(hash('sha256', $this->dsn . "\0app"), 0, 12);
        $first_labels = array_keys(ConnectionPool::get_stats($first)['pools']);
        $second_labels = array_keys(ConnectionPool::get_stats($second)['pools']);

        $this->assertSame([$label], $first_labels);
        $this->assertSame([$label], $second_labels);
    }

    public function test_label_and_key_are_not_derivable_from_password_hash(): void
    {
        $key = $this->pool('hunter2');
        $plain_hash = hash('sha256', implode("\0", [$this->dsn, 'app', 'hunter2', serialize([])]));

        $this->assertNotSame($plain_hash, $key);
        foreach (array_keys(ConnectionPool::get_stats()['pools']) as $label) {
            $this->assertStringStartsNotWith(substr($plain_hash, 0, 12), (string) $label);
        }
    }

    public function test_colliding_labels_are_disambiguated_in_totals(): void
    {
        $this->pool('a');
        $this->pool('b');

        $label = substr(hash('sha256', $this->dsn . "\0app"), 0, 12);
        $labels = array_keys(ConnectionPool::get_stats()['pools']);

        $this->assertContains($label, $labels);
        $this->assertContains($label . '-2', $labels);
    }
}
