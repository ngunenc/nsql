<?php

namespace nsql\database\cache;

/**
 * Cache Manager
 * 
 * Strategy pattern ile cache adapter'ları yönetir
 * Fallback mekanizması ile primary adapter başarısız olursa secondary adapter kullanır
 */
class cache_manager
{
    private ?cache_adapter_interface $primary_adapter = null;
    private ?cache_adapter_interface $fallback_adapter = null;
    private bool $use_fallback = true;

    public function __construct(
        ?cache_adapter_interface $primary_adapter = null,
        ?cache_adapter_interface $fallback_adapter = null,
        bool $use_fallback = true
    ) {
        $this->primary_adapter = $primary_adapter;
        $this->fallback_adapter = $fallback_adapter;
        $this->use_fallback = $use_fallback;
    }

    /**
     * Primary adapter'ı ayarlar
     */
    public function set_primary_adapter(cache_adapter_interface $adapter): void
    {
        $this->primary_adapter = $adapter;
    }

    /**
     * Fallback adapter'ı ayarlar
     */
    public function set_fallback_adapter(cache_adapter_interface $adapter): void
    {
        $this->fallback_adapter = $adapter;
    }

    /**
     * Aktif adapter'ı döndürür (primary kullanılabilir değilse fallback)
     */
    private function get_active_adapter(): ?cache_adapter_interface
    {
        if ($this->primary_adapter && $this->primary_adapter->is_available()) {
            return $this->primary_adapter;
        }

        if ($this->use_fallback && $this->fallback_adapter && $this->fallback_adapter->is_available()) {
            return $this->fallback_adapter;
        }

        return null;
    }

    /**
     * Cache'den değer alır
     */
    public function get(string $key): mixed
    {
        $adapter = $this->get_active_adapter();
        if (! $adapter) {
            return null;
        }

        return $adapter->get($key);
    }

    /**
     * Cache'e değer yazar
     */
    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        $adapter = $this->get_active_adapter();
        if (! $adapter) {
            return false;
        }

        $result = $adapter->set($key, $value, $ttl, $tags);

        // Fallback adapter varsa ve primary başarısız olduysa fallback'e de yaz
        if (! $result && $this->use_fallback && $this->fallback_adapter && $this->fallback_adapter->is_available()) {
            return $this->fallback_adapter->set($key, $value, $ttl, $tags);
        }

        return $result;
    }

    /**
     * Cache'den değer siler
     */
    public function delete(string $key): bool
    {
        $adapter = $this->get_active_adapter();
        if (! $adapter) {
            return false;
        }

        $result = $adapter->delete($key);

        // Fallback adapter'da da sil
        if ($this->use_fallback && $this->fallback_adapter && $this->fallback_adapter->is_available()) {
            $this->fallback_adapter->delete($key);
        }

        return $result;
    }

    /**
     * Tüm cache'i temizler
     */
    public function clear(): bool
    {
        $result = true;

        if ($this->primary_adapter && $this->primary_adapter->is_available()) {
            $result = $this->primary_adapter->clear() && $result;
        }

        if ($this->use_fallback && $this->fallback_adapter && $this->fallback_adapter->is_available()) {
            $result = $this->fallback_adapter->clear() && $result;
        }

        return $result;
    }

    /**
     * Tag'e göre cache'leri temizler
     */
    public function invalidate_by_tag($tags): bool
    {
        $result = true;

        if ($this->primary_adapter && $this->primary_adapter->is_available()) {
            $result = $this->primary_adapter->invalidate_by_tag($tags) && $result;
        }

        if ($this->use_fallback && $this->fallback_adapter && $this->fallback_adapter->is_available()) {
            $result = $this->fallback_adapter->invalidate_by_tag($tags) && $result;
        }

        return $result;
    }

    /**
     * Cache'in mevcut olup olmadığını kontrol eder
     */
    public function has(string $key): bool
    {
        $adapter = $this->get_active_adapter();
        if (! $adapter) {
            return false;
        }

        return $adapter->has($key);
    }

    /**
     * Aktif adapter adını döndürür
     */
    public function get_active_adapter_name(): ?string
    {
        $adapter = $this->get_active_adapter();
        return $adapter ? $adapter->get_name() : null;
    }

    /**
     * Adapter durumlarını döndürür
     */
    public function get_status(): array
    {
        return [
            'primary' => [
                'name' => $this->primary_adapter?->get_name(),
                'available' => $this->primary_adapter?->is_available() ?? false,
            ],
            'fallback' => [
                'name' => $this->fallback_adapter?->get_name(),
                'available' => $this->fallback_adapter?->is_available() ?? false,
            ],
            'active' => $this->get_active_adapter_name(),
        ];
    }
}
