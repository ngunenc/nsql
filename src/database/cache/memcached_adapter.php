<?php

namespace nsql\database\cache;

/**
 * Memcached Cache Adapter
 * 
 * Memcached kullanarak distributed cache sağlar
 */
class memcached_adapter implements cache_adapter_interface
{
    private ?\Memcached $memcached = null;
    private array $servers;
    private int $default_ttl;
    private bool $connected = false;

    public function __construct(array $servers = [['127.0.0.1', 11211]], int $default_ttl = 3600)
    {
        $this->servers = $servers;
        $this->default_ttl = $default_ttl;
    }

    /**
     * Memcached bağlantısını oluşturur
     */
    private function connect(): bool
    {
        if ($this->connected && $this->memcached !== null) {
            return true;
        }

        if (! extension_loaded('memcached')) {
            return false;
        }

        try {
            $this->memcached = new \Memcached();
            $this->memcached->addServers($this->servers);
            
            // Consistency hash kullan (daha iyi dağıtım)
            $this->memcached->setOption(\Memcached::OPT_DISTRIBUTION, \Memcached::DISTRIBUTION_CONSISTENT);
            $this->memcached->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);

            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            $this->memcached = null;
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
            $value = $this->memcached->get($key);
            
            if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
                return null;
            }

            return $value;
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
            $ttl = $ttl ?? $this->default_ttl;
            
            // Memcached TTL maksimum 30 gün (2592000 saniye)
            if ($ttl > 2592000) {
                $ttl = 2592000;
            }

            $result = $this->memcached->set($key, $value, $ttl);

            // Tag'leri ayrı key'lerde sakla (Memcached'de set yok)
            if (! empty($tags) && $result) {
                foreach ($tags as $tag) {
                    $tag_key = "tag:{$tag}";
                    $tag_keys = $this->memcached->get($tag_key);
                    
                    if ($tag_keys === false) {
                        $tag_keys = [];
                    }
                    
                    if (! in_array($key, $tag_keys)) {
                        $tag_keys[] = $key;
                        $this->memcached->set($tag_key, $tag_keys, $ttl);
                    }
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
            return $this->memcached->delete($key);
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
            return $this->memcached->flush();
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
                $keys = $this->memcached->get($tag_key);
                
                if ($keys !== false && is_array($keys)) {
                    $keys_to_delete = array_merge($keys_to_delete, $keys);
                    $this->memcached->delete($tag_key);
                }
            }

            if (! empty($keys_to_delete)) {
                foreach (array_unique($keys_to_delete) as $key) {
                    $this->memcached->delete($key);
                }
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
            $this->memcached->get($key);
            return $this->memcached->getResultCode() === \Memcached::RES_SUCCESS;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function is_available(): bool
    {
        return extension_loaded('memcached') && $this->connect();
    }

    public function get_name(): string
    {
        return 'memcached';
    }
}
