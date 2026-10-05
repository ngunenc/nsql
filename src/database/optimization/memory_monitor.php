<?php

namespace nsql\database\optimization;

use nsql\database\config;

/**
 * Süreç genelinde bellek eşikleri, istatistikleri ve uyarlanabilir chunk boyutu.
 *
 * Durum statiktir: tüm nsql örnekleri aynı PHP sürecinin belleğini paylaşır.
 */
final class memory_monitor
{
    private static ?int $last_check = null;
    private static int $chunk_size = config::default_chunk_size;

    /** @var array{peak_usage: int, warning_count: int, critical_count: int} */
    private static array $stats = [
        'peak_usage' => 0,
        'warning_count' => 0,
        'critical_count' => 0,
    ];

    /**
     * Bellek kullanımını MEMORY_CHECK_INTERVAL aralıklarla kontrol eder.
     * Uyarı eşiğinde $cleanup çağrılır; kritik eşikte $cleanup sonrası RuntimeException.
     *
     * @param callable(): void $cleanup
     * @param (callable(string, string): void)|null $debug_log
     * @throws \RuntimeException Kritik eşik aşıldığında
     */
    public static function check(callable $cleanup, ?callable $debug_log = null): void
    {
        $now = time();
        $interval = (int) config::get('memory_check_interval', config::memory_check_interval);
        if (self::$last_check !== null && ($now - self::$last_check) < $interval) {
            return;
        }
        self::$last_check = $now;

        $current = memory_get_usage(true);
        self::$stats['peak_usage'] = max(self::$stats['peak_usage'], memory_get_peak_usage(true));
        $thresholds = self::thresholds();

        if ($current > $thresholds['critical']) {
            self::$stats['critical_count']++;
            $cleanup();

            throw new \RuntimeException(sprintf(
                'Kritik bellek kullanımı aşıldı! Mevcut: %s, Limit: %s',
                self::format_bytes($current),
                self::format_bytes($thresholds['critical'])
            ));
        }

        if ($current > $thresholds['warning']) {
            self::$stats['warning_count']++;
            $cleanup();

            if ($debug_log !== null) {
                $debug_log('Memory Warning', sprintf(
                    'Bellek uyarı seviyesi aşıldı: %s (Limit: %s)',
                    self::format_bytes($current),
                    self::format_bytes($thresholds['warning'])
                ));
            }
        }
    }

    /**
     * Bellek eşikleri (byte).
     *
     * MEMORY_LIMIT_WARNING / MEMORY_LIMIT_CRITICAL açıkça ayarlanmışsa mutlak değer kullanılır;
     * aksi halde ini memory_limit × MEMORY_WARNING_RATIO (0.75) / MEMORY_CRITICAL_RATIO (0.9).
     * memory_limit=-1 ise kritik eşik yoktur, uyarı eşiği config::memory_limit_warning'dir.
     *
     * @return array{warning: int, critical: int}
     */
    public static function thresholds(): array
    {
        $limit = self::memory_limit();
        $unlimited = $limit === PHP_INT_MAX;

        $warning = config::has('memory_limit_warning')
            ? (int) config::get('memory_limit_warning')
            : ($unlimited ? config::memory_limit_warning : (int) ($limit * (float) config::get('memory_warning_ratio', 0.75)));

        $critical = config::has('memory_limit_critical')
            ? (int) config::get('memory_limit_critical')
            : ($unlimited ? PHP_INT_MAX : (int) ($limit * (float) config::get('memory_critical_ratio', 0.9)));

        return ['warning' => max(1, $warning), 'critical' => max(1, $critical)];
    }

    /**
     * ini memory_limit (byte); -1 ise PHP_INT_MAX.
     */
    public static function memory_limit(): int
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $bytes = self::parse_limit($limit);

        return $bytes > 0 ? $bytes : config::memory_limit_critical;
    }

    /**
     * "128M", "1G", "512k" gibi değerleri byte'a çevirir.
     */
    public static function parse_limit(string $limit): int
    {
        $limit = trim($limit);
        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    public static function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = (int) min(floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);

        return round($bytes / (1 << (10 * $pow)), 2) . ' ' . $units[$pow];
    }

    public static function max_result_set_size(): int
    {
        return (int) config::get('max_result_set_size', config::max_result_set_size);
    }

    public static function chunk_size(): int
    {
        return self::$chunk_size;
    }

    public static function set_chunk_size(int $size): void
    {
        self::$chunk_size = max(1, $size);
    }

    /**
     * AUTO_ADJUST_CHUNK_SIZE açıksa chunk boyutunu bellek kullanımına göre büyütür/küçültür.
     *
     * @param (callable(string, string): void)|null $debug_log
     */
    public static function adjust_chunk_size(?callable $debug_log = null): int
    {
        if (! (bool) config::get('auto_adjust_chunk_size', config::auto_adjust_chunk_size)) {
            return self::$chunk_size = (int) config::get('default_chunk_size', config::default_chunk_size);
        }

        $usage = memory_get_usage(true);
        $ratio = $usage / self::thresholds()['warning'];

        if ($ratio > 0.75) {
            self::$chunk_size = max(
                (int) config::get('min_chunk_size', config::min_chunk_size),
                (int) (self::$chunk_size * 0.6)
            );
        } elseif ($ratio < 0.4) {
            self::$chunk_size = min(
                (int) config::get('max_chunk_size', config::max_chunk_size),
                (int) (self::$chunk_size * 1.3)
            );
        }

        if ($debug_log !== null && $ratio > 0.7) {
            $debug_log('Chunk Size Adjustment', sprintf(
                'Chunk size ayarlandı: %d (Memory usage: %s, Ratio: %.2f)',
                self::$chunk_size,
                self::format_bytes($usage),
                $ratio
            ));
        }

        return self::$chunk_size;
    }

    /**
     * @return array<string, int>
     */
    public static function stats(): array
    {
        $thresholds = self::thresholds();

        return array_merge(self::$stats, [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'limit' => self::memory_limit(),
            'warning_threshold' => $thresholds['warning'],
            'critical_threshold' => $thresholds['critical'],
            'current_chunk_size' => self::$chunk_size,
        ]);
    }
}
