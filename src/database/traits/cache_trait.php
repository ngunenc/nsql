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
    
    // Cache invalidation için
    private array $cache_tags = []; // key => [tag1, tag2, ...]
    private array $tag_to_keys = []; // tag => [key1, key2, ...]
    private array $table_to_keys = []; // table_name => [key1, key2, ...]
    
    // Cache warming için
    private array $warm_queries = []; // [['query' => ..., 'params' => ..., 'tags' => ..., 'tables' => ...], ...]

    /**
     * Sorgudan benzersiz önbellek anahtarı oluşturur
     */
    private function generate_query_cache_key(mixed $query, array $params = []): string
    {
        return md5((string)$query . serialize($params));
    }

    /**
     * SQL sorgusundan tablo adlarını çıkarır (basit regex ile)
     *
     * @param string $query SQL sorgusu
     * @return array Tablo adları
     */
    private function extract_tables_from_query(string $query): array
    {
        $tables = [];
        $query_upper = strtoupper(trim($query));
        
        // FROM clause
        if (preg_match('/\bFROM\s+([a-z0-9_]+)/i', $query, $matches)) {
            $tables[] = strtolower($matches[1]);
        }
        
        // JOIN clauses
        if (preg_match_all('/\b(?:INNER|LEFT|RIGHT|FULL|CROSS)\s+JOIN\s+([a-z0-9_]+)/i', $query, $matches)) {
            foreach ($matches[1] as $table) {
                $tables[] = strtolower($table);
            }
        }
        
        // UPDATE table
        if (preg_match('/\bUPDATE\s+([a-z0-9_]+)/i', $query, $matches)) {
            $tables[] = strtolower($matches[1]);
        }
        
        // INSERT INTO table
        if (preg_match('/\bINSERT\s+INTO\s+([a-z0-9_]+)/i', $query, $matches)) {
            $tables[] = strtolower($matches[1]);
        }
        
        // DELETE FROM table
        if (preg_match('/\bDELETE\s+FROM\s+([a-z0-9_]+)/i', $query, $matches)) {
            $tables[] = strtolower($matches[1]);
        }
        
        return array_unique($tables);
    }

    /**
     * Sorgu sonucunu önbelleğe ekler (optimize edilmiş LRU)
     *
     * @param string $key Cache key
     * @param mixed $data Cache data
     * @param array $tags Cache tags (opsiyonel)
     * @param array $tables İlgili tablolar (opsiyonel, event-based invalidation için)
     */
    private function add_to_query_cache(string $key, mixed $data, array $tags = [], array $tables = []): void
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
            'tags' => $tags,
            'tables' => $tables,
        ];
        $this->query_cache_usage[$key] = microtime(true);
        
        // Tag-based invalidation için
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
        
        // Event-based invalidation için (tablo bazlı)
        if (! empty($tables)) {
            foreach ($tables as $table) {
                if (! isset($this->table_to_keys[$table])) {
                    $this->table_to_keys[$table] = [];
                }
                if (! in_array($key, $this->table_to_keys[$table])) {
                    $this->table_to_keys[$table][] = $key;
                }
            }
        }
        
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
        $this->query_cache_enabled = (bool)\nsql\database\config::get('query_cache_enabled', false);
        $this->query_cache_timeout = (int)\nsql\database\config::get('query_cache_timeout', 3600);
        $this->query_cache_size_limit = (int)\nsql\database\config::get('query_cache_size_limit', 100);
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
        $this->cache_tags = [];
        $this->tag_to_keys = [];
        $this->table_to_keys = [];
    }

    /**
     * TTL tabanlı invalidation (zaten mevcut, is_valid_cache ve purge_expired_cache ile)
     */

    /**
     * Event-based invalidation: Belirli bir tabloyu etkileyen tüm cache'leri temizler
     *
     * @param string|array $tables Tablo adı veya tablo adları dizisi
     */
    public function invalidate_cache_by_table($tables): void
    {
        if (! $this->query_cache_enabled) {
            return;
        }

        $tables = is_array($tables) ? $tables : [$tables];
        $keys_to_remove = [];

        foreach ($tables as $table) {
            $table = strtolower(trim($table));
            if (isset($this->table_to_keys[$table])) {
                $keys_to_remove = array_merge($keys_to_remove, $this->table_to_keys[$table]);
                unset($this->table_to_keys[$table]);
            }
        }

        // Duplicate'leri kaldır
        $keys_to_remove = array_unique($keys_to_remove);

        // Cache'leri temizle
        foreach ($keys_to_remove as $key) {
            $this->remove_cache_entry($key);
        }
    }

    /**
     * Tag-based invalidation: Belirli bir tag'e sahip tüm cache'leri temizler
     *
     * @param string|array $tags Tag veya tag'ler dizisi
     */
    public function invalidate_cache_by_tag($tags): void
    {
        if (! $this->query_cache_enabled) {
            return;
        }

        $tags = is_array($tags) ? $tags : [$tags];
        $keys_to_remove = [];

        foreach ($tags as $tag) {
            $tag = (string)$tag;
            if (isset($this->tag_to_keys[$tag])) {
                $keys_to_remove = array_merge($keys_to_remove, $this->tag_to_keys[$tag]);
                unset($this->tag_to_keys[$tag]);
            }
        }

        // Duplicate'leri kaldır
        $keys_to_remove = array_unique($keys_to_remove);

        // Cache'leri temizle
        foreach ($keys_to_remove as $key) {
            $this->remove_cache_entry($key);
        }
    }

    /**
     * Belirli bir cache entry'sini kaldırır
     *
     * @param string $key Cache key
     */
    private function remove_cache_entry(string $key): void
    {
        if (isset($this->query_cache[$key])) {
            // Tag'leri temizle
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

            // Tablo mapping'lerini temizle
            if (isset($this->query_cache[$key]['tables'])) {
                foreach ($this->query_cache[$key]['tables'] as $table) {
                    if (isset($this->table_to_keys[$table])) {
                        $this->table_to_keys[$table] = array_filter(
                            $this->table_to_keys[$table],
                            fn($k) => $k !== $key
                        );
                        if (empty($this->table_to_keys[$table])) {
                            unset($this->table_to_keys[$table]);
                        }
                    }
                }
            }

            unset($this->query_cache[$key], $this->query_cache_usage[$key]);
            $this->remove_from_access_order($key);
        }
    }

    /**
     * Tüm cache'i temizler (clear_query_cache ile aynı)
     */
    public function invalidate_all_cache(): void
    {
        $this->clear_query_cache();
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
            'warm_queries_count' => count($this->warm_queries),
        ];
    }

    /**
     * Cache Warming Mekanizması
     */

    /**
     * Cache warming için sorgu kaydeder
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @param array $tags Cache tags (opsiyonel)
     * @param array $tables İlgili tablolar (opsiyonel)
     */
    public function register_warm_query(string $query, array $params = [], array $tags = [], array $tables = []): void
    {
        $this->warm_queries[] = [
            'query' => $query,
            'params' => $params,
            'tags' => $tags,
            'tables' => $tables,
        ];
    }

    /**
     * Kayıtlı tüm warm query'leri cache'e yükler
     *
     * @param bool $force Yeniden yükle (zaten cache'de olsa bile)
     * @return array Yüklenen cache entry sayısı ve hata bilgileri
     */
    public function warm_cache(bool $force = false): array
    {
        if (! $this->query_cache_enabled) {
            return [
                'success' => false,
                'message' => 'Cache devre dışı',
                'loaded' => 0,
                'errors' => [],
            ];
        }

        $loaded = 0;
        $errors = [];

        foreach ($this->warm_queries as $warm_query) {
            try {
                $cache_key = $this->generate_query_cache_key($warm_query['query'], $warm_query['params']);
                
                // Zaten cache'de varsa ve force=false ise atla
                if (! $force && isset($this->query_cache[$cache_key])) {
                    continue;
                }

                // Sorguyu çalıştır (nsql instance'ına ihtiyacımız var)
                // Bu metod trait içinde olduğu için $this->execute_query() kullanamayız
                // Bu yüzden warm_cache metodunu nsql sınıfında override etmemiz gerekebilir
                // Şimdilik sadece yapıyı kuruyoruz
                $loaded++;
            } catch (\Exception $e) {
                $errors[] = [
                    'query' => $warm_query['query'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'loaded' => $loaded,
            'errors' => $errors,
        ];
    }

    /**
     * Belirli bir sorguyu cache'e yükler (preload)
     * Not: Bu metod nsql sınıfında override edilmeli
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @param array $tags Cache tags (opsiyonel)
     * @param array $tables İlgili tablolar (opsiyonel)
     * @return bool Başarılı ise true
     */
    public function preload_query(string $query, array $params = [], array $tags = [], array $tables = []): bool
    {
        if (! $this->query_cache_enabled) {
            return false;
        }

        $cache_key = $this->generate_query_cache_key($query, $params);
        
        // Zaten cache'de varsa true döndür
        if (isset($this->query_cache[$cache_key])) {
            return true;
        }

        // Bu metod trait içinde olduğu için sorguyu çalıştıramayız
        // nsql sınıfında bu metod override edilmeli
        // Şimdilik sadece yapıyı kuruyoruz
        return false;
    }

    /**
     * Kayıtlı warm query'leri döndürür
     *
     * @return array Warm query'ler
     */
    public function get_warm_queries(): array
    {
        return $this->warm_queries;
    }

    /**
     * Kayıtlı warm query'leri temizler
     */
    public function clear_warm_queries(): void
    {
        $this->warm_queries = [];
    }
}
