<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\MigrationManager;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

class ExampleSeederTest extends TestCase
{
    public function test_example_seeder_runs_through_migration_manager(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        Config::set_environment('testing');
        $root = dirname(__DIR__, 2);
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_seed_' . getmypid() . '.sqlite';
        $db = new Nsql(db: $file, driver: 'sqlite');

        try {
            $db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, password TEXT)');

            (new MigrationManager($db, $root . '/examples/database/migrations', $root . '/examples/database/seeds'))
                ->seed('UserSeeder');

            $this->assertSame(['admin', 'test'], array_column(
                $db->get_results('SELECT username FROM users ORDER BY id', [], \PDO::FETCH_ASSOC),
                'username'
            ));
        } finally {
            (fn () => $this->disconnect())->call($db);
            @unlink($file);
        }
    }

    public function test_demo_seeder_is_not_shipped_in_package(): void
    {
        $this->assertFalse(class_exists('nsql\\database\\seeds\\UserSeeder'));
        $this->assertFalse(class_exists('nsql\\database\\seeds\\user_seeder'));
    }
}
