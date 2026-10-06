<?php

namespace Tests\Unit;

use nsql\database\optimization\QueryOptimizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueryOptimizerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function valid_queries(): array
    {
        return [
            'where 1=1' => ['SELECT * FROM users WHERE 1=1 AND status = 1'],
            'aggregate' => ['SELECT COUNT(id) FROM users'],
            'single in' => ['SELECT * FROM users WHERE id IN (5)'],
            'literal whitespace' => ["SELECT * FROM users WHERE name = 'a  b'"],
        ];
    }

    #[DataProvider('valid_queries')]
    public function test_optimize_does_not_rewrite_sql(string $sql): void
    {
        $this->assertSame($sql, QueryOptimizer::optimize($sql));
        $this->assertSame($sql, QueryOptimizer::optimize($sql, ['rewrite' => true]));
    }

    public function test_index_hints_are_added_to_from_and_join(): void
    {
        $sql = 'SELECT * FROM users JOIN orders ON orders.user_id = users.id JOIN users_archive ON 1 = 1';

        $result = QueryOptimizer::optimize($sql, [
            'add_index_hints' => true,
            'index_hints' => ['users' => 'idx_email, idx_status', 'orders' => ['idx_user']],
        ]);

        $this->assertSame(
            'SELECT * FROM users USE INDEX (idx_email, idx_status) JOIN orders USE INDEX (idx_user) '
            . 'ON orders.user_id = users.id JOIN users_archive ON 1 = 1',
            $result
        );
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function invalid_hints(): array
    {
        return [
            'index injection' => [['users' => 'idx) UNION SELECT password FROM admins -- ']],
            'table injection' => [['users`; DROP TABLE x; --' => 'idx']],
        ];
    }

    /**
     * @param array<string, string> $hints
     */
    #[DataProvider('invalid_hints')]
    public function test_invalid_hint_names_are_rejected(array $hints): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueryOptimizer::optimize('SELECT * FROM users', ['add_index_hints' => true, 'index_hints' => $hints]);
    }
}
