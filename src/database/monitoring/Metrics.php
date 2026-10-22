<?php

namespace nsql\database\monitoring;

use nsql\database\Nsql;

/**
 * Metrics
 *
 * Performans metrikleri toplama ve raporlama
 */
class Metrics
{
    /**
     * Hata ayrıntısı yanıta konmaz (EndpointGuard::fail_closed ile aynı ilke); error_log'a yazılır.
     *
     * @return array{error: string}
     */
    private static function failure(\Exception $e): array
    {
        error_log('[nsql metrics] ' . $e::class . ': ' . $e->getMessage());

        return ['error' => 'unavailable'];
    }

    private Nsql $db;

    public function __construct(Nsql $db)
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
            $queries = $this->db->get_query_stats();

            return [
                'connection_pool' => $stats['connection_pool'] ?? [],
                'query_count' => $queries['total_queries'],
                'slow_queries' => $queries['slow_queries'],
                'failed_queries' => $queries['failed_queries'],
            ];
        } catch (\Exception $e) {
            return self::failure($e);
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
            return self::failure($e);
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
            return self::failure($e);
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
            return self::failure($e);
        }
    }

    /**
     * Query analyzer metriklerini döndürür
     */
    public function get_query_analyzer_metrics(): array
    {
        try {
            $stats = $this->db->get_query_analyzer_stats();
            $queries = $this->db->get_query_stats();

            // Önceden olmayan anahtarlar okunuyor, her zaman 0 dönüyordu
            return [
                'total_queries' => $queries['total_queries'],
                'slow_queries' => $queries['slow_queries'],
                'failed_queries' => $queries['failed_queries'],
                'risky_queries' => $stats['risky_queries'],
                'average_execution_time' => $queries['average_execution_time'],
            ];
        } catch (\Exception $e) {
            return self::failure($e);
        }
    }
}
