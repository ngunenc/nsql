<?php

namespace nsql\database\cache;

/**
 * Redis Cache Adapter
 *
 * Redis kullanarak distributed cache sağlar. Tüm anahtarlar (tag kümeleri dahil) `$prefix` ile
 * yazılır; clear() yalnızca bu öneke ait anahtarları siler, aynı Redis veritabanındaki diğer
 * uygulamaların verisine dokunmaz.
 */
class RedisAdapter implements CacheAdapterInterface
{
    /** @var \Redis|null */
    private ?object $redis = null;
    private string $host;
    private int $port;
    private int $timeout;
    private ?string $password;
    private int $database;
    private int $default_ttl;
    private string $prefix;
    private bool $connected = false;

    /**
     * @param \Redis|null $client Önceden bağlanmış istemci (paylaşılan bağlantı); verilirse host/port kullanılmaz
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $timeout = 5,
        ?string $password = null,
        int $database = 0,
        int $default_ttl = 3600,
        string $prefix = 'nsql_',
        ?object $client = null
    ) {
        if ($prefix === '') {
            throw new \InvalidArgumentException('Redis anahtar öneki boş olamaz (clear() yalnızca öneki siler).');
        }

        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->password = $password;
        $this->database = $database;
        $this->default_ttl = $default_ttl;
        $this->prefix = $prefix;

        if ($client !== null) {
            $this->redis = $client;
            $this->connected = true;
        }
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

    /**
     * @return \Redis
     */
    private function client(): object
    {
        assert($this->redis !== null);

        return $this->redis;
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    private function tag_key(string $tag): string
    {
        return $this->prefix . 'tag:' . $tag;
    }

    public function get(string $key): mixed
    {
        if (! $this->connect()) {
            return null;
        }

        try {
            $value = $this->client()->get($this->key($key));
            if (! is_string($value)) {
                return null;
            }

            return SafeSerializer::decode($value);
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
            $redis = $this->client();
            $ttl = $ttl ?? $this->default_ttl;
            $full_key = $this->key($key);

            $result = (bool) $redis->setex($full_key, $ttl, SafeSerializer::encode($value));

            foreach ($tags as $tag) {
                $tag_key = $this->tag_key((string) $tag);
                $redis->sAdd($tag_key, $full_key);
                $redis->expire($tag_key, $ttl);
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
            return (int) $this->client()->del($this->key($key)) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Yalnızca bu adaptörün önekine ait anahtarları siler (SCAN + DEL; FLUSHDB kullanılmaz).
     */
    public function clear(): bool
    {
        if (! $this->connect()) {
            return false;
        }

        try {
            $redis = $this->client();
            $pattern = self::escape_glob($this->prefix) . '*';
            $iterator = null;
            do {
                $keys = $redis->scan($iterator, $pattern, 1000);
                if (is_array($keys) && $keys !== []) {
                    $redis->del(...$keys);
                }
            } while ($iterator > 0);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function invalidate_by_tag($tags): bool
    {
        if (! $this->connect()) {
            return false;
        }

        $keys_to_delete = [];

        try {
            $redis = $this->client();
            foreach ((array) $tags as $tag) {
                $tag_key = $this->tag_key((string) $tag);
                $keys = $redis->sMembers($tag_key);

                if (is_array($keys)) {
                    $keys_to_delete = array_merge($keys_to_delete, $keys);
                    $redis->del($tag_key);
                }
            }

            if ($keys_to_delete !== []) {
                $redis->del(...array_unique($keys_to_delete));
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
            return (int) $this->client()->exists($this->key($key)) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function is_available(): bool
    {
        return $this->connect();
    }

    public function get_name(): string
    {
        return 'redis';
    }

    /**
     * Redis SCAN glob desenindeki özel karakterleri kaçışlar.
     */
    private static function escape_glob(string $value): string
    {
        return (string) preg_replace('/([\\\\*?\[\]])/', '\\\\$1', $value);
    }
}
