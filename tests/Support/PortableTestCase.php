<?php

namespace Tests\Support;

use nsql\database\Config;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

/**
 * Sürücüden bağımsız entegrasyon testleri (tests/Portable).
 *
 * DB_DRIVER=mysql|pgsql|sqlite ile aynı testler üç veritabanında koşar. SQLite için geçici bir
 * dosya kullanılır (':memory:' her havuz bağlantısında ayrı veritabanı açacağı için uygun değil).
 */
abstract class PortableTestCase extends TestCase
{
    protected Nsql $db;

    /** @var list<Nsql> */
    private array $connections = [];

    public static function driver(): string
    {
        $driver = strtolower((string) Config::get('db_driver', 'mysql'));

        return match ($driver) {
            'postgresql', 'postgres' => 'pgsql',
            'mariadb' => 'mysql',
            default => $driver,
        };
    }

    protected function setUp(): void
    {
        Config::set_environment('testing');
        Config::set_project_root(dirname(__DIR__, 2));
        Config::set('query_cache_enabled', DatabaseTestCase::query_cache_suite());

        $this->db = $this->connect();
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            (fn () => $this->disconnect())->call($db);
        }
        $this->connections = [];
    }

    protected function connect(): Nsql
    {
        $driver = self::driver();
        $db = $driver === 'sqlite'
            ? new Nsql(db: self::sqlite_path(), driver: 'sqlite')
            : new Nsql(
                host: (string) Config::get('db_host', 'localhost'),
                db: (string) Config::get('db_name', 'nsql_test_db'),
                user: (string) Config::get('db_user', 'root'),
                pass: (string) Config::get('db_pass', ''),
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
        $suffix = self::driver() === 'mysql' ? ' ENGINE=InnoDB' : '';

        $this->db->query('CREATE TABLE ' . $table . ' (' . implode(', ', [$id, ...$columns]) . ')' . $suffix);
    }
}
