<?php

namespace Tests\Support;

/**
 * MemcachedAdapter'ın kullandığı Memcached metotlarının süreç içi taklidi (memcached eklentisi gerekmez).
 */
final class FakeMemcached
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, int> anahtar => CAS token'ı (her yazmada artar) */
    private array $cas = [];
    private int $cas_counter = 0;

    /**
     * Bir tag anahtarına ilk yazma girişiminden (set / add / cas) hemen önce bir kez çağrılır:
     * okuma ile yazma arasına giren başka bir istemciyi taklit eder.
     *
     * @var (callable(self, string): void)|null
     */
    public $before_tag_write = null;

    private function interleave(string $key): void
    {
        if ($this->before_tag_write !== null && str_contains($key, 'tag:')) {
            $hook = $this->before_tag_write;
            $this->before_tag_write = null;
            $hook($this, $key);
        }
    }

    /**
     * @param int $flags 2 = \Memcached::GET_EXTENDED (['value' => …, 'cas' => …])
     */
    public function get(string $key, mixed $callback = null, int $flags = 0): mixed
    {
        if (! array_key_exists($key, $this->data)) {
            return false;
        }

        return $flags === 2 ? ['value' => $this->data[$key], 'cas' => $this->cas[$key] ?? 0, 'flags' => 0] : $this->data[$key];
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (strlen($key) > 250 || preg_match('/[\s\x00-\x1f]/', $key)) {
            return false;
        }
        $this->interleave($key);
        $this->data[$key] = $value;
        $this->cas[$key] = ++$this->cas_counter;

        return true;
    }

    public function cas(int|float|string $token, string $key, mixed $value, int $ttl = 0): bool
    {
        $this->interleave($key);
        if (! array_key_exists($key, $this->data) || ($this->cas[$key] ?? 0) !== (int) $token) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->interleave($key);
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
