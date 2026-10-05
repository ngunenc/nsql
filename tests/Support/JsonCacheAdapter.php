<?php

namespace Tests\Support;

use nsql\database\cache\cache_adapter_interface;
use nsql\database\cache\safe_serializer;

/**
 * redis_adapter gibi değerleri JSON (safe_serializer) ile saklayan paylaşılan adaptör taklidi.
 */
final class JsonCacheAdapter implements cache_adapter_interface
{
    /** @var array<string, string> */
    public array $items = [];

    public function get(string $key): mixed
    {
        return isset($this->items[$key]) ? safe_serializer::decode($this->items[$key]) : null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        $this->items[$key] = safe_serializer::encode($value);

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
