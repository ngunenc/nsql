<?php

namespace Tests\Unit;

use nsql\database\Config;
use nsql\database\traits\CacheTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueryCacheTableExtractionTest extends TestCase
{
    private function extract(string $sql): array
    {
        $subject = new class () {
            use CacheTrait;

            public function extract(string $sql): array
            {
                return $this->extract_tables_from_query($sql);
            }

            public function get_transaction_level(): int
            {
                return 0;
            }
        };

        $tables = $subject->extract($sql);
        sort($tables);

        return $tables;
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function queries(): array
    {
        return [
            'simple select' => ['SELECT * FROM users WHERE id = 1', ['users']],
            'backtick quoted' => ['SELECT `name` FROM `test_table`', ['test_table']],
            'schema prefixed' => ['SELECT * FROM `app`.`orders` o', ['orders']],
            'comma join' => ['SELECT * FROM users u, orders o WHERE u.id = o.user_id', ['orders', 'users']],
            'plain join' => ['SELECT * FROM users JOIN orders ON orders.user_id = users.id', ['orders', 'users']],
            'left join quoted' => ['SELECT * FROM `users` LEFT JOIN `orders` ON 1=1', ['orders', 'users']],
            'subquery' => ['SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)', ['orders', 'users']],
            'derived table' => ['SELECT * FROM (SELECT id FROM u) t', ['u']],
            'derived table with join' => [
                'SELECT COUNT(*) AS c FROM (SELECT user_id FROM orders GROUP BY user_id) t JOIN users ON users.id = t.user_id',
                ['orders', 'users'],
            ],
            'nested derived table' => ['SELECT * FROM (SELECT * FROM (SELECT id FROM `app`.`logs`) a) b', ['logs']],
            'derived table plus comma table' => ['SELECT * FROM (SELECT id FROM u) t, v WHERE t.id = v.id', ['u', 'v']],
            'derived table with function call' => ['SELECT * FROM (SELECT COUNT(id) AS c FROM u GROUP BY x) t', ['u']],
            'derived table without table' => ['SELECT * FROM (SELECT 1 AS x) t, v', []],
            'parenthesized from item' => ['SELECT * FROM (u) JOIN v ON 1 = 1', []],
            'insert' => ['INSERT INTO `logs` (a) VALUES (?)', ['logs']],
            'insert ignore' => ['INSERT IGNORE INTO logs (a) VALUES (1)', ['logs']],
            'replace' => ['REPLACE INTO logs (a) VALUES (1)', ['logs']],
            'update ignore' => ['UPDATE IGNORE users SET a = 1', ['users']],
            'delete' => ['DELETE FROM users WHERE id = 1', ['users']],
            'truncate' => ['TRUNCATE TABLE sessions', ['sessions']],
            'drop if exists' => ['DROP TABLE IF EXISTS sessions', ['sessions']],
            'no table' => ['SELECT 1', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('queries')]
    public function test_extracts_tables(string $sql, array $expected): void
    {
        $this->assertSame($expected, $this->extract($sql));
    }

    public function test_query_cache_is_disabled_by_default(): void
    {
        $this->assertFalse(Config::query_cache_enabled);
        $this->assertFalse(Config::default_values()['QUERY_CACHE_ENABLED']);
    }
}
