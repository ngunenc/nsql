<?php

namespace Tests\Support;

use nsql\database\nsql;
use PHPUnit\Framework\TestCase;

/**
 * MySQL entegrasyon testleri için ortak kurulum.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected ?nsql $db = null;
    private static bool $migrated = false;

    protected function setUp(): void
    {
        \nsql\database\config::set_environment('testing');
        \nsql\database\config::set_project_root(dirname(__DIR__, 2));

        $this->db = new nsql(
            host: \nsql\database\config::get('db_host', 'localhost'),
            db: \nsql\database\config::get('db_name', 'nsql_test_db'),
            user: \nsql\database\config::get('db_user', 'root'),
            pass: \nsql\database\config::get('db_pass', '')
        );

        if (! self::$migrated) {
            $this->runMigrations();
            self::$migrated = true;
        }

        try {
            $this->db->query('TRUNCATE TABLE test_table');
        } catch (\Exception $e) {
            // tablo yoksa devam
        }
    }

    private function runMigrations(): void
    {
        try {
            $users = new \nsql\database\migrations\create_users_table();
            $users->up();
            $test = new \nsql\database\migrations\create_test_table();
            $test->up();
        } catch (\Exception $e) {
            $this->markTestSkipped('Migration failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }
}
