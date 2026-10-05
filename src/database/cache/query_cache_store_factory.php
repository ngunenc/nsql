<?php

namespace nsql\database\cache;

use nsql\database\config;
use Psr\SimpleCache\CacheInterface;

/**
 * QUERY_CACHE_DRIVER ayarından paylaşılan query cache store'u üretir.
 *
 * - memory (varsayılan): store yok; cache yalnızca o PHP sürecinde yaşar (FPM worker'ları arasında
 *   paylaşılmaz, istek bitince silinir).
 * - redis / memcached: süreçler arası paylaşılan store. Production için önerilen: redis.
 *
 * Store süreç başına bir kez kurulur (tek bağlantı). Sunucuya ulaşılamazsa veya sürücü adı
 * geçersizse $warn çağrılır ve process içi cache ile devam edilir.
 */
final class query_cache_store_factory
{
    /** @var array<string, CacheInterface|null> */
    private static array $stores = [];

    /**
     * @param callable(string): void $warn
     */
    public static function from_config(callable $warn): ?CacheInterface
    {
        $driver = strtolower(trim((string) config::get('query_cache_driver', config::query_cache_driver)));
        if ($driver === '' || $driver === 'memory' || $driver === 'in_memory') {
            return null;
        }

        $signature = $driver . '|' . self::signature($driver);
        if (array_key_exists($signature, self::$stores)) {
            return self::$stores[$signature];
        }

        $adapter = match ($driver) {
            'redis' => new redis_adapter(
                (string) config::get('redis_host', '127.0.0.1'),
                (int) config::get('redis_port', 6379),
                (int) config::get('redis_timeout', 2),
                ($password = config::get('redis_password')) !== null && $password !== '' ? (string) $password : null,
                (int) config::get('redis_database', 0)
            ),
            'memcached' => new memcached_adapter([[
                (string) config::get('memcached_host', '127.0.0.1'),
                (int) config::get('memcached_port', 11211),
            ]]),
            default => null,
        };

        if ($adapter === null) {
            $warn("Geçersiz QUERY_CACHE_DRIVER '{$driver}' (memory, redis, memcached); process içi cache kullanılıyor.");

            return self::$stores[$signature] = null;
        }

        if (! $adapter->is_available()) {
            $warn("QUERY_CACHE_DRIVER={$driver} ama sunucuya ulaşılamadı veya PHP eklentisi yüklü değil; "
                . 'query cache yalnızca bu süreçte tutuluyor (worker\'lar arası paylaşılmaz).');

            return self::$stores[$signature] = null;
        }

        return self::$stores[$signature] = new adapter_simple_cache($adapter);
    }

    public static function reset(): void
    {
        self::$stores = [];
    }

    private static function signature(string $driver): string
    {
        return match ($driver) {
            'redis' => config::get('redis_host') . ':' . config::get('redis_port') . '/' . config::get('redis_database'),
            'memcached' => config::get('memcached_host') . ':' . config::get('memcached_port'),
            default => '',
        };
    }
}
