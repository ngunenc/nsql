<?php

namespace nsql\database\events;

/**
 * Veritabanında çalıştırılan her sorgu için on_query() dinleyicilerine iletilen olay.
 * Cache'ten dönen sonuçlar için olay üretilmez.
 */
final class QueryEvent
{
    /**
     * @param array<int|string, mixed> $params Hassas anahtarları maskelenmiş parametreler
     * @param int|null $row_count Etkilenen/dönen satır sayısı; unbuffered akışta bilinmez (null)
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params,
        public readonly float $duration_ms,
        public readonly ?int $row_count,
        public readonly bool $success,
        public readonly ?\Throwable $error,
        public readonly string $driver
    ) {
    }
}
