<?php

namespace Tests\Unit;

use nsql\database\Nsql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConnectionLossRetryTest extends TestCase
{
    private static function pdo_error(string $sql_state, int $driver_code = 0): \PDOException
    {
        $e = new \PDOException("SQLSTATE[{$sql_state}]: test");
        $e->errorInfo = [$sql_state, $driver_code ?: null, 'test'];

        return $e;
    }

    private static function should_retry(\PDOException $e, string $sql, int $attempts = 1): bool
    {
        $db = (new \ReflectionClass(Nsql::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(Nsql::class, 'should_retry'))->invoke($db, $e, $attempts, $sql);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function queries(): array
    {
        return [
            'select' => ['SELECT * FROM users WHERE id = ?', true],
            'cte read' => ['WITH t AS (SELECT 1 AS x) SELECT x FROM t', true],
            'insert' => ['INSERT INTO users (name) VALUES (?)', false],
            'update' => ['UPDATE accounts SET balance = balance + 10 WHERE id = ?', false],
            'delete' => ['DELETE FROM users WHERE id = ?', false],
            'select for update' => ['SELECT * FROM users WHERE id = ? FOR UPDATE', false],
        ];
    }

    #[DataProvider('queries')]
    public function test_only_read_queries_are_retried_after_connection_loss(string $sql, bool $expected): void
    {
        $this->assertSame($expected, self::should_retry(self::pdo_error('HY000', 2013), $sql));
    }

    public function test_retry_limit_is_respected(): void
    {
        $this->assertFalse(self::should_retry(self::pdo_error('HY000', 2006), 'SELECT 1', 3));
    }

    public function test_non_connection_errors_are_not_retried(): void
    {
        $this->assertFalse(self::should_retry(self::pdo_error('42S02', 1146), 'SELECT * FROM missing'));
    }
}
