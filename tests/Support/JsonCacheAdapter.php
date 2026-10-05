<?php

namespace Tests\Support;

use nsql\database\cache\CacheAdapterInterface;
use nsql\database\cache\SafeSerializer;

/**
 * redis_adapter gibi değerleri JSON (safe_serializer) ile saklayan paylaşılan adaptör taklidi.
 */
final class JsonCacheAdapter implements CacheAdapterInterface
{
    /** @var array<string, string> */
    public array $items = [];

    public function get(string $key): mixed
    {
        return isset($this->items[$key]) ? SafeSerializer::decode($this->items[$key]) : null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        $this->items[$key] = SafeSerializer::encode($value);

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

    public function invalidate_by_tag($tags): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->items[$key]);
    }

    public function is_available(): bool
    {
        return true;
    }

    public function get_name(): string
    {
        return 'json_test';
    }
}
