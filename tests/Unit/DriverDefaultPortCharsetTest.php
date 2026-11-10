<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

/**
 * DB_PORT / DB_CHARSET verilmediğinde varsayılanlar sürücüye göre üretilir
 * (MySQL varsayılanları PostgreSQL bağlantısına sızmaz).
 */
class DriverDefaultPortCharsetTest extends TestCase
{
    private string $temp_root;

    /** @var array<string, string|false> */
    private array $saved_env = [];

    protected function setUp(): void
    {
        // CI bu değişkenleri verebilir: test süresince kaldırılır, sonra geri yüklenir
        foreach (['DB_PORT', 'DB_CHARSET'] as $key) {
            $this->saved_env[$key] = getenv($key);
            putenv($key);
        }

        // .env içermeyen kök: DB_PORT / DB_CHARSET hiçbir kaynaktan gelmez
        $this->temp_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_driver_defaults_' . bin2hex(random_bytes(6));
        mkdir($this->temp_root, 0700, true);
        Config::set_project_root($this->temp_root);
        Config::refresh();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved_env as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        Config::set_project_root(dirname(__DIR__, 2));
        Config::refresh();
        @rmdir($this->temp_root);
    }

    /**
     * Bağlantı kurulamasa da (sunucu/sürücü yok) constructor'ın ürettiği DSN'i döndürür.
     */
    private static function dsn_for(string $driver): string
    {
        $db = (new \ReflectionClass(Nsql::class))->newInstanceWithoutConstructor();

        try {
            (new \ReflectionMethod(Nsql::class, '__construct'))->invoke(
                $db,
                host: '127.0.0.1',
                db: 'nsql_defaults_probe',
                user: 'nsql_probe',
                pass: 'nsql_probe',
                driver: $driver
            );
        } catch (\Throwable) {
            // DSN bağlantı denemesinden önce oluşturulur
        }

        return (fn (): string => $this->dsn)->call($db);
    }

    public function test_config_has_no_driver_specific_port_or_charset_default(): void
    {
        $defaults = Config::default_values();

        $this->assertArrayNotHasKey('DB_PORT', $defaults);
        $this->assertArrayNotHasKey('DB_CHARSET', $defaults);
        $this->assertNull(Config::get('db_port'));
        $this->assertNull(Config::get('db_charset'));
    }

    public function test_pgsql_without_port_uses_5432_and_utf8(): void
    {
        $dsn = self::dsn_for('pgsql');

        $this->assertStringContainsString(';port=5432;', $dsn);
        $this->assertStringContainsString('--client_encoding=UTF8', $dsn);
        $this->assertStringNotContainsString('3306', $dsn);
        $this->assertStringNotContainsString('utf8mb4', $dsn);
    }

    public function test_mysql_without_port_keeps_3306_and_utf8mb4(): void
    {
        $dsn = self::dsn_for('mysql');

        $this->assertStringContainsString(';port=3306;', $dsn);
        $this->assertStringContainsString(';charset=utf8mb4', $dsn);
    }

    public function test_explicit_config_port_and_charset_still_win(): void
    {
        Config::set('db_port', 6543);
        Config::set('db_charset', 'LATIN1');

        try {
            $dsn = self::dsn_for('pgsql');
        } finally {
            Config::refresh();
        }

        $this->assertStringContainsString(';port=6543;', $dsn);
        $this->assertStringContainsString('--client_encoding=LATIN1', $dsn);
    }
}
