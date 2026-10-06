<?php

namespace Tests\Support;

/**
 * MemcachedAdapter'ın kullandığı Memcached metotlarının süreç içi taklidi (memcached eklentisi gerekmez).
 */
final class FakeMemcached
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? false;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (strlen($key) > 250 || preg_match('/[\s\x00-\x1f]/', $key)) {
            return false;
        }
        $this->data[$key] = $value;

        return true;
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        if (array_key_exists($key, $this->data)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        $existed = array_key_exists($key, $this->data);
        unset($this->data[$key]);

        return $existed;
    }

    public function flush(): bool
    {
        throw new \LogicException('flush kullanılmamalı');
    }
}
