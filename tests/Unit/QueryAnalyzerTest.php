<?php

namespace Tests\Unit;

use nsql\database\security\query_analyzer;
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
        $report = (new query_analyzer())->analyze_query('SELECT id, name FROM users WHERE id = ?');

        $this->assertSame('SELECT id, name FROM users WHERE id = ?', $report['query']);
        $this->assertSame([], array_values(array_filter(
            $report['issues'],
            fn ($issue) => in_array($issue['category'], ['risk', 'security'], true)
        )));
        $this->assertLessThanOrEqual(1, $report['risk_score']);
    }

    public function test_dangerous_writes_are_flagged(): void
    {
        $analyzer = new query_analyzer();

        $this->assertContains('delete_without_where', self::types($analyzer->analyze_query('DELETE FROM users')));
        $this->assertContains('drop_table', self::types($analyzer->analyze_query('DROP TABLE users')));

        $report = $analyzer->analyze_query('DELETE FROM users');
        $this->assertGreaterThan(0, $report['risk_score']);
        $this->assertNotEmpty($report['recommendations']);
    }

    public function test_injection_patterns_are_flagged(): void
    {
        $report = (new query_analyzer())->analyze_query("SELECT * FROM users WHERE id = 1 OR SLEEP(5) -- x");

        $categories = array_unique(array_column($report['issues'], 'category'));
        $this->assertContains('security', $categories);
        $this->assertContains('select_all_columns', self::types($report));
    }

    public function test_complexity_and_length(): void
    {
        $joins = 'SELECT a.id FROM a JOIN b ON b.a = a.id JOIN c ON c.b = b.id JOIN d ON d.c = c.id JOIN e ON e.d = d.id';
        $this->assertContains('complex_joins', self::types((new query_analyzer())->analyze_query($joins)));

        $long = 'SELECT id FROM t WHERE ' . implode(' AND ', array_fill(0, 7, 'x = 1')) . str_repeat(' ', 1000);
        $types = self::types((new query_analyzer())->analyze_query($long));
        $this->assertContains('complex_conditions', $types);
        $this->assertContains('long_query', $types);
    }
}
