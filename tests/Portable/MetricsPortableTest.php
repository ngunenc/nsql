<?php

namespace Tests\Portable;

use nsql\database\Config;
use nsql\database\exceptions\QueryException;
use nsql\database\monitoring\Metrics;
use Tests\Support\PortableTestCase;

/**
 * Metrics önceden var olmayan anahtarları okuyup sorgu sayılarını her zaman 0 döndürüyordu.
 */
class MetricsPortableTest extends PortableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->create_table('p_metric_items', ['name VARCHAR(20) NOT NULL']);
    }

    public function test_query_counters_reflect_executed_queries(): void
    {
        $db = $this->connect();
        $before = $db->get_query_stats();

        Config::set('slow_query_threshold_ms', 0.000001);
        Config::set('log_file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nsql_metrics_' . getmypid() . '.log');
        try {
            for ($i = 0; $i < 20; $i++) {
                $db->get_results('SELECT name FROM p_metric_items WHERE name = ?', ['x' . $i]);
            }
            try {
                $db->get_results('SELECT missing_column FROM p_metric_items');
            } catch (QueryException) {
            }
        } finally {
            Config::set('slow_query_threshold_ms', null);
            Config::set('log_file', null);
        }

        $stats = $db->get_query_stats();
        $this->assertSame(21, $stats['total_queries'] - $before['total_queries']);
        $this->assertSame(1, $stats['failed_queries'] - $before['failed_queries']);
        $this->assertGreaterThanOrEqual(20, $stats['slow_queries'] - $before['slow_queries']);
        $this->assertGreaterThan(0, $stats['average_execution_time']);

        $metrics = new Metrics($db);
        $database = $metrics->get_database_metrics();
        $this->assertSame($stats['total_queries'], $database['query_count']);
        $this->assertSame($stats['slow_queries'], $database['slow_queries']);
        $analyzer = $metrics->get_query_analyzer_metrics();
        $this->assertSame($stats['total_queries'], $analyzer['total_queries']);
        $this->assertSame($stats['failed_queries'], $analyzer['failed_queries']);
    }

    public function test_risky_queries_are_counted_when_analysis_is_enabled(): void
    {
        $db = $this->connect();
        $db->enable_query_analysis();
        try {
            $db->get_results("SELECT * FROM p_metric_items WHERE name = CONCAT('a', 'b')");
            $db->get_results('SELECT name FROM p_metric_items WHERE name = ?', ['a']);
        } finally {
            $db->disable_query_analysis();
        }

        $this->assertSame(1, (new Metrics($db))->get_query_analyzer_metrics()['risky_queries']);
    }
}
