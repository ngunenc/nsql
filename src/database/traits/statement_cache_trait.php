<?php

namespace nsql\database\traits;

use PDOStatement;

trait statement_cache_trait
{
    private array $statement_cache = [];
    private array $statement_cache_usage = [];
    private array $statement_cache_access_order = []; // LRU için optimize edilmiş sıralama
    private array $statement_cache_frequency = []; // LFU için kullanım sıklığı
    private int $statement_cache_hits = 0;
    private int $statement_cache_misses = 0;
    private bool $use_lfu_algorithm = false; // LFU algoritması kullanılsın mı?
    private int $base_cache_limit = 50; // Temel cache limit

    /**
     * Statement cache anahtarı oluşturur
     */
    private function get_statement_cache_key(string $sql, array $params): string
    {
        return md5($sql . '|' . serialize(array_keys($params)) . '|' . serialize(array_map('gettype', $params)));
    }

    /**
     * Statement'ı önbelleğe ekler (LRU veya LFU algoritması ile)
     */
    private function add_to_statement_cache(string $key, PDOStatement $stmt): void
    {
        // Dinamik cache size ayarla (memory kullanımına göre)
        $this->adjust_cache_size();
        
        $this->statement_cache[$key] = $stmt;
        $this->statement_cache_usage[$key] = microtime(true);
        
        // LFU için frequency başlat
        if ($this->use_lfu_algorithm && !isset($this->statement_cache_frequency[$key])) {
            $this->statement_cache_frequency[$key] = 0;
        }

        // Kapasite aşıldıysa en az kullanılanı çıkar
        $current_limit = $this->get_dynamic_cache_limit();
        if (count($this->statement_cache) > $current_limit) {
            if ($this->use_lfu_algorithm) {
                $this->evict_least_frequently_used_statement();
            } else {
                $this->evict_least_recently_used_statement();
            }
        }

        // LRU sıralamasını güncelle (O(1) complexity)
        $this->update_statement_access_order($key);
    }

    /**
     * Statement'ı önbellekten alır (LRU veya LFU algoritması ile)
     */
    private function get_from_statement_cache(string $key): ?PDOStatement
    {
        if (! isset($this->statement_cache[$key])) {
            $this->statement_cache_misses++;
            return null;
        }

        $this->statement_cache_usage[$key] = microtime(true);
        
        // LFU için frequency artır
        if ($this->use_lfu_algorithm) {
            $this->statement_cache_frequency[$key] = ($this->statement_cache_frequency[$key] ?? 0) + 1;
        }
        
        $this->update_statement_access_order($key);
        $this->statement_cache_hits++;

        return $this->statement_cache[$key];
    }

    /**
     * Statement önbelleğini temizler
     */
    public function clear_statement_cache(): void
    {
        $this->statement_cache = [];
        $this->statement_cache_usage = [];
        $this->statement_cache_access_order = [];
        $this->statement_cache_frequency = [];
        $this->statement_cache_hits = 0;
        $this->statement_cache_misses = 0;
    }

    /**
     * En az kullanılan statement cache girişini çıkarır (O(1) complexity)
     */
    private function evict_least_recently_used_statement(): void
    {
        if (empty($this->statement_cache_access_order)) {
            return;
        }

        // En eski erişilen key'i al (O(1))
        $oldest_key = array_shift($this->statement_cache_access_order);
        
        if (isset($this->statement_cache[$oldest_key])) {
            unset($this->statement_cache[$oldest_key], $this->statement_cache_usage[$oldest_key]);
        }
    }

    /**
     * Statement LRU erişim sıralamasını günceller (O(1) complexity)
     */
    private function update_statement_access_order(string $key): void
    {
        // Key'i mevcut pozisyonundan kaldır
        $this->remove_from_statement_access_order($key);
        
        // Key'i en sona ekle (en yeni erişim)
        $this->statement_cache_access_order[] = $key;
    }

    /**
     * Key'i statement erişim sıralamasından kaldırır (O(1) complexity)
     */
    private function remove_from_statement_access_order(string $key): void
    {
        $index = array_search($key, $this->statement_cache_access_order, true);
        if ($index !== false) {
            array_splice($this->statement_cache_access_order, $index, 1);
        }
    }

    /**
     * En az sıklıkla kullanılan statement'ı çıkarır (LFU algoritması)
     */
    private function evict_least_frequently_used_statement(): void
    {
        if (empty($this->statement_cache)) {
            return;
        }

        // En düşük frequency'ye sahip key'i bul
        $min_frequency = PHP_INT_MAX;
        $evict_key = null;
        
        foreach ($this->statement_cache_frequency as $key => $frequency) {
            if (!isset($this->statement_cache[$key])) {
                continue;
            }
            
            // Aynı frequency'de ise en eski erişim zamanını kullan
            if ($frequency < $min_frequency || 
                ($frequency === $min_frequency && $evict_key !== null && 
                 ($this->statement_cache_usage[$key] ?? 0) < ($this->statement_cache_usage[$evict_key] ?? 0))) {
                $min_frequency = $frequency;
                $evict_key = $key;
            }
        }
        
        if ($evict_key !== null) {
            unset($this->statement_cache[$evict_key], 
                  $this->statement_cache_usage[$evict_key],
                  $this->statement_cache_frequency[$evict_key]);
            $this->remove_from_statement_access_order($evict_key);
        }
    }
    
    /**
     * Dinamik cache size ayarlar (memory kullanımına göre)
     */
    private function adjust_cache_size(): void
    {
        // Config'den dinamik cache size ayarını kontrol et
        $dynamic_cache_enabled = \nsql\database\config::get('statement_cache_dynamic_size', false);
        if (!$dynamic_cache_enabled) {
            return;
        }
        
        $memory_usage = memory_get_usage(true);
        $memory_limit = \nsql\database\config::get('memory_limit_warning', 384 * 1024 * 1024);
        $usage_ratio = $memory_usage / $memory_limit;
        
        // Memory kullanımı yüksekse cache limit'i azalt
        if ($usage_ratio > 0.75) {
            $this->base_cache_limit = max(10, (int)($this->base_cache_limit * 0.7));
        } elseif ($usage_ratio < 0.4) {
            // Memory kullanımı düşükse cache limit'i artır
            $this->base_cache_limit = min(200, (int)($this->base_cache_limit * 1.2));
        }
    }
    
    /**
     * Dinamik cache limit'i döndürür
     */
    private function get_dynamic_cache_limit(): int
    {
        $dynamic_cache_enabled = \nsql\database\config::get('statement_cache_dynamic_size', false);
        if ($dynamic_cache_enabled) {
            return $this->base_cache_limit;
        }
        return $this->statement_cache_limit;
    }
    
    /**
     * LFU algoritmasını etkinleştir/devre dışı bırak
     * 
     * @param bool $enabled LFU algoritması aktif edilsin mi?
     * @return void
     */
    public function set_lfu_algorithm(bool $enabled): void
    {
        $this->use_lfu_algorithm = $enabled;
    }
    
    /**
     * Statement cache istatistiklerini döndürür
     * 
     * @return array{
     *     size: int,
     *     limit: int,
     *     base_limit: int,
     *     hits: int,
     *     misses: int,
     *     hit_rate: float,
     *     algorithm: 'LRU'|'LFU'
     * }
     */
    public function get_statement_cache_stats(): array
    {
        $total_requests = $this->statement_cache_hits + $this->statement_cache_misses;
        $hit_rate = $total_requests > 0 ? ($this->statement_cache_hits / $total_requests) * 100 : 0;

        return [
            'size' => count($this->statement_cache),
            'limit' => $this->get_dynamic_cache_limit(),
            'base_limit' => $this->base_cache_limit,
            'hits' => $this->statement_cache_hits,
            'misses' => $this->statement_cache_misses,
            'hit_rate' => round($hit_rate, 2),
            'algorithm' => $this->use_lfu_algorithm ? 'LFU' : 'LRU',
        ];
    }
}
