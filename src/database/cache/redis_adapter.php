<?php

namespace nsql\database\cache;

/**
 * Redis Cache Adapter
 * 
 * Redis kullanarak distributed cache sağlar
 */
class redis_adapter implements cache_adapter_interface
{
    private ?\Redis $redis = null;
    private string $host;
    private int $port;
    private int $timeout;
    private ?string $password;
    private int $database;
    private int $default_ttl;
    private bool $connected = false;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $timeout = 5,
        ?string $password = null,
        int $database = 0,
        int $default_ttl = 3600
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->password = $password;
        $this->database = $database;
        $this->default_ttl = $default_ttl;
    }

    /**
     * Redis bağlantısını oluşturur
     */
    private function connect(): bool
    {
        if ($this->connected && $this->redis !== null) {
            return true;
        }

        if (! extension_loaded('redis')) {
            return false;
        }

        try {
            $this->redis = new \Redis();
            $connected = $this->redis->connect($this->host, $this->port, $this->timeout);
            
            if (! $connected) {
                $this->redis = null;
                return false;
            }

            if ($this->password !== null) {
                $this->redis->auth($this->password);
            }

            if ($this->database > 0) {
                $this->redis->select($this->database);
            }

            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            $this->redis = null;
            $this->connected = false;
            return false;
        }
    }

    public function get(string $key): mixed
    {
        if (! $this->connect()) {
            return null;
        }

        try {
            $value = $this->redis->get($key);
            if ($value === false) {
                return null;
            }

            return unserialize($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            $serialized = serialize($value);
            $ttl = $ttl ?? $this->default_ttl;

            $result = $this->redis->setex($key, $ttl, $serialized);

            // Tag'leri set olarak sakla
            if (! empty($tags)) {
                foreach ($tags as $tag) {
                    $tag_key = "tag:{$tag}";
                    $this->redis->sAdd($tag_key, $key);
                    $this->redis->expire($tag_key, $ttl);
                }
            }

            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            return $this->redis->del($key) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function clear(): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            return $this->redis->flushDB();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function invalidate_by_tag($tags): bool
    {
        if (! $this->connect()) {
            return false;
        }

        $tags = is_array($tags) ? $tags : [$tags];
        $keys_to_delete = [];

        try {
            foreach ($tags as $tag) {
                $tag_key = "tag:{$tag}";
                $keys = $this->redis->sMembers($tag_key);
                
                if ($keys !== false) {
                    $keys_to_delete = array_merge($keys_to_delete, $keys);
                    $this->redis->del($tag_key);
                }
            }

            if (! empty($keys_to_delete)) {
                $this->redis->del(...array_unique($keys_to_delete));
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            return $this->redis->exists($key) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function is_available(): bool
    {
        return extension_loaded('redis') && $this->connect();
    }

    public function get_name(): string
    {
        return 'redis';
    }
}
