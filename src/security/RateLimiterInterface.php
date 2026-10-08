<?php

namespace nsql\security;

/**
 * Token bucket hız sınırlayıcı (veritabanı: RateLimiter, Redis: RedisRateLimiter).
 */
interface RateLimiterInterface
{
    /**
     * İsteğe izin verilip verilmediğini döndürür ve izin verildiyse bir token harcar.
     */
    public function check_rate_limit(string $identifier, string $request_type = 'default'): bool;

    /**
     * Saniyede eklenen token sayısı.
     */
    public function refill_rate(): float;

    /**
     * Son isteği verilen süreden eski kayıtları temizler; silinen kayıt sayısını döndürür.
     */
    public function purge(?int $older_than_seconds = null): int;
}
