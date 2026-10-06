<?php

namespace Tests\Support;

/**
 * RedisAdapter'ın kullandığı phpredis metotlarının süreç içi taklidi (redis eklentisi gerekmez).
 */
final class FakeRedis
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->data[$key] = $value;

        return true;
    }

    public function del(string ...$keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function exists(string $key): int
    {
        return array_key_exists($key, $this->data) ? 1 : 0;
    }

    public function sAdd(string $key, string $member): int
    {
        $set = $this->data[$key] ?? [];
        $set[$member] = true;
        $this->data[$key] = $set;

        return 1;
    }

    /** @return list<string> */
    public function sMembers(string $key): array
    {
        return array_keys($this->data[$key] ?? []);
    }

    public function expire(string $key, int $ttl): bool
    {
        return true;
    }

    /**
     * Tek turda tüm eşleşmeleri döndürür; iki anahtarlık sayfalar ile birden fazla tur taklit edilir.
     *
     * @return list<string>|false
     */
    public function scan(?int &$iterator, string $pattern, int $count = 0): array|false
    {
        // Gerçek SCAN gibi: tarama başında var olan anahtarlar, arada silme yapılsa da döner
        if (! $iterator) {
            $this->scan_snapshot = array_values(array_filter(array_keys($this->data), fn (string $k) => fnmatch($pattern, $k)));
        }
        $offset = (int) $iterator;
        $page = array_slice($this->scan_snapshot, $offset, 2);
        $iterator = $offset + 2 < count($this->scan_snapshot) ? $offset + 2 : 0;

        return $page;
    }

    /** @var list<string> */
    private array $scan_snapshot = [];

    public function flushDB(): bool
    {
        throw new \LogicException('flushDB kullanılmamalı');
    }
}
