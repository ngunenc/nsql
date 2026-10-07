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
    private function dispatch_query_event(string $sql, array $params, int|float $started, ?PDOStatement $stmt, ?\Throwable $error, bool $row_count_known = true): void
    {
        $threshold = (float) Config::get('slow_query_threshold_ms', Config::slow_query_threshold_ms);
        if ($this->query_listeners === [] && $threshold <= 0) {
            return;
        }

        $duration_ms = (hrtime(true) - $started) / 1e6;
        $is_slow = $threshold > 0 && $duration_ms >= $threshold;
        if ($this->query_listeners === [] && ! $is_slow) {
            return;
        }

        $masked = SensitiveDataFilter::mask_params($sql, $params);
        $row_count = $row_count_known && $stmt !== null ? $stmt->rowCount() : null;

        if ($is_slow) {
            $this->log_error('Yavaş sorgu', [
                'sql' => $sql,
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
