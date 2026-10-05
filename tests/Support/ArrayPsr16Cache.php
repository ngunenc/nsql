<?php

namespace Tests\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * Süreçler arası paylaşılan bir store'u (Redis vb.) taklit eden PSR-16 test double'ı.
 * Değerler gerçek arka uçlar gibi serialize edilerek saklanır.
 */
final class ArrayPsr16Cache implements CacheInterface
{
    /** @var array<string, array{0: string, 1: int|null}> */
    public array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->has($key)) {
            return $default;
        }

        return unserialize($this->items[$key][0]);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $expires = is_int($ttl) ? time() + $ttl : null;
        $this->items[$key] = [serialize($value), $expires];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
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
        if (! isset($this->items[$key])) {
            return false;
        }

        $expires = $this->items[$key][1];
        if ($expires !== null && $expires < time()) {
            unset($this->items[$key]);

            return false;
        }

        return true;
    }
}
