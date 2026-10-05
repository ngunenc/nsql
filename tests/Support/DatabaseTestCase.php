<?php

namespace Tests\Support;

use nsql\database\nsql;
use PHPUnit\Framework\TestCase;

/**
 * MySQL entegrasyon testleri için ortak kurulum.
 *
 * NSQL_TEST_QUERY_CACHE=1 ile tüm entegrasyon testleri query cache açıkken koşar
 * (`composer test:cache`).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected ?nsql $db = null;
    private static bool $migrated = false;

    public static function query_cache_suite(): bool
    {
        $flag = getenv('NSQL_TEST_QUERY_CACHE');

        return $flag !== false && filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    protected function setUp(): void
    {
        \nsql\database\config::set_environment('testing');
        \nsql\database\config::set_project_root(dirname(__DIR__, 2));
        \nsql\database\config::set('query_cache_enabled', self::query_cache_suite());

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
            (new \Tests\Fixtures\Migrations\create_users_table())->set_connection($this->db)->up();
            (new \Tests\Fixtures\Migrations\create_test_table())->set_connection($this->db)->up();
        } catch (\Exception $e) {
            $this->markTestSkipped('Migration failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }
}
