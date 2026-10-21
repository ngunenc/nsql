<?php

namespace Tests\Unit;

use nsql\database\Nsql;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Nsql::connect() / constructor PDO seçenekleri (#98).
 */
class ConnectOptionsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        // Test başına benzersiz dosya: havuzda kalan bağlantı, silinmiş bir dosyaya yeniden verilmesin
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_connect_options_' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function options_of(Nsql $db): array
    {
        return (fn () => $this->options)->call($db);
    }

    public function test_option_keys_are_pdo_attributes_not_renumbered(): void
    {
        $db = new Nsql(db: $this->path, driver: 'sqlite');
        $options = self::options_of($db);

        $this->assertArrayHasKey(PDO::ATTR_PERSISTENT, $options);
        $this->assertArrayHasKey(PDO::ATTR_ERRMODE, $options);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
        // array_merge yeniden numaralandırsaydı 0 (ATTR_AUTOCOMMIT) anahtarı oluşurdu
        $this->assertArrayNotHasKey(PDO::ATTR_AUTOCOMMIT, $options);
    }

    public function test_connect_applies_options_before_connecting(): void
    {
        $db = Nsql::connect('sqlite:' . $this->path, null, null, [PDO::ATTR_CASE => PDO::CASE_UPPER]);

        $this->assertSame(PDO::CASE_UPPER, self::options_of($db)[PDO::ATTR_CASE]);
        $this->assertSame(PDO::CASE_UPPER, $db->get_pdo()?->getAttribute(PDO::ATTR_CASE));

        $row = $db->get_row('SELECT 1 AS value');
        $this->assertNotNull($row);
        $this->assertObjectHasProperty('VALUE', $row);
    }

    public function test_connect_sqlite_uses_full_path(): void
    {
        $db = Nsql::connect('sqlite:' . $this->path);
        $db->query('CREATE TABLE IF NOT EXISTS connect_path_probe (id INTEGER)');

        // Aynı dosya tam yolla açılınca tablo görünmeli (dosya çalışma dizininde açılmamalı)
        $direct = new Nsql(db: $this->path, driver: 'sqlite');
        $this->assertNotNull($direct->get_row("SELECT name FROM sqlite_master WHERE name = 'connect_path_probe'"));
        $this->assertFileDoesNotExist(getcwd() . DIRECTORY_SEPARATOR . basename($this->path));
    }

    /**
     * Bağlanılamasa da havuz DSN'i kaydedilir; sslmode / sslrootcert DSN'de kalmalı (önceden düşüyordu).
     */
    public function test_connect_keeps_tls_params_and_dsn_credentials(): void
    {
        $dsn = 'pgsql:host=127.0.0.1;port=1;dbname=nsql_tls_probe;sslmode=verify-full;sslrootcert=/etc/nsql-ca.pem;user=dsn_user;password=dsn_pass';

        try {
            Nsql::connect($dsn);
        } catch (\Throwable) {
            // Port 1'de sunucu yok (veya pdo_pgsql yüklü değil); kaydedilen havuz yapılandırması incelenir
        }

        $configs = array_column((fn () => self::$pools)->call(new \nsql\database\ConnectionPool()), 'config');
        $matches = array_values(array_filter($configs, fn (array $c) => str_contains($c['dsn'], 'nsql_tls_probe')));

        $this->assertNotEmpty($matches);
        $this->assertStringContainsString(';sslmode=verify-full;sslrootcert=/etc/nsql-ca.pem', $matches[0]['dsn']);
        $this->assertSame(['dsn_user', 'dsn_pass'], [$matches[0]['username'], $matches[0]['password']]);
    }

    public function test_mysql_tls_settings_become_pdo_options(): void
    {
        if (! defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $this->markTestSkipped('pdo_mysql yüklü değil');
        }

        \nsql\database\Config::set('db_ssl_ca', '/etc/nsql-ca.pem');
        \nsql\database\Config::set('db_ssl_verify', 'true');
        try {
            $method = new \ReflectionMethod(Nsql::class, 'configured_ssl_options');
            $options = $method->invoke(null, 'mysql');
        } finally {
            \nsql\database\Config::set('db_ssl_ca', null);
            \nsql\database\Config::set('db_ssl_verify', null);
        }

        $this->assertSame('/etc/nsql-ca.pem', $options[PDO::MYSQL_ATTR_SSL_CA] ?? null);
        $this->assertTrue($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null);
        $this->assertSame([], $method->invoke(null, 'pgsql'));
    }

    public function test_different_options_use_different_pools(): void
    {
        $plain = new Nsql(db: $this->path, driver: 'sqlite');
        $upper = new Nsql(db: $this->path, driver: 'sqlite', options: [PDO::ATTR_CASE => PDO::CASE_UPPER]);

        $this->assertNotSame($plain->get_pdo(), $upper->get_pdo());
        $this->assertNotSame(PDO::CASE_UPPER, $plain->get_pdo()?->getAttribute(PDO::ATTR_CASE));
    }
}
