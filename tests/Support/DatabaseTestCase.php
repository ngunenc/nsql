<?php

namespace Tests\Support;

use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

/**
 * MySQL entegrasyon testleri için ortak kurulum.
 *
 * NSQL_TEST_QUERY_CACHE=1 ile tüm entegrasyon testleri query cache açıkken koşar
 * (`composer test:cache`).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected ?Nsql $db = null;
    private static bool $migrated = false;

    public static function query_cache_suite(): bool
    {
        $flag = getenv('NSQL_TEST_QUERY_CACHE');

        return $flag !== false && filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    protected function setUp(): void
    {
        \nsql\database\Config::set_environment('testing');
        \nsql\database\Config::set_project_root(dirname(__DIR__, 2));
        \nsql\database\Config::set('query_cache_enabled', self::query_cache_suite());

        $this->db = new Nsql(
            host: \nsql\database\Config::get('db_host', 'localhost'),
            db: \nsql\database\Config::get('db_name', 'nsql_test_db'),
            user: \nsql\database\Config::get('db_user', 'root'),
            pass: \nsql\database\Config::get('db_pass', '')
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
        // Döngüsel referanslar yıkıcıyı GC'ye bırakabilir; bağlantı havuza hemen iade edilir.
        if ($this->db !== null) {
            (fn () => $this->disconnect())->call($this->db);
        }
        $this->db = null;
    }
}
