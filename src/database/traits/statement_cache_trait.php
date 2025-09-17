<?php

namespace nsql\database\traits;

use PDOStatement;

trait statement_cache_trait
{
    private array $statement_cache = [];
    private array $statement_cache_usage = [];
    private array $statement_cache_access_order = []; // LRU için optimize edilmiş sıralama
    private int $statement_cache_hits = 0;
    private int $statement_cache_misses = 0;

    /**
     * Statement cache anahtarı oluşturur
     */
    private function get_statement_cache_key(string $sql, array $params): string
    {
        return md5($sql . '|' . serialize(array_keys($params)) . '|' . serialize(array_map('gettype', $params)));
    }

    /**
     * Statement'ı önbelleğe ekler (optimize edilmiş LRU)
     */
    private function add_to_statement_cache(string $key, PDOStatement $stmt): void
    {
        $this->statement_cache[$key] = $stmt;
        $this->statement_cache_usage[$key] = microtime(true);

        // Kapasite aşıldıysa en az kullanılanı çıkar (O(1) complexity)
        if (count($this->statement_cache) > $this->statement_cache_limit) {
            $this->evict_least_recently_used_statement();
        }

        // LRU sıralamasını güncelle (O(1) complexity)
        $this->update_statement_access_order($key);
    }

    /**
     * Statement'ı önbellekten alır (optimize edilmiş LRU)
     */
    private function get_from_statement_cache(string $key): ?PDOStatement
    {
        if (! isset($this->statement_cache[$key])) {
            $this->statement_cache_misses++;
            return null;
        }

        $this->statement_cache_usage[$key] = microtime(true);
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
     * Statement cache istatistiklerini döndürür
     */
    public function get_statement_cache_stats(): array
    {
        $total_requests = $this->statement_cache_hits + $this->statement_cache_misses;
        $hit_rate = $total_requests > 0 ? ($this->statement_cache_hits / $total_requests) * 100 : 0;

        return [
            'size' => count($this->statement_cache),
            'limit' => $this->statement_cache_limit,
            'hits' => $this->statement_cache_hits,
            'misses' => $this->statement_cache_misses,
            'hit_rate' => round($hit_rate, 2),
        ];
    }
}
