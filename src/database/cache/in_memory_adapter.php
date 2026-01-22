<?php

namespace nsql\database\cache;

/**
 * In-Memory Cache Adapter
 * 
 * Mevcut cache_trait'in array-based cache'ini adapter pattern'e uyarlar
 */
class in_memory_adapter implements cache_adapter_interface
{
    private array $cache = [];
    private array $cache_tags = [];
    private array $tag_to_keys = [];
    private int $default_ttl;
    private int $size_limit;

    public function __construct(int $default_ttl = 3600, int $size_limit = 100)
    {
        $this->default_ttl = $default_ttl;
        $this->size_limit = $size_limit;
    }

    public function get(string $key): mixed
    {
        if (! isset($this->cache[$key])) {
            return null;
        }

        $entry = $this->cache[$key];
        
        // TTL kontrolü
        if (isset($entry['expires_at']) && $entry['expires_at'] < time()) {
            $this->delete($key);
            return null;
        }

        return $entry['value'] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        $ttl = $ttl ?? $this->default_ttl;
        $expires_at = time() + $ttl;

        // Size limit kontrolü
        if (count($this->cache) >= $this->size_limit && ! isset($this->cache[$key])) {
            // En eski entry'yi sil (basit LRU)
            $oldest_key = array_key_first($this->cache);
            if ($oldest_key !== null) {
                $this->delete($oldest_key);
            }
        }

        $this->cache[$key] = [
            'value' => $value,
            'expires_at' => $expires_at,
            'tags' => $tags,
        ];

        // Tag mapping
        if (! empty($tags)) {
            $this->cache_tags[$key] = $tags;
            foreach ($tags as $tag) {
                if (! isset($this->tag_to_keys[$tag])) {
                    $this->tag_to_keys[$tag] = [];
                }
                if (! in_array($key, $this->tag_to_keys[$tag])) {
                    $this->tag_to_keys[$tag][] = $key;
                }
            }
        }

        return true;
    }

    public function delete(string $key): bool
    {
        if (isset($this->cache[$key])) {
            // Tag mapping'lerini temizle
            if (isset($this->cache_tags[$key])) {
                foreach ($this->cache_tags[$key] as $tag) {
                    if (isset($this->tag_to_keys[$tag])) {
                        $this->tag_to_keys[$tag] = array_filter(
                            $this->tag_to_keys[$tag],
                            fn($k) => $k !== $key
                        );
                        if (empty($this->tag_to_keys[$tag])) {
                            unset($this->tag_to_keys[$tag]);
                        }
                    }
                }
                unset($this->cache_tags[$key]);
            }

            unset($this->cache[$key]);
            return true;
        }

        return false;
    }

    public function clear(): bool
    {
        $this->cache = [];
        $this->cache_tags = [];
        $this->tag_to_keys = [];
        return true;
    }

    public function invalidate_by_tag($tags): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $keys_to_delete = [];

        foreach ($tags as $tag) {
            if (isset($this->tag_to_keys[$tag])) {
                $keys_to_delete = array_merge($keys_to_delete, $this->tag_to_keys[$tag]);
                unset($this->tag_to_keys[$tag]);
            }
        }

        foreach (array_unique($keys_to_delete) as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        if (! isset($this->cache[$key])) {
            return false;
        }

        // TTL kontrolü
        $entry = $this->cache[$key];
        if (isset($entry['expires_at']) && $entry['expires_at'] < time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    public function is_available(): bool
    {
        return true; // In-memory cache her zaman kullanılabilir
    }

    public function get_name(): string
    {
        return 'in_memory';
    }
}
