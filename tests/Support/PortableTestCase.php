<?php

namespace Tests\Support;

use nsql\database\config;
use nsql\database\nsql;
use PHPUnit\Framework\TestCase;

/**
 * Sürücüden bağımsız entegrasyon testleri (tests/Portable).
 *
 * DB_DRIVER=mysql|pgsql|sqlite ile aynı testler üç veritabanında koşar. SQLite için geçici bir
 * dosya kullanılır (':memory:' her havuz bağlantısında ayrı veritabanı açacağı için uygun değil).
 */
abstract class PortableTestCase extends TestCase
{
    protected nsql $db;

    /** @var list<nsql> */
    private array $connections = [];

    public static function driver(): string
    {
        $driver = strtolower((string) config::get('db_driver', 'mysql'));

        return match ($driver) {
            'postgresql', 'postgres' => 'pgsql',
            'mariadb' => 'mysql',
            default => $driver,
        };
    }

    protected function setUp(): void
    {
        config::set_environment('testing');
        config::set_project_root(dirname(__DIR__, 2));
        config::set('query_cache_enabled', DatabaseTestCase::query_cache_suite());

        $this->db = $this->connect();
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->connections = [];
    }

    protected function connect(): nsql
    {
        $driver = self::driver();
        $db = $driver === 'sqlite'
            ? new nsql(db: self::sqlite_path(), driver: 'sqlite')
            : new nsql(
                host: (string) config::get('db_host', 'localhost'),
                db: (string) config::get('db_name', 'nsql_test_db'),
                user: (string) config::get('db_user', 'root'),
                pass: (string) config::get('db_pass', ''),
                charset: $driver === 'pgsql' ? 'UTF8' : null,
                driver: $driver
            );
        $this->connections[] = $db;

        return $db;
    }

    private static function sqlite_path(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_portable_' . getmypid() . '.sqlite';
    }

    /**
     * Sürücüye uygun otomatik artan birincil anahtarla tabloyu yeniden oluşturur.
     *
     * @param list<string> $columns
     */
    protected function create_table(string $table, array $columns): void
    {
        $id = match (self::driver()) {
            'pgsql' => 'id SERIAL PRIMARY KEY',
            'sqlite' => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'id INT AUTO_INCREMENT PRIMARY KEY',
        };

        $this->db->query('DROP TABLE IF EXISTS ' . $table);
        $this->db->query('CREATE TABLE ' . $table . ' (' . implode(', ', [$id, ...$columns]) . ')');
    }
}
