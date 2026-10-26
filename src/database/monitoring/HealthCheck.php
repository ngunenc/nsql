<?php

namespace nsql\database\monitoring;

use nsql\database\Nsql;
use nsql\security\PhpSecurityAdvisories;
use Psr\Log\LoggerInterface;

/**
 * Health Check
 *
 * Veritabanı ve sistem sağlık kontrolü. Yanıt dışarı açılabileceği için hata ayrıntısı
 * (exception mesajı / sınıfı) yanıta konmaz; logger'a veya error_log'a yazılır.
 */
class HealthCheck
{
    private Nsql $db;
    private ?LoggerInterface $logger;

    public function __construct(Nsql $db, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    private function report(string $check, \Throwable $e): void
    {
        $message = "nsql health check '{$check}' failed: " . get_class($e) . ': ' . $e->getMessage();
        if ($this->logger !== null) {
            $this->logger->error($message, ['check' => $check, 'exception' => $e]);

            return;
        }
        error_log($message);
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

        // PHP sürümü: genel durumu değiştirmez (dağıtım backport'larında yanlış alarm olabilir)
        $results['checks']['php'] = $this->check_php();

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
        } catch (\Throwable $e) {
            $this->report('database', $e);

            return [
                'status' => 'unhealthy',
                'message' => 'Database check failed',
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
        } catch (\Throwable $e) {
            $this->report('cache', $e);

            return [
                'status' => 'unhealthy',
                'message' => 'Cache check failed',
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
        } catch (\Throwable $e) {
            $this->report('memory', $e);

            return [
                'status' => 'unknown',
                'message' => 'Memory check failed',
            ];
        }
    }

    /**
     * Bytes'ı okunabilir formata çevirir
     */
    /**
     * Çalışan PHP sürümü bilinen çekirdek açıklarına karşı yamalı mı? Yanıta sürüm numarası
     * konmaz (dışarı açık endpoint'te parmak izi vermemek için); ayrıntı log'a yazılır.
     *
     * @param string $version Test için; varsayılan PHP_VERSION
     */
    public function check_php(string $version = PHP_VERSION): array
    {
        $advisory = PhpSecurityAdvisories::status($version);
        if ($advisory['status'] === 'ok') {
            return ['status' => 'healthy', 'message' => 'PHP version has no known critical advisories'];
        }

        $detail = $advisory['status'] === 'unsupported'
            ? "PHP {$advisory['branch']} güvenlik desteği almıyor; en az {$advisory['minimum']} sürümüne geçin"
            : "PHP {$version} bilinen kritik açıklar içeriyor (PHP2Shell CVE-2026-109618/109619); "
                . "en az {$advisory['minimum']} sürümüne güncelleyip PHP süreçlerini yeniden başlatın";
        $message = "nsql health check 'php': {$detail}. Dağıtım paketi yamayı backport etmiş olabilir.";
        if ($this->logger !== null) {
            $this->logger->warning($message, ['check' => 'php']);
        } else {
            error_log($message);
        }

        return [
            'status' => $advisory['status'],
            'message' => $advisory['status'] === 'unsupported'
                ? 'PHP branch no longer receives security fixes'
                : 'PHP security update required (or verify distribution backport)',
        ];
    }

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
