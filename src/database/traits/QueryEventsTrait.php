<?php

namespace nsql\database\traits;

use nsql\database\Config;
use nsql\database\events\QueryEvent;
use nsql\database\logging\Logger;
use nsql\database\security\SensitiveDataFilter;
use PDOStatement;

/**
 * on_query() dinleyicileri ve SLOW_QUERY_THRESHOLD_MS ile yavaş sorgu logu.
 */
trait QueryEventsTrait
{
    /** @var list<callable(QueryEvent): void> */
    private array $query_listeners = [];

    /** @var array{total: int, slow: int, failed: int, time_ms: float} Bu örneğin sorgu sayaçları (Metrics) */
    private array $query_stats = ['total' => 0, 'slow' => 0, 'failed' => 0, 'time_ms' => 0.0];

    /**
     * Bu örnekte (ve varsa okuma replica'sında) çalışan sorguların sayaçları. Süreç başınadır:
     * yeni bir Nsql örneği sıfırdan başlar.
     *
     * @return array{total_queries: int, slow_queries: int, failed_queries: int, total_time_ms: float, average_execution_time: float}
     */
    public function get_query_stats(): array
    {
        $stats = $this->query_stats;
        if ($this->reader !== null) {
            foreach ($this->reader->query_stats as $key => $value) {
                $stats[$key] += $value;
            }
        }

        return [
            'total_queries' => (int) $stats['total'],
            'slow_queries' => (int) $stats['slow'],
            'failed_queries' => (int) $stats['failed'],
            'total_time_ms' => round((float) $stats['time_ms'], 3),
            'average_execution_time' => $stats['total'] > 0 ? round($stats['time_ms'] / $stats['total'], 3) : 0.0,
        ];
    }

    /**
     * Her veritabanı sorgusundan sonra çağrılacak dinleyici ekler (SQL, maskeli parametreler,
     * süre, satır sayısı, hata). Dinleyicide fırlatılan exception sorgu çağrısına yayılır.
     *
     * @param callable(QueryEvent): void $listener
     */
    public function on_query(callable $listener): static
    {
        $this->query_listeners[] = $listener;

        return $this;
    }

    public function clear_query_listeners(): static
    {
        $this->query_listeners = [];

        return $this;
    }

    /**
     * @param int|float $started hrtime(true) değeri
     */
    private function dispatch_query_event(
        string $sql,
        array $params,
        int|float $started,
        ?PDOStatement $stmt,
        ?\Throwable $error,
        bool $row_count_known = true
    ): void {
        $threshold = (float) $this->setting('slow_query_threshold_ms', Config::slow_query_threshold_ms);
        $duration_ms = (hrtime(true) - $started) / 1e6;
        $is_slow = $threshold > 0 && $duration_ms >= $threshold;

        // Sayaçlar dinleyici olmasa da tutulur (Metrics); erken dönüşlerden önce
        $this->query_stats['total']++;
        $this->query_stats['time_ms'] += $duration_ms;
        $this->query_stats['slow'] += $is_slow ? 1 : 0;
        $this->query_stats['failed'] += $error !== null ? 1 : 0;

        if ($this->query_listeners === [] && ! $is_slow) {
            return;
        }

        $masked = SensitiveDataFilter::mask_params($sql, $params);
        $row_count = $row_count_known && $stmt !== null ? $stmt->rowCount() : null;

        if ($is_slow) {
            $this->log_error('Yavaş sorgu', [
                'sql' => SensitiveDataFilter::mask_sql_literals($sql),
                'params' => $masked,
                'duration_ms' => round($duration_ms, 2),
                'threshold_ms' => $threshold,
                'row_count' => $row_count,
            ], Logger::WARNING);
        }

        if ($this->query_listeners === []) {
            return;
        }

        $event = new QueryEvent(
            $sql,
            $masked,
            $duration_ms,
            $row_count,
            $error === null,
            $error,
            $this->get_driver_name()
        );
        foreach ($this->query_listeners as $listener) {
            $listener($event);
        }
    }
}
