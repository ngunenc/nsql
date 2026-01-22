<?php

namespace nsql\database\monitoring;

use nsql\database\nsql;

/**
 * Metrics
 * 
 * Performans metrikleri toplama ve raporlama
 */
class metrics
{
    private nsql $db;

    public function __construct(nsql $db)
    {
        $this->db = $db;
    }

    /**
     * Tüm metrikleri döndürür
     *
     * @return array Metrics
     */
    public function get_all(): array
    {
        return [
            'database' => $this->get_database_metrics(),
            'cache' => $this->get_cache_metrics(),
            'memory' => $this->get_memory_metrics(),
            'connection_pool' => $this->get_connection_pool_metrics(),
            'query_analyzer' => $this->get_query_analyzer_metrics(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Database metriklerini döndürür
     */
    public function get_database_metrics(): array
    {
        try {
            $stats = $this->db->get_all_stats();
            
            return [
                'connection_pool' => $stats['connection_pool'] ?? [],
                'query_count' => $stats['query_analyzer']['total_queries'] ?? 0,
                'slow_queries' => $stats['query_analyzer']['slow_queries'] ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cache metriklerini döndürür
     */
    public function get_cache_metrics(): array
    {
        try {
            $stats = $this->db->get_cache_stats();
            
            return [
                'enabled' => $stats['enabled'],
                'size' => $stats['size'],
                'limit' => $stats['limit'],
                'hits' => $stats['hits'],
                'misses' => $stats['misses'],
                'hit_rate' => $stats['hit_rate'] . '%',
                'timeout' => $stats['timeout'],
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Memory metriklerini döndürür
     */
    public function get_memory_metrics(): array
    {
        try {
            $stats = $this->db->get_memory_stats();
            
            return [
                'current_usage' => $stats['current_usage'],
                'peak_usage' => $stats['peak_usage'],
                'limit' => $stats['limit'],
                'usage_percent' => round(($stats['current_usage'] / $stats['limit']) * 100, 2),
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Connection pool metriklerini döndürür
     */
    public function get_connection_pool_metrics(): array
    {
        try {
            $stats = $this->db->get_pool_stats();
            
            return [
                'active_connections' => $stats['active_connections'] ?? 0,
                'idle_connections' => $stats['idle_connections'] ?? 0,
                'total_connections' => $stats['total_connections'] ?? 0,
                'max_connections' => $stats['max_connections'] ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Query analyzer metriklerini döndürür
     */
    public function get_query_analyzer_metrics(): array
    {
        try {
            $stats = $this->db->get_query_analyzer_stats();
            
            return [
                'total_queries' => $stats['total_queries'] ?? 0,
                'slow_queries' => $stats['slow_queries'] ?? 0,
                'risky_queries' => $stats['risky_queries'] ?? 0,
                'average_execution_time' => $stats['average_execution_time'] ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
