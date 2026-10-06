<?php

namespace Tests\Integration;

use nsql\database\Config;
use nsql\database\exceptions\QueryException;
use nsql\database\Nsql;
use PHPUnit\Framework\TestCase;

class QueryAnalyzerTraitTest extends TestCase
{
    private Nsql $db;
    private string $file;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite gerekli');
        }
        Config::set_environment('testing');
        Config::set_project_root(dirname(__DIR__, 2));
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_analyzer_' . getmypid() . '.sqlite';
        $this->db = new Nsql(db: $this->file, driver: 'sqlite');
    }

    protected function tearDown(): void
    {
        (fn () => $this->disconnect())->call($this->db);
        @unlink($this->file);
    }

    public function test_analyze_sql_reports_without_enabling_analysis(): void
    {
        $report = $this->db->analyze_sql('DELETE FROM users');

        $this->assertContains('delete_without_where', array_column($report['issues'], 'type'));
        $this->assertFalse($this->db->get_query_analyzer_stats()['enabled']);
    }

    public function test_critical_finding_throws_query_exception(): void
    {
        $this->db->enable_query_analysis();

        try {
            (fn () => $this->analyze_query('DROP TABLE users'))->call($this->db);
            $this->fail('QueryException bekleniyordu');
        } catch (QueryException $e) {
            $this->assertSame('DROP TABLE users', $e->get_sql());
            $this->assertStringContainsString('Kritik risk', $e->getMessage());
        }
    }
}
