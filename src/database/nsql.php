<?php

namespace nsql\database;

use InvalidArgumentException;
use nsql\database\drivers\driver_factory;
use nsql\database\drivers\driver_interface;
use nsql\database\traits\{
    cache_trait,
    connection_trait,
    debug_trait,
    error_handling_trait,
    error_model_trait,
    log_path_trait,
    query_analyzer_trait,
    query_execution_trait,
    query_parameter_trait,
    session_facade_trait,
    statement_cache_trait,
    streaming_trait,
    transaction_trait,
    write_operations_trait
};
use PDO;
use PDOStatement;

/**
 * PDO tabanlı veritabanı sarmalayıcı (public facade).
 *
 * Composition kullanır: fiziksel bağlantı yalnızca connection_pool üzerinden gelir.
 * `nsql` artık PDO'yu extend etmez (v1.5.5+); ham PDO için get_pdo() kullanın.
 *
 * Sorumluluklar trait'lere ayrılmıştır:
 * - connection_trait / transaction_trait: bağlantı, reconnect, transaction
 * - query_execution_trait: prepare/bind/execute, reconnect retry
 * - write_operations_trait: insert/update/delete/statement/batch_*
 * - streaming_trait: get_yield, chunk_by_id, get_chunk (bellek: optimization\memory_monitor)
 * - cache_trait / statement_cache_trait: sorgu ve statement cache
 * - error_model_trait: loglama, safe_execute, THROW_ON_ERROR
 * - session_facade_trait: statik session/CSRF kısayolları
 */
class nsql
{
    use query_parameter_trait;
    use cache_trait;
    use debug_trait;
    use statement_cache_trait;
    use connection_trait;
    use transaction_trait;
    use query_analyzer_trait;
    use error_handling_trait;
    use error_model_trait;
    use log_path_trait;
    use query_execution_trait;
    use write_operations_trait;
    use streaming_trait;
    use session_facade_trait;

    // Debug özellikleri
    protected ?string $last_error = null;
    protected string $last_query = '';
    protected array $last_params = [];
    protected string $last_called_method = 'unknown';
    protected bool $debug_mode = false;
    protected string $log_file = 'error_log.txt';

    // Bağlantı ayarları ($pdo, $pool_key, $retry_limit: connection_trait)
    private array $options = [];
    private string $dsn = '';
    private ?string $user = null;
    private ?string $pass = null;
    private ?driver_interface $driver = null;

    // Sorgu sonuçları
    private array $last_results = [];

    /**
     * Query Builder oluşturur
     *
     * @param string|null $table Tablo adı (opsiyonel)
     * @return query_builder
     */
    public function table(?string $table = null): query_builder
    {
        $builder = new query_builder($this);

        return $table ? $builder->table($table) : $builder;
    }

    public function __construct(
        ?string $host = null,
        ?string $db = null,
        ?string $user = null,
        ?string $pass = null,
        ?string $charset = null,
        ?bool $debug = null,
        ?string $driver = null
    ) {
        // Driver belirle (varsayılan: mysql)
        $driver_name = $driver ?? config::get('db_driver', 'mysql');
        $this->driver = driver_factory::create($driver_name);

        // Config sınıfından değerleri al
        $host = $host ?? config::get('db_host', 'localhost');
        $db = $db ?? config::get('db_name', 'nsql');
        $user = $user ?? config::get('db_user', 'root');
        $pass = $pass ?? config::get('db_pass', '');
        $charset = $charset ?? config::get('db_charset', $this->get_default_charset($driver_name));

        // Driver'a göre DSN oluştur
        $config = [
            'host' => $host,
            'dbname' => $db,
            'charset' => $charset,
        ];
        
        // SQLite için path kullan
        if ($driver_name === 'sqlite') {
            $config['path'] = $db;
            unset($config['host'], $config['charset']);
        } else {
            $config['port'] = config::get('db_port', $this->get_default_port($driver_name));
        }
        
        $this->dsn = $this->driver->build_dsn($config);
        $this->user = (string)$user;
        $this->pass = (string)$pass;

        // PDO bağlantı seçeneklerini ayarla (driver'a özel + genel)
        $driver_options = $this->driver->get_pdo_options();
        $this->options = array_merge($driver_options, [
            \PDO::ATTR_PERSISTENT => (int)(bool)config::get('persistent_connection', config::persistent_connection),
        ]);
        
        // MySQL için timeout DSN'e eklenir (PDO attribute olarak desteklenmez)
        if ($driver_name === 'mysql' && config::has('connection_timeout')) {
            $timeout = (int)config::get('connection_timeout', config::connection_timeout);
            if (strpos($this->dsn, 'timeout=') === false) {
                $this->dsn .= ";timeout={$timeout}";
            }
        }

        $this->debug_mode = (bool)($debug ?? config::get('debug_mode', false));
        $this->log_file = (string)config::get('log_file', 'error_log.txt');
        $this->statement_cache_limit = (int)config::get('statement_cache_limit', 100);

        // Tek bağlantı: yalnızca pool (parent PDO açılmaz — çift bağlantı önlenir)
        $this->initialize_pool();
        $this->initialize_connection();
        $this->load_cache_config();
    }

    /**
     * Pool'dan alınan ham PDO bağlantısını döndürür.
     */
    public function get_pdo(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * Driver'a göre varsayılan charset döndürür
     */
    private function get_default_charset(string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => 'utf8mb4',
            'pgsql', 'postgresql' => 'UTF8',
            'sqlite' => 'UTF-8',
            default => 'utf8mb4',
        };
    }

    /**
     * Driver'a göre varsayılan port döndürür
     */
    private function get_default_port(string $driver): int
    {
        return match ($driver) {
            'mysql', 'mariadb' => 3306,
            'pgsql', 'postgresql' => 5432,
            default => 3306,
        };
    }

    public static function connect(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null): static
    {
        // DSN'den driver oluştur
        $driver = driver_factory::create_from_dsn($dsn);
        $parsed = $driver->parse_dsn($dsn);
        
        // Driver'a göre instance oluştur
        $instance = new static(
            host: $parsed['host'] ?? null,
            db: $parsed['dbname'] ?? $parsed['path'] ?? null,
            user: $username,
            pass: $password,
            charset: $parsed['charset'] ?? null,
            driver: $parsed['driver']
        );
        
        // Özel options varsa uygula
        if ($options !== null) {
            foreach ($options as $key => $value) {
                $instance->pdo?->setAttribute($key, $value);
            }
        }
        
        return $instance;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Süreçteki tüm bağlantı havuzlarının toplam istatistikleri.
     */
    public static function get_pool_stats(): array
    {
        return connection_pool::get_stats();
    }

    /**
     * Yalnızca bu örneğin DSN/kullanıcı havuzuna ait istatistikler.
     */
    public function get_instance_pool_stats(): array
    {
        return connection_pool::get_stats($this->pool_key);
    }

    public function query(string $query, ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $this->set_last_called_method();
        
        // GELISTIRME-009: Error handling - exception fırlatma
        $result = $this->execute_query($query, [], $fetch_mode, ...$fetch_mode_args);
        
        // query() her iki modda da fırlatır (geriye uyumluluk)
        if ($result === false) {
            throw $this->make_query_exception($query, []);
        }

        if ($result !== false && ! preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $query)) {
            $this->invalidate_cache_for_write($query);
        }
        
        return $result;
    }

    /**
     * Aktif sürücü adı: `mysql`, `pgsql` veya `sqlite`.
     */
    public function get_driver_name(): string
    {
        return $this->driver?->get_driver_name() ?? 'mysql';
    }

    /**
     * Tablo/kolon adını doğrular ve driver'a göre quote eder (`tablo` veya `şema.tablo`).
     *
     * @throws InvalidArgumentException Ad yalnızca harf, rakam ve alt çizgiden oluşmuyorsa
     */
    public function quote_identifier(string $identifier): string
    {
        $parts = explode('.', $identifier);

        if (count($parts) > 2) {
            throw new InvalidArgumentException('Geçersiz tanımlayıcı: ' . substr($identifier, 0, 64));
        }

        foreach ($parts as $part) {
            if (! preg_match('/^(?!\d+$)[A-Za-z0-9_]+$/', $part)) {
                throw new InvalidArgumentException('Geçersiz tanımlayıcı: ' . substr($identifier, 0, 64));
            }
        }

        $quote = $this->driver?->get_identifier_quote() ?? '`';

        return implode('.', array_map(fn (string $part) => $quote . $part . $quote, $parts));
    }

    /**
     * get_row() için sorguya güvenli olduğunda `LIMIT 1` ekler.
     *
     * Yalnızca SELECT/WITH sorgularında, sorgu herhangi bir LIMIT (`LIMIT 5`, `LIMIT ?`, `LIMIT :p`,
     * subquery içi dahil) veya kilit ifadesi (`FOR UPDATE`, `FOR SHARE`, `LOCK IN SHARE MODE`)
     * içermiyorsa eklenir. Aksi halde sorgu değiştirilmez ve yalnızca ilk satır okunur.
     */
    private function with_single_row_limit(string $query): string
    {
        $query = rtrim($query, " \t\n\r\0\x0B;");

        if (! preg_match('/^\s*(SELECT|WITH)\b/i', $query)) {
            return $query;
        }

        if (preg_match('/\bLIMIT\b|\bFOR\s+(UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $query)) {
            return $query;
        }

        return $query . ' LIMIT 1';
    }

    public function get_row(string $query, array $params = []): ?object
    {
        $this->set_last_called_method();
        $query = $this->with_single_row_limit($query);

        // Cache kontrolü
        $cache_key = $this->generate_query_cache_key($query, $params);
        if ($this->query_cache_enabled) {
            $cached = $this->get_from_query_cache($cache_key);
            if ($cached !== null) {
                return is_array($cached) && ! empty($cached) ? (object)$cached[0] : $cached;
            }
        }

        // Sorguyu çalıştır
        $stmt = $this->execute_query($query, $params);
        if ($stmt === false) {
            return null;
        }
        
        // Sonucu al, last_results ve cache'i guncelle
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        $stmt->closeCursor();
        $this->last_results = $result ? [$result] : [];
        if ($result && $this->query_cache_enabled) {
            $tables = $this->extract_tables_from_query($query);
            $this->add_to_query_cache($cache_key, $result, [], $tables);
        }

        return $result ?: null;
    }

    public function get_results(string $query, array $params = []): array
    {
        $this->set_last_called_method();

        // Memory kontrolü
        $this->check_memory_status();

        // Cache kontrolü
        $cache_key = $this->generate_query_cache_key($query, $params);
        if ($this->query_cache_enabled) {
            $cached = $this->get_from_query_cache($cache_key);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Sorguyu çalıştır
        $stmt = $this->execute_query($query, $params);
        if ($stmt === false) {
            return [];
        }

        $results = $stmt->fetchAll(PDO::FETCH_OBJ);

        // rowCount() SELECT için sürücüler arası güvenilir değil; gerçek satır sayısı kullanılır
        $result_count = count($results);
        if ($result_count > (int) config::get('large_result_warning', config::large_result_warning)) {
            trigger_error(
                "Büyük veri seti ($result_count satır). chunk_by_id() veya get_yield() kullanmayı düşünün.",
                E_USER_NOTICE
            );
        }

        $this->last_results = $results;
        if ($this->query_cache_enabled && count($results) <= $this->query_cache_size_limit) {
            $this->add_to_query_cache($cache_key, $results, [], $this->extract_tables_from_query($query));
        }

        return $results;
    }

    /**
     * Tüm cache istatistiklerini döndürür
     */
    public function get_all_cache_stats(): array
    {
        return [
            'query_cache' => $this->get_cache_stats(),
            'statement_cache' => $this->get_statement_cache_stats(),
        ];
    }

    /**
     * Tüm istatistikleri döndürür
     */
    public function get_all_stats(): array
    {
        return [
            'memory' => $this->get_memory_stats(),
            'cache' => $this->get_all_cache_stats(),
            'query_analyzer' => $this->get_query_analyzer_stats(),
            'connection_pool' => $this->get_pool_stats(),
        ];
    }

    /**
     * Belirli bir sorguyu cache'e yükler (preload)
     * nsql sınıfında override edilmiş versiyon
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @param array $tags Cache tags (opsiyonel)
     * @param array $tables İlgili tablolar (opsiyonel, otomatik çıkarılır)
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

        try {
            // Sorguyu çalıştır
            $stmt = $this->execute_query($query, $params);
            if ($stmt === false) {
                return false;
            }

            // Sonuçları al
            $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
            
            // Tabloları otomatik çıkar (eğer belirtilmemişse)
            if (empty($tables)) {
                $tables = $this->extract_tables_from_query($query);
            }

            return $this->add_to_query_cache($cache_key, $results, $tags, $tables);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Kayıtlı tüm warm query'leri cache'e yükler (nsql sınıfında override edilmiş versiyon)
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
                $success = $this->preload_query(
                    $warm_query['query'],
                    $warm_query['params'] ?? [],
                    $warm_query['tags'] ?? [],
                    $warm_query['tables'] ?? []
                );
                
                if ($success) {
                    $loaded++;
                }
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
}
