<?php

namespace Tests\Unit;

use nsql\database\Nsql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReadQueryDetectionTest extends TestCase
{
    private static function is_read(string $sql): bool
    {
        return (new \ReflectionMethod(Nsql::class, 'is_read_query'))->invoke(null, $sql);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function queries(): array
    {
        return [
            'plain select' => ['SELECT id FROM users WHERE id = ?', true],
            'cte read' => ['WITH t AS (SELECT 1 AS x) SELECT x FROM t', true],
            'show' => ['SHOW TABLES', true],
            'into outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/u.csv'", false],
            'into dumpfile' => ["SELECT data FROM blobs LIMIT 1 INTO DUMPFILE '/tmp/b'", false],
            'into variable' => ['SELECT COUNT(*) INTO @total FROM users', false],
            'pgsql into table' => ['SELECT * INTO users_copy FROM users', false],
            'lowercase into' => ['select id into @x from users limit 1', false],
            'for update' => ['SELECT * FROM users FOR UPDATE', false],
            'insert' => ['INSERT INTO users (name) VALUES (?)', false],
            'mysql named lock' => ["SELECT GET_LOCK('job', 10)", false],
            'mysql release lock' => ["SELECT release_lock('job')", false],
            'pg advisory lock' => ['SELECT pg_advisory_lock(42)', false],
            'pg try advisory xact lock' => ['SELECT pg_try_advisory_xact_lock(42)', false],
            'sequence' => ["SELECT nextval('orders_id_seq')", false],
            'connection last id' => ['SELECT LAST_INSERT_ID()', false],
            'sleep' => ['SELECT SLEEP(1)', false],
            'column named like function' => ['SELECT next_value, lock_name FROM jobs', true],
        ];
    }

    #[DataProvider('queries')]
    public function test_read_detection(string $sql, bool $expected): void
    {
        $this->assertSame($expected, self::is_read($sql));
    }
}
