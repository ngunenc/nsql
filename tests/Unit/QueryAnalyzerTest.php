<?php

namespace Tests\Unit;

use nsql\database\security\QueryAnalyzer;
use PHPUnit\Framework\TestCase;

class QueryAnalyzerTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function types(array $report): array
    {
        return array_column($report['issues'], 'type');
    }

    public function test_safe_query_has_no_risk(): void
    {
        $report = (new QueryAnalyzer())->analyze_query('SELECT id, name FROM users WHERE id = ?');

        $this->assertSame('SELECT id, name FROM users WHERE id = ?', $report['query']);
        $this->assertSame([], array_values(array_filter(
            $report['issues'],
            fn ($issue) => in_array($issue['category'], ['risk', 'security'], true)
        )));
        $this->assertLessThanOrEqual(1, $report['risk_score']);
    }

    public function test_dangerous_writes_are_flagged(): void
    {
        $analyzer = new QueryAnalyzer();

        $this->assertContains('delete_without_where', self::types($analyzer->analyze_query('DELETE FROM users')));
        $this->assertContains('drop_table', self::types($analyzer->analyze_query('DROP TABLE users')));

        $report = $analyzer->analyze_query('DELETE FROM users');
        $this->assertGreaterThan(0, $report['risk_score']);
        $this->assertNotEmpty($report['recommendations']);
    }

    public function test_injection_patterns_are_flagged(): void
    {
        $report = (new QueryAnalyzer())->analyze_query("SELECT * FROM users WHERE id = 1 OR SLEEP(5) -- x");

        $categories = array_unique(array_column($report['issues'], 'category'));
        $this->assertContains('security', $categories);
        $this->assertContains('select_all_columns', self::types($report));
    }

    /**
     * enable_query_analysis() kritik bulguda sorguyu engeller; normal sorgular kritik sayılmamalı.
     *
     * @return array<string, array{string}>
     */
    public static function legitimate_queries(): array
    {
        return [
            'delete with where' => ['DELETE FROM sessions WHERE expires_at < ?'],
            'delete with subquery where' => ['DELETE FROM a WHERE id IN (SELECT a_id FROM b WHERE b.x = ?)'],
            'update with where' => ['UPDATE users SET name = ?, email = ? WHERE id = ?'],
            'update with subquery' => ['UPDATE users SET name = ? WHERE id IN (SELECT user_id FROM x)'],
            'update set value has commas' => ["UPDATE t SET note = CONCAT(a, ', ', b) WHERE id = ?"],
            'column named like command' => ['SELECT id, shutdown_at, kill_count, lock_tables_flag FROM servers WHERE id = ?'],
            'update column named like command' => ['UPDATE servers SET shutdown_reason = ? WHERE id = ?'],
            'semicolon in literal' => ["SELECT id FROM t WHERE note = 'a; DROP TABLE x'"],
            'trailing semicolon' => ['SELECT * FROM users ORDER BY id;'],
            'pg upsert' => ['INSERT INTO t (a, b) VALUES (?, ?) ON CONFLICT (a) DO UPDATE SET b = EXCLUDED.b'],
            'mysql upsert' => ['INSERT INTO t (a, b) VALUES (?, ?) ON DUPLICATE KEY UPDATE b = VALUES(b)'],
            'empty not in from builder' => ['SELECT * FROM "t" WHERE "a" = :__p0 AND 1 = 1'],
            'union with null padding' => ['SELECT id, name FROM a UNION ALL SELECT id, NULL FROM b'],
            'comment with keyword' => ["SELECT id FROM t -- DROP TABLE t\nWHERE id = ?"],
            'cte delete with where' => ['WITH old AS (SELECT id FROM t WHERE x < ?) DELETE FROM t WHERE id IN (SELECT id FROM old)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legitimate_queries')]
    public function test_legitimate_queries_are_not_critical(string $sql): void
    {
        $critical = array_values(array_filter(
            (new QueryAnalyzer())->analyze_query($sql)['issues'],
            fn ($issue) => $issue['risk_level'] === 'critical'
        ));

        $this->assertSame([], array_column($critical, 'type'), $sql);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dangerous_queries(): array
    {
        return [
            'delete all' => ['DELETE FROM users', 'delete_without_where'],
            'delete all with trailing semicolon' => ['DELETE FROM users;', 'delete_without_where'],
            'delete where only in subquery' => ['DELETE FROM a WHERE_x IN (SELECT id FROM b WHERE c = 1)', 'delete_without_where'],
            'update all' => ['UPDATE users SET is_admin = 1', 'update_without_where'],
            'update where in literal only' => ["UPDATE users SET note = 'WHERE id = 1'", 'update_without_where'],
            'drop table' => ['DROP TABLE users', 'drop_table'],
            'stacked' => ['SELECT 1; DROP TABLE users', 'stacked_queries'],
            'shutdown' => ['SHUTDOWN', 'shutdown'],
            'into outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/x'", 'potential_injection'],
            'sleep' => ['SELECT * FROM t WHERE id = 1 AND SLEEP(5)', 'time_based_injection'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerous_queries')]
    public function test_dangerous_queries_are_still_critical(string $sql, string $type): void
    {
        $issues = (new QueryAnalyzer())->analyze_query($sql)['issues'];
        $critical = array_column(array_filter($issues, fn ($issue) => $issue['risk_level'] === 'critical'), 'type');

        $this->assertContains($type, $critical, $sql);
    }

    public function test_hash_is_a_comment_only_on_mysql(): void
    {
        // PostgreSQL'de `#` XOR operatörüdür; ardındaki ifade yorum sayılmamalı
        $sql = 'DELETE FROM t # WHERE id = 1';
        $this->assertContains('delete_without_where', self::types((new QueryAnalyzer('mysql'))->analyze_query($sql)));
        $this->assertNotContains('delete_without_where', self::types((new QueryAnalyzer('pgsql'))->analyze_query($sql)));
    }

    public function test_complexity_and_length(): void
    {
        $joins = 'SELECT a.id FROM a JOIN b ON b.a = a.id JOIN c ON c.b = b.id JOIN d ON d.c = c.id JOIN e ON e.d = d.id';
        $this->assertContains('complex_joins', self::types((new QueryAnalyzer())->analyze_query($joins)));

        $long = 'SELECT id FROM t WHERE ' . implode(' AND ', array_fill(0, 7, 'x = 1')) . str_repeat(' ', 1000);
        $types = self::types((new QueryAnalyzer())->analyze_query($long));
        $this->assertContains('complex_conditions', $types);
        $this->assertContains('long_query', $types);
    }
}
