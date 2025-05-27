<?php

namespace nsql\database\traits;

use nsql\database\Config;

trait cache_trait {
    private array $query_cache = [];
    private array $query_cache_usage = [];
    private bool $query_cache_enabled = false;

    /**
     * Sorgudan benzersiz önbellek anahtarı oluşturur
     */
    private function generate_query_cache_key($query, $params = []): string {
        return md5($query . serialize($params));
    }
    
    /**
     * Sorgu sonucunu önbelleğe ekler
     */
    private function add_to_query_cache(string $key, $data): void {
        if (!$this->query_cache_enabled) {
            return;
        }
        
        if (count($this->query_cache) >= $this->query_cache_size_limit) {
            array_shift($this->query_cache);
        }
        
        $this->query_cache[$key] = [
            'data' => $data,
            'time' => time()
        ];
    }
    
    /**
     * Önbellekten sorgu sonucunu getirir
     */
    private function get_from_query_cache(string $key) {
        if (!$this->query_cache_enabled || !isset($this->query_cache[$key])) {
            return null;
        }
        
        $cached = $this->query_cache[$key];
        
        if (!$this->is_valid_cache($cached['time'])) {
            unset($this->query_cache[$key]);
            return null;
        }
        
        return $cached['data'];
    }
    
    /**
     * Önbellek süre kontrolü
     */
    private function is_valid_cache(int $cache_time): bool {
        return (time() - $cache_time) <= $this->query_cache_timeout;
    }
    
    private function load_cache_config(): void {
        $this->query_cache_enabled = Config::get('QUERY_CACHE_ENABLED', false);
        $this->query_cache_timeout = Config::get('QUERY_CACHE_TIMEOUT', 3600);
        $this->query_cache_size_limit = Config::get('QUERY_CACHE_SIZE_LIMIT', 100);
    }
    
    /**
     * Önbelleği temizler
     */
    private function clear_query_cache(): void {
        $this->query_cache = [];
    }
}
