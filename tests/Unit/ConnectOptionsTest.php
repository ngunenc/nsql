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
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_connect_options_' . getmypid() . '.sqlite';
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

    public function test_different_options_use_different_pools(): void
    {
        $plain = new Nsql(db: $this->path, driver: 'sqlite');
        $upper = new Nsql(db: $this->path, driver: 'sqlite', options: [PDO::ATTR_CASE => PDO::CASE_UPPER]);

        $this->assertNotSame($plain->get_pdo(), $upper->get_pdo());
        $this->assertNotSame(PDO::CASE_UPPER, $plain->get_pdo()?->getAttribute(PDO::ATTR_CASE));
    }
}
