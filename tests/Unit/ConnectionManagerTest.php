<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\ConnectionManager;
use PHPUnit\Framework\TestCase;

class ConnectionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        Config::set_environment('testing');
        Config::set_project_root(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        ConnectionManager::reset();
    }

    /**
     * options ve dsn_params Nsql constructor'ının desteklediği ayarlardı, ama add() bunları reddediyordu.
     */
    public function test_pdo_options_are_passed_to_connection(): void
    {
        ConnectionManager::add('upper', [
            'driver' => 'sqlite',
            'db' => ':memory:',
            'options' => [\PDO::ATTR_CASE => \PDO::CASE_UPPER],
            'dsn_params' => [],
        ]);

        $row = ConnectionManager::get('upper')->get_row('SELECT 1 AS lower_name');

        $this->assertSame(1, (int) ($row->LOWER_NAME ?? 0));
    }

    public function test_unknown_keys_are_still_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConnectionManager::add('typo', ['driver' => 'sqlite', 'optons' => []]);
    }
}
