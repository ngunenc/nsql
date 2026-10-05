<?php

namespace nsql\database\cache;

use Psr\SimpleCache\CacheInterface;

/**
 * cache_adapter_interface (redis_adapter, memcached_adapter, in_memory_adapter, cache_manager
 * ile sarılmış adaptörler) için PSR-16 köprüsü. set_query_cache_store() ile kullanılır.
 *
 * Adaptörler null değeri "yok" olarak döndürdüğünden null saklamak desteklenmez.
 */
final class adapter_simple_cache implements CacheInterface
{
    /** TTL verilmeyen kayıtlar (sürüm token'ları) için süre; Memcached'in göreli TTL sınırı */
    public const FOREVER_TTL = 2592000;

    public function __construct(private cache_adapter_interface $adapter)
    {
    }

    public function adapter(): cache_adapter_interface
    {
        return $this->adapter;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->adapter->get($key) ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = self::seconds($ttl);
        if ($seconds <= 0) {
            $this->adapter->delete($key);

            return true;
        }

        return $this->adapter->set($key, $value, $seconds);
    }

    public function delete(string $key): bool
    {
        $this->adapter->delete($key);

        return true;
    }

    public function clear(): bool
    {
        return $this->adapter->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->adapter->has($key);
    }

    private static function seconds(null|int|\DateInterval $ttl): int
    {
        if ($ttl === null) {
            return self::FOREVER_TTL;
        }
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return min($ttl, self::FOREVER_TTL);
    }
}
