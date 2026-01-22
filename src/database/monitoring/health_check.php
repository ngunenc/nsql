<?php

namespace nsql\database\monitoring;

use nsql\database\nsql;

/**
 * Health Check
 * 
 * Veritabanı ve sistem sağlık kontrolü
 */
class health_check
{
    private nsql $db;

    public function __construct(nsql $db)
    {
        $this->db = $db;
    }

    /**
     * Health check yapar
     *
     * @return array Health check sonuçları
     */
    public function check(): array
    {
        $results = [
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => [],
        ];

        // Database connection check
        $db_check = $this->check_database();
        $results['checks']['database'] = $db_check;
        if ($db_check['status'] !== 'healthy') {
            $results['status'] = 'unhealthy';
        }

        // Cache check
        $cache_check = $this->check_cache();
        $results['checks']['cache'] = $cache_check;
        if ($cache_check['status'] !== 'healthy') {
            $results['status'] = 'degraded';
        }

        // Memory check
        $memory_check = $this->check_memory();
        $results['checks']['memory'] = $memory_check;
        if ($memory_check['status'] !== 'healthy') {
            $results['status'] = 'degraded';
        }

        return $results;
    }

    /**
     * Database bağlantısını kontrol eder
     */
    private function check_database(): array
    {
        try {
            $start = microtime(true);
            $result = $this->db->get_row("SELECT 1 as health_check");
            $duration = microtime(true) - $start;

            if ($result && isset($result->health_check)) {
                return [
                    'status' => 'healthy',
                    'response_time' => round($duration * 1000, 2) . 'ms',
                    'message' => 'Database connection successful',
                ];
            }

            return [
                'status' => 'unhealthy',
                'message' => 'Database query failed',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => $e->getMessage(),
                'error' => get_class($e),
            ];
        }
    }

    /**
     * Cache'i kontrol eder
     */
    private function check_cache(): array
    {
        try {
            $cache_stats = $this->db->get_cache_stats();
            
            return [
                'status' => $cache_stats['enabled'] ? 'healthy' : 'disabled',
                'enabled' => $cache_stats['enabled'],
                'hit_rate' => $cache_stats['hit_rate'] . '%',
                'size' => $cache_stats['size'],
                'message' => $cache_stats['enabled'] ? 'Cache is operational' : 'Cache is disabled',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Memory durumunu kontrol eder
     */
    private function check_memory(): array
    {
        try {
            $memory_stats = $this->db->get_memory_stats();
            $usage_percent = ($memory_stats['current_usage'] / $memory_stats['limit']) * 100;

            $status = 'healthy';
            if ($usage_percent > 90) {
                $status = 'critical';
            } elseif ($usage_percent > 75) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'current_usage' => $this->format_bytes($memory_stats['current_usage']),
                'peak_usage' => $this->format_bytes($memory_stats['peak_usage']),
                'limit' => $this->format_bytes($memory_stats['limit']),
                'usage_percent' => round($usage_percent, 2) . '%',
                'message' => 'Memory usage is ' . $status,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unknown',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Bytes'ı okunabilir formata çevirir
     */
    private function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
