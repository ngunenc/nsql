<?php

namespace nsql\database\traits;

/**
 * Process içi query cache.
 *
 * $query_cache dizisinin ekleme sırası LRU sırasıdır: erişilen kayıt unset edilip
 * sona yeniden eklenir, en eski kayıt array_key_first() ile bulunur (O(1)).
 * Tablo/tag eşlemeleri key => true kümeleridir; tüm silmeler remove_cache_entry()
 * üzerinden yapılır, böylece eviction ve expiry sonrası eşlemeler büyümez.
 */
trait cache_trait
{
    /** @var array<string, array{data: mixed, time: int, tags: list<string>, tables: list<string>}> */
    private array $query_cache = [];
    private bool $query_cache_enabled = false;
    private int $query_cache_timeout = 3600;
    private int $query_cache_size_limit = 100;
    private int $query_cache_hits = 0;
    private int $query_cache_misses = 0;

    /** @var array<string, array<string, true>> tag => [key => true] */
    private array $tag_to_keys = [];
    /** @var array<string, array<string, true>> table => [key => true] */
    private array $table_to_keys = [];

    /** @var list<array{query: string, params: array, tags: array, tables: array}> */
    private array $warm_queries = [];

    /** @var array<string, int> table_name => ttl_seconds */
    private array $table_ttl_overrides = [];
    private array $cache_warming_strategies = []; // table_name => strategy_config

    /**
     * Sorgudan benzersiz önbellek anahtarı oluşturur
     */
    private function generate_query_cache_key(mixed $query, array $params = []): string
    {
        return md5((string)$query . serialize($params));
    }

    /**
     * SQL sorgusundan tablo adlarını çıkarır.
     *
     * Quote'lu (`tablo`, "tablo"), şema önekli (db.tablo), JOIN, virgüllü FROM listesi
     * ve subquery içindeki tabloları yakalar. Boş dizi = tablo tespit edilemedi.
     *
     * @param string $query SQL sorgusu
     * @return list<string> Küçük harfli tablo adları
     */
    private function extract_tables_from_query(string $query): array
    {
        $ident = '[`"]?(?:\w+[`"]?\.[`"]?)?(\w+)[`"]?';
        $patterns = [
            '/\bJOIN\s+' . $ident . '/i',
            '/\bUPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*' . $ident . '/i',
            '/\bINTO\s+' . $ident . '/i',
            '/\bTABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?' . $ident . '/i',
        ];

        $tables = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $query, $matches)) {
                foreach ($matches[1] as $table) {
                    $tables[] = strtolower($table);
                }
            }
        }

        $from_end = '(?=\b(?:WHERE|GROUP|ORDER|LIMIT|HAVING|UNION|JOIN|INNER|LEFT|RIGHT|CROSS|FULL|NATURAL|STRAIGHT_JOIN|FOR|LOCK|WINDOW)\b|\)|;|$)';
        if (preg_match_all('/\bFROM\s+(.+?)' . $from_end . '/is', $query, $matches)) {
            foreach ($matches[1] as $segment) {
                foreach (explode(',', $segment) as $part) {
                    if (preg_match('/^\s*' . $ident . '/', $part, $part_match)) {
                        $tables[] = strtolower($part_match[1]);
                    }
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Query cache okunabilir/yazılabilir mi? Transaction içinde cache bypass edilir;
     * commit edilmemiş veri cache'e girmez, rollback sonrası eski kayıt dönmez.
     */
    private function query_cache_usable(): bool
    {
        if (! $this->query_cache_enabled) {
            return false;
        }

        return ! (method_exists($this, 'get_transaction_level') && $this->get_transaction_level() > 0);
    }

    /**
     * Yazma sorgusundan etkilenen tabloların cache'ini temizler.
     * Tablo tespit edilemezse tüm cache temizlenir.
     */
    private function invalidate_cache_for_write(string $sql): void
    {
        if (! $this->query_cache_enabled) {
            return;
        }

        $tables = $this->extract_tables_from_query($sql);
        if ($tables === []) {
            $this->invalidate_all_cache();

            return;
        }

        $this->invalidate_cache_by_table($tables);
    }

    /**
     * Sorgu sonucunu önbelleğe ekler
     *
     * @param string $key Cache key
     * @param mixed $data Cache data
     * @param array $tags Cache tags (opsiyonel)
     * @param array $tables İlgili tablolar (event-based invalidation için; boşsa cache'lenmez)
     * @return bool Cache'e yazıldıysa true
     */
    private function add_to_query_cache(string $key, mixed $data, array $tags = [], array $tables = []): bool
    {
        // Tablosu bilinmeyen sonuç yazma sonrası geçersiz kılınamaz → cache'leme
        if (! $this->query_cache_usable() || $tables === []) {
            return false;
        }

        $cleanup_probability = \nsql\database\config::get('cache_cleanup_probability', 10);
        if (rand(1, 100) <= $cleanup_probability) {
            $this->purge_expired_cache();
        }

        $this->remove_cache_entry($key);

        $limit = max(1, $this->query_cache_size_limit);
        while (count($this->query_cache) >= $limit) {
            $this->evict_least_recently_used();
        }

        $tags = array_values(array_unique(array_map('strval', $tags)));
        $tables = array_values(array_unique(array_map(fn ($t) => strtolower(trim((string)$t)), $tables)));

        $this->query_cache[$key] = [
            'data' => $data,
            'time' => time(),
            'tags' => $tags,
            'tables' => $tables,
        ];

        foreach ($tags as $tag) {
            $this->tag_to_keys[$tag][$key] = true;
        }
        foreach ($tables as $table) {
            $this->table_to_keys[$table][$key] = true;
        }

        return true;
    }

    /**
     * Önbellekten sorgu sonucunu getirir
     */
    private function get_from_query_cache(string $key): mixed
    {
        if (! $this->query_cache_usable()) {
            return null;
        }

        if (! isset($this->query_cache[$key])) {
            $this->query_cache_misses++;

            return null;
        }

        $cached = $this->query_cache[$key];

        if (! $this->is_valid_cache($cached['time'], $cached['tables'])) {
            $this->remove_cache_entry($key);
            $this->query_cache_misses++;

            return null;
        }

        // LRU: sona taşı
        unset($this->query_cache[$key]);
        $this->query_cache[$key] = $cached;
        $this->query_cache_hits++;

        return $cached['data'];
    }

    /**
     * Önbellek süre kontrolü (per-table TTL desteği ile)
     */
    private function is_valid_cache(int $cache_time, array $tables = []): bool
    {
        $ttl = $this->query_cache_timeout;
        foreach ($tables as $table) {
            $table = strtolower(trim($table));
            if (isset($this->table_ttl_overrides[$table])) {
                $ttl = $this->table_ttl_overrides[$table];

                break;
            }
        }

        return (time() - $cache_time) <= $ttl;
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
        foreach ($this->query_cache as $key => $entry) {
            if (! $this->is_valid_cache($entry['time'], $entry['tables'])) {
                $this->remove_cache_entry($key);
            }
        }
    }

    /**
     * Önbelleği temizler
     */
    private function clear_query_cache(): void
    {
        $this->query_cache = [];
        $this->query_cache_hits = 0;
        $this->query_cache_misses = 0;
        $this->tag_to_keys = [];
        $this->table_to_keys = [];
    }

    /**
     * Event-based invalidation: Belirli bir tabloyu etkileyen tüm cache'leri temizler
     *
     * @param string|array $tables Tablo adı veya tablo adları dizisi
     */
    public function invalidate_cache_by_table(string|array $tables): void
    {
        if (! $this->query_cache_enabled) {
            return;
        }

        foreach ((array)$tables as $table) {
            $table = strtolower(trim((string)$table));
            foreach (array_keys($this->table_to_keys[$table] ?? []) as $key) {
                $this->remove_cache_entry((string)$key);
            }
            unset($this->table_to_keys[$table]);
        }
    }

    /**
     * Tag-based invalidation: Belirli bir tag'e sahip tüm cache'leri temizler
     *
     * @param string|array $tags Tag veya tag'ler dizisi
     */
    public function invalidate_cache_by_tag(string|array $tags): void
    {
        if (! $this->query_cache_enabled) {
            return;
        }

        foreach ((array)$tags as $tag) {
            $tag = (string)$tag;
            foreach (array_keys($this->tag_to_keys[$tag] ?? []) as $key) {
                $this->remove_cache_entry((string)$key);
            }
            unset($this->tag_to_keys[$tag]);
        }
    }

    /**
     * Cache entry'sini ve tüm tablo/tag eşlemelerini kaldırır.
     */
    private function remove_cache_entry(string $key): void
    {
        if (! isset($this->query_cache[$key])) {
            return;
        }

        $entry = $this->query_cache[$key];
        foreach ($entry['tags'] as $tag) {
            unset($this->tag_to_keys[$tag][$key]);
            if (empty($this->tag_to_keys[$tag])) {
                unset($this->tag_to_keys[$tag]);
            }
        }
        foreach ($entry['tables'] as $table) {
            unset($this->table_to_keys[$table][$key]);
            if (empty($this->table_to_keys[$table])) {
                unset($this->table_to_keys[$table]);
            }
        }

        unset($this->query_cache[$key]);
    }

    /**
     * Tüm cache'i temizler (clear_query_cache ile aynı)
     */
    public function invalidate_all_cache(): void
    {
        $this->clear_query_cache();
    }

    /**
     * En az kullanılan cache girişini çıkarır (O(1))
     */
    private function evict_least_recently_used(): void
    {
        $oldest_key = array_key_first($this->query_cache);
        if ($oldest_key !== null) {
            $this->remove_cache_entry((string)$oldest_key);
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
            'tracked_tables' => count($this->table_to_keys),
            'tracked_tags' => count($this->tag_to_keys),
        ];
    }

    /**
     * Cache warming için sorgu kaydeder. Yükleme nsql::warm_cache() / preload_query() ile yapılır.
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

    /**
     * Belirli bir tablo için TTL ayarlar (per-table TTL)
     *
     * @param string $table Tablo adı
     * @param int $ttl_seconds TTL süresi (saniye, negatif değer 0'a çekilir)
     */
    public function set_table_ttl(string $table, int $ttl_seconds): void
    {
        $table = strtolower(trim($table));
        $this->table_ttl_overrides[$table] = max(0, $ttl_seconds);
    }

    /**
     * Tablo TTL ayarını kaldırır (default TTL kullanılır)
     *
     * @param string $table Tablo adı
     */
    public function remove_table_ttl(string $table): void
    {
        $table = strtolower(trim($table));
        unset($this->table_ttl_overrides[$table]);
    }

    /**
     * Tüm tablo TTL ayarlarını döndürür
     *
     * @return array Tablo adı => TTL (saniye)
     */
    public function get_table_ttls(): array
    {
        return $this->table_ttl_overrides;
    }

    /**
     * Cache warming stratejisi ayarlar
     *
     * @param string $table Tablo adı
     * @param array{
     *     enabled?: bool,
     *     queries?: array<int, array{query: string, params?: array, tags?: array, tables?: array}>,
     *     priority?: int
     * } $strategy Strateji konfigürasyonu
     */
    public function set_cache_warming_strategy(string $table, array $strategy): void
    {
        $table = strtolower(trim($table));
        $this->cache_warming_strategies[$table] = [
            'enabled' => $strategy['enabled'] ?? true,
            'queries' => $strategy['queries'] ?? [],
            'priority' => $strategy['priority'] ?? 0,
        ];
    }

    /**
     * Cache warming stratejisini çalıştırır (belirli bir tablo için)
     *
     * @param string $table Tablo adı
     * @return array{
     *     success: bool,
     *     message?: string,
     *     loaded: int,
     *     errors: array<int, array{table: string, query: string, error: string}>
     * }
     */
    public function warm_cache_for_table(string $table): array
    {
        $table = strtolower(trim($table));

        if (! isset($this->cache_warming_strategies[$table]) ||
            ! $this->cache_warming_strategies[$table]['enabled']) {
            return [
                'success' => false,
                'message' => "Tablo için warming stratejisi bulunamadı veya devre dışı: {$table}",
                'loaded' => 0,
                'errors' => [],
            ];
        }

        $strategy = $this->cache_warming_strategies[$table];
        $errors = [];
        $loaded = 0;

        foreach ($strategy['queries'] as $query_config) {
            $query = $query_config['query'] ?? '';
            $params = $query_config['params'] ?? [];
            $tags = $query_config['tags'] ?? [];
            $tables = $query_config['tables'] ?? [$table];

            try {
                if ($this->preload_query($query, $params, $tags, $tables)) {
                    $loaded++;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'table' => $table,
                    'query' => $query !== '' ? $query : 'unknown',
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
     * Tüm tablolar için cache warming stratejilerini öncelik sırasına göre çalıştırır
     *
     * @return array{
     *     success: bool,
     *     loaded: int,
     *     errors: array<int, array{table: string, query: string, error: string}>
     * }
     */
    public function warm_cache_all_tables(): array
    {
        $strategies = $this->cache_warming_strategies;
        uasort($strategies, fn ($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        $total_loaded = 0;
        $all_errors = [];

        foreach ($strategies as $table => $strategy) {
            if (! $strategy['enabled']) {
                continue;
            }

            $result = $this->warm_cache_for_table($table);
            $total_loaded += $result['loaded'];
            $all_errors = array_merge($all_errors, $result['errors']);
        }

        return [
            'success' => true,
            'loaded' => $total_loaded,
            'errors' => $all_errors,
        ];
    }
}
