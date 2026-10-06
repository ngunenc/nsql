<?php

namespace Tests\Unit;

use nsql\database\ConnectionPool;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArrayLogger;

class ConnectionPoolWarningTest extends TestCase
{
    private ArrayLogger $logger;
    private string $file;

    /** @var list<PDO> */
    private array $held = [];

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        $this->logger = new ArrayLogger();
        ConnectionPool::set_logger($this->logger);
        // Benzersiz DSN: her test yeni (uyarı verilmemiş) bir havuz alır
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_pool_' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $pdo) {
            ConnectionPool::discard_connection($pdo);
        }
        $this->held = [];
        ConnectionPool::set_logger(null);
        @unlink($this->file);
    }

    private function pool(int $max): string
    {
        return ConnectionPool::initialize(
            ['dsn' => 'sqlite:' . $this->file, 'username' => '', 'password' => '', 'options' => []],
            0,
            $max
        );
    }

    public function test_warns_once_when_usage_reaches_threshold(): void
    {
        $key = $this->pool(5);

        for ($i = 0; $i < 3; $i++) {
            $this->held[] = ConnectionPool::get_connection($key);
        }
        $this->assertSame([], $this->logger->records);

        $this->held[] = ConnectionPool::get_connection($key);
        $warnings = $this->logger->matching('warning', 'bağlantı havuzu');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('4/5', $warnings[0]['message']);
        $this->assertSame(5, $warnings[0]['context']['max']);

        $this->held[] = ConnectionPool::get_connection($key);
        $this->assertCount(1, $this->logger->records, 'havuz başına bir kez');
    }

    public function test_reused_idle_connection_does_not_warn(): void
    {
        $key = $this->pool(10);

        for ($i = 0; $i < 20; $i++) {
            $pdo = ConnectionPool::get_connection($key);
            ConnectionPool::release_connection($pdo);
        }
        $this->held[] = $pdo;

        $this->assertSame([], $this->logger->records);
    }
}
