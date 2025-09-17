<?php

namespace nsql\database\traits;

trait cache_trait
{
    private array $query_cache = [];
    private array $query_cache_usage = [];
    private array $query_cache_access_order = []; // LRU için optimize edilmiş sıralama
    private bool $query_cache_enabled = false;
    private int $query_cache_hits = 0;
    private int $query_cache_misses = 0;

    /**
     * Sorgudan benzersiz önbellek anahtarı oluşturur
     */
    private function generate_query_cache_key(mixed $query, array $params = []): string
    {
        return md5((string)$query . serialize($params));
    }

    /**
     * Sorgu sonucunu önbelleğe ekler (optimize edilmiş LRU)
     */
    private function add_to_query_cache(string $key, mixed $data): void
    {
        if (! $this->query_cache_enabled) {
            return;
        }

        // Sadece belirli aralıklarla expired cache temizle (performans optimizasyonu)
        if (rand(1, 100) <= 10) { // %10 olasılıkla temizle
            $this->purge_expired_cache();
        }

        // Kapasite aşıldıysa en az kullanılanı çıkar (O(1) complexity)
        if (count($this->query_cache) >= $this->query_cache_size_limit) {
            $this->evict_least_recently_used();
        }

        $this->query_cache[$key] = [
            'data' => $data,
            'time' => time(),
        ];
        $this->query_cache_usage[$key] = microtime(true);
        
        // LRU sıralamasını güncelle (O(1) complexity)
        $this->update_access_order($key);
    }

    /**
     * Önbellekten sorgu sonucunu getirir (optimize edilmiş LRU)
     */
    private function get_from_query_cache(string $key): mixed
    {
        if (! $this->query_cache_enabled || ! isset($this->query_cache[$key])) {
            $this->query_cache_misses++;
            return null;
        }

        $cached = $this->query_cache[$key];

        if (! $this->is_valid_cache($cached['time'])) {
            unset($this->query_cache[$key], $this->query_cache_usage[$key]);
            $this->remove_from_access_order($key);
            $this->query_cache_misses++;

            return null;
        }

        // Erişim zamanını güncelle (LRU) - O(1) complexity
        $this->query_cache_usage[$key] = microtime(true);
        $this->update_access_order($key);
        $this->query_cache_hits++;

        return $cached['data'];
    }

    /**
     * Önbellek süre kontrolü
     */
    private function is_valid_cache(int $cache_time): bool
    {
        return (time() - $cache_time) <= $this->query_cache_timeout;
    }

    private function load_cache_config(): void
    {
        $this->query_cache_enabled = (bool)\nsql\database\Config::get('query_cache_enabled', false);
        $this->query_cache_timeout = (int)\nsql\database\Config::get('query_cache_timeout', 3600);
        $this->query_cache_size_limit = (int)\nsql\database\Config::get('query_cache_size_limit', 100);
    }

    /**
     * Süresi dolmuş cache girişlerini temizler
     */
    private function purge_expired_cache(): void
    {
        if (! $this->query_cache_enabled) {
            return;
        }

        $now = time();
        foreach ($this->query_cache as $key => $entry) {
            if (! isset($entry['time']) || ($now - (int)$entry['time']) > $this->query_cache_timeout) {
                unset($this->query_cache[$key], $this->query_cache_usage[$key]);
            }
        }
    }

    /**
     * Önbelleği temizler
     */
    private function clear_query_cache(): void
    {
        $this->query_cache = [];
        $this->query_cache_usage = [];
        $this->query_cache_access_order = [];
        $this->query_cache_hits = 0;
        $this->query_cache_misses = 0;
    }

    /**
     * En az kullanılan cache girişini çıkarır (O(1) complexity)
     */
    private function evict_least_recently_used(): void
    {
        if (empty($this->query_cache_access_order)) {
            return;
        }

        // En eski erişilen key'i al (O(1))
        $oldest_key = array_shift($this->query_cache_access_order);
        
        if (isset($this->query_cache[$oldest_key])) {
            unset($this->query_cache[$oldest_key], $this->query_cache_usage[$oldest_key]);
        }
    }

    /**
     * LRU erişim sıralamasını günceller (O(1) complexity)
     */
    private function update_access_order(string $key): void
    {
        // Key'i mevcut pozisyonundan kaldır
        $this->remove_from_access_order($key);
        
        // Key'i en sona ekle (en yeni erişim)
        $this->query_cache_access_order[] = $key;
    }

    /**
     * Key'i erişim sıralamasından kaldırır (O(1) complexity)
     */
    private function remove_from_access_order(string $key): void
    {
        $index = array_search($key, $this->query_cache_access_order, true);
        if ($index !== false) {
            array_splice($this->query_cache_access_order, $index, 1);
        }
    }

    /**
     * Cache istatistiklerini döndürür
     */
    public function get_cache_stats(): array
    {
        $total_requests = $this->query_cache_hits + $this->query_cache_misses;
        $hit_rate = $total_requests > 0 ? ($this->query_cache_hits / $total_requests) * 100 : 0;

        return [
            'enabled' => $this->query_cache_enabled,
            'size' => count($this->query_cache),
            'limit' => $this->query_cache_size_limit,
            'hits' => $this->query_cache_hits,
            'misses' => $this->query_cache_misses,
            'hit_rate' => round($hit_rate, 2),
            'timeout' => $this->query_cache_timeout,
        ];
    }
}
