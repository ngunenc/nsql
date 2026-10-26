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

        // PHP sürüm uyarısı çalışan sürüme bağlı; yalnızca hata kayıtları sayılır
        $failures = array_values(array_filter($logger->messages, fn (string $m) => str_contains($m, 'failed')));
        $this->assertCount(3, $failures);
        $this->assertStringContainsString('Access denied', $failures[0]);
    }

    public function test_php_check_reports_outdated_version_without_revealing_it(): void
    {
        $db = $this->createMock(Nsql::class);
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $health = new HealthCheck($db, $logger);

        $outdated = $health->check_php('8.4.26');
        $this->assertSame('outdated', $outdated['status']);
        $this->assertStringNotContainsString('8.4.26', (string) json_encode($outdated));
        $this->assertStringContainsString('8.4.27', $logger->messages[0] ?? '');

        $this->assertSame('unsupported', $health->check_php('8.1.30')['status']);
        $this->assertSame('healthy', $health->check_php('8.4.27')['status']);
        $this->assertCount(2, $logger->messages);
    }
}
