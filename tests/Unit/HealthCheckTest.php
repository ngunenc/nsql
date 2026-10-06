<?php

namespace Tests\Unit;

use nsql\database\monitoring\HealthCheck;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class HealthCheckTest extends TestCase
{
    private const SECRET = "SQLSTATE[HY000] [1045] Access denied for user 'app'@'10.0.0.5' table secret_tbl";

    public function test_failures_do_not_leak_exception_details(): void
    {
        $db = $this->createMock(Nsql::class);
        $db->method('get_row')->willThrowException(new \PDOException(self::SECRET));
        $db->method('get_cache_stats')->willThrowException(new \RuntimeException(self::SECRET));
        $db->method('get_memory_stats')->willThrowException(new \RuntimeException(self::SECRET));

        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $result = (new HealthCheck($db, $logger))->check();
        $json = (string) json_encode($result);

        $this->assertSame('unhealthy', $result['checks']['database']['status']);
        $this->assertSame('Database check failed', $result['checks']['database']['message']);
        $this->assertSame('Cache check failed', $result['checks']['cache']['message']);
        $this->assertSame('Memory check failed', $result['checks']['memory']['message']);
        $this->assertStringNotContainsString('Access denied', $json);
        $this->assertStringNotContainsString('secret_tbl', $json);
        $this->assertStringNotContainsString('PDOException', $json);

        $this->assertCount(3, $logger->messages);
        $this->assertStringContainsString('Access denied', $logger->messages[0]);
    }
}
