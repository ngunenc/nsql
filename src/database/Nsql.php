<?php

namespace nsql\database;

use InvalidArgumentException;
use nsql\database\drivers\DriverFactory;
use nsql\database\drivers\DriverInterface;
use nsql\database\traits\{
    CacheTrait,
    ConnectionTrait,
    DebugTrait,
    ErrorHandlingTrait,
    ErrorModelTrait,
    HotSettingsTrait,
    LogPathTrait,
    QueryAnalyzerTrait,
    QueryEventsTrait,
    QueryExecutionTrait,
    QueryParameterTrait,
    ReadWriteSplitTrait,
    SessionFacadeTrait,
    StatementCacheTrait,
    StreamingTrait,
    TransactionTrait,
    WriteOperationsTrait
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
 * - query_events_trait: on_query() dinleyicileri, yavaş sorgu logu
 * - write_operations_trait: insert/update/delete/statement/batch_*
 * - streaming_trait: get_yield, chunk_by_id, get_chunk (bellek: optimization\memory_monitor)
 * - cache_trait / statement_cache_trait: sorgu ve statement cache
 * - error_model_trait: loglama, safe_execute, THROW_ON_ERROR
 * - session_facade_trait: statik session/CSRF kısayolları
 *
 * Alt sınıflar connect() içindeki `new static(...)` için constructor imzasını korumalıdır.
 *
 * @phpstan-consistent-constructor
 */
class Nsql
{
    use QueryParameterTrait;
    use CacheTrait;
    use DebugTrait;
    use StatementCacheTrait;
    use ConnectionTrait;
    use TransactionTrait;
    use QueryAnalyzerTrait;
    use ErrorHandlingTrait;
    use ErrorModelTrait;
    use LogPathTrait;
    use QueryExecutionTrait;
    use QueryEventsTrait;
    use WriteOperationsTrait;
    use StreamingTrait;
    use SessionFacadeTrait;
    use ReadWriteSplitTrait;
    use HotSettingsTrait;

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
    private ?DriverInterface $driver = null;
    /** @var array{host: string, db: string, user: string, pass: string, charset: string, driver: string, port: int} */
    private array $connection_args;

    // Sorgu sonuçları
    private array $last_results = [];

    /**
     * Query Builder oluşturur
     *
     * @param string|null $table Tablo adı (opsiyonel)
     * @return QueryBuilder
     */
    public function table(?string $table = null): QueryBuilder
    {
        $builder = new QueryBuilder($this);

        return $table ? $builder->table($table) : $builder;
    }

    public function __construct(
        ?string $host = null,
        ?string $db = null,
        ?string $user = null,
        ?string $pass = null,
        ?string $charset = null,
        ?bool $debug = null,
        ?string $driver = null,
        ?int $port = null,
        ?array $options = null
    ) {
        // Driver belirle (varsayılan: mysql)
        $driver_name = $driver ?? Config::get('db_driver', 'mysql');
        $this->driver = DriverFactory::create($driver_name);

        // Config sınıfından değerleri al
        $host = $host ?? Config::get('db_host', 'localhost');
        $db = $db ?? Config::get('db_name', 'nsql');
        $user = $user ?? Config::get('db_user', 'root');
        $pass = $pass ?? Config::get('db_pass', '');
        $charset = $charset ?? Config::get('db_charset', $this->get_default_charset($driver_name));
        $port = $port ?? (int) Config::get('db_port', $this->get_default_port($driver_name));
        $this->connection_args = [
            'host' => (string) $host,
            'db' => (string) $db,
            'user' => (string) $user,
            'pass' => (string) $pass,
            'charset' => (string) $charset,
            'driver' => (string) $driver_name,
            'port' => $port,
        ];

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
            $config['port'] = $port;
        }

        $this->dsn = $this->driver->build_dsn($config);
        $this->user = (string)$user;
        $this->pass = (string)$pass;

        // PDO bağlantı seçenekleri: driver + genel + çağıranın verdikleri. Anahtarlar PDO::ATTR_*
        // tamsayıları olduğundan array_merge kullanılmaz (anahtarları yeniden numaralar) (#98).
        $this->options = [
            \PDO::ATTR_PERSISTENT => (int)(bool)Config::get('persistent_connection', Config::persistent_connection),
        ] + $this->driver->get_pdo_options();
        foreach ($options ?? [] as $attribute => $value) {
            $this->options[$attribute] = $value;
        }

        // MySQL için timeout DSN'e eklenir (PDO attribute olarak desteklenmez)
        if ($driver_name === 'mysql' && Config::has('connection_timeout')) {
            $timeout = (int)Config::get('connection_timeout', Config::connection_timeout);
            if (strpos($this->dsn, 'timeout=') === false) {
                $this->dsn .= ";timeout={$timeout}";
            }
        }

        $this->debug_mode = (bool)($debug ?? Config::get('debug_mode', false));
        $this->log_file = (string)Config::get('log_file', 'error_log.txt');
        $this->statement_cache_limit = (int)Config::get('statement_cache_limit', 100);

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

    /**
     * İsimlendirilmiş bağlantıyı döndürür (aynı süreçte tekil, ilk kullanımda açılır).
     *
     * @see ConnectionManager
     */
    public static function connection(string $name = ConnectionManager::DEFAULT): self
    {
        return ConnectionManager::get($name);
    }

    public static function connect(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null): static
    {
        // DSN'den driver oluştur
        $driver = DriverFactory::create_from_dsn($dsn);
        $parsed = $driver->parse_dsn($dsn);

        // Driver'a göre instance oluştur
        $instance = new static(
            host: $parsed['host'] ?? null,
            // SQLite'ta dbname yalnızca dosya adıdır (basename); tam yol kullanılmalı (#98)
            db: $parsed['driver'] === 'sqlite' ? ($parsed['path'] ?? null) : ($parsed['dbname'] ?? null),
            user: $username,
            pass: $password,
            charset: $parsed['charset'] ?? null,
            driver: $parsed['driver'],
            port: isset($parsed['port']) ? (int) $parsed['port'] : null,
            // Bağlantı kurulmadan uygulanır: ATTR_PERSISTENT, ATTR_TIMEOUT, SSL vb. ancak böyle etkilidir (#98)
            options: $options
        );

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
        return ConnectionPool::get_stats();
    }

    /**
     * Yalnızca bu örneğin DSN/kullanıcı havuzuna ait istatistikler.
     */
    public function get_instance_pool_stats(): array
    {
        return ConnectionPool::get_stats($this->pool_key);
    }

    public function query(string $query, ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $this->set_last_called_method();

        // GELISTIRME-009: Error handling - exception fırlatma
        // Statement çağırana verilir: cache'teki statement'ı paylaşmaz (#96)
        $result = $this->execute_query_uncached($query, [], $fetch_mode, ...$fetch_mode_args);

        // query() her iki modda da fırlatır (geriye uyumluluk)
        if ($result === false) {
            throw $this->make_query_exception($query, []);
        }

        if (! preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $query)) {
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
     * Tek sorguda bağlanabilecek en fazla parametre sayısı (SQLite 32766, MySQL/PostgreSQL 65535).
     *
     * @internal Toplu ekleme parça boyutu için
     */
    public function max_bound_params(): int
    {
        return $this->get_driver_name() === 'sqlite' ? 32766 : 65535;
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

        // Cache anahtarı yalnızca cache kullanılabilirken hesaplanır
        $cache_key = $this->query_cache_usable() ? $this->generate_query_cache_key($query, $params) : null;
        if ($cache_key !== null) {
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
        if ($result && $cache_key !== null) {
            $this->add_to_query_cache($cache_key, $result, [], $this->extract_tables_from_query($query));
        }

        return $result ?: null;
    }

    public function get_results(string $query, array $params = []): array
    {
        $this->set_last_called_method();

        // Memory kontrolü
        $this->check_memory_status();

        $cache_key = $this->query_cache_usable() ? $this->generate_query_cache_key($query, $params) : null;
        if ($cache_key !== null) {
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
        $stmt->closeCursor();

        // rowCount() SELECT için sürücüler arası güvenilir değil; gerçek satır sayısı kullanılır
        $result_count = count($results);
        if ($result_count > (int) $this->setting('large_result_warning', Config::large_result_warning)) {
            trigger_error(
                "Büyük veri seti ($result_count satır). chunk_by_id() veya get_yield() kullanmayı düşünün.",
                E_USER_NOTICE
            );
        }

        $this->last_results = $results;
        if ($cache_key !== null && count($results) <= $this->query_cache_size_limit) {
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
     * @param bool $force Cache'de olsa bile sorguyu yeniden çalıştırıp kaydı yeniler (#99)
     * @return bool Başarılı ise true
     */
    public function preload_query(string $query, array $params = [], array $tags = [], array $tables = [], bool $force = false): bool
    {
        if (! $this->query_cache_enabled) {
            return false;
        }

        $cache_key = $this->generate_query_cache_key($query, $params);

        // Zaten cache'de varsa true döndür
        if (! $force && isset($this->query_cache[$cache_key])) {
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
            $stmt->closeCursor();

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
                    $warm_query['tables'] ?? [],
                    $force
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
