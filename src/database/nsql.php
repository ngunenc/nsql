<?php

namespace nsql\database;

use Exception;
use Generator;
use InvalidArgumentException;
use nsql\database\security\session_manager;
use nsql\database\drivers\driver_factory;
use nsql\database\drivers\driver_interface;
use nsql\database\traits\{
    cache_trait,
    connection_trait,
    debug_trait,
    error_handling_trait,
    log_path_trait,
    query_analyzer_trait,
    query_parameter_trait,
    statement_cache_trait,
    transaction_trait
};
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO tabanlı veritabanı sarmalayıcı.
 *
 * Composition kullanır: fiziksel bağlantı yalnızca connection_pool üzerinden gelir.
 * `nsql` artık PDO'yu extend etmez (v1.5.5+); ham PDO için get_pdo() kullanın.
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
    use log_path_trait;

    // Debug özellikleri
    protected ?string $last_error = null;
    protected string $last_query = '';
    protected array $last_params = [];
    protected string $last_called_method = 'unknown';
    protected bool $debug_mode = false;
    protected string $log_file = 'error_log.txt';
    private ?\nsql\database\logging\logger $logger = null;

    // Query analiz özellikleri trait içinde tanımlanmıştır

    // Database bağlantı özellikleri ($pdo, $pool_key, $retry_limit: connection_trait)
    private int $last_insert_id = 0;
    private array $options = [];
    private string $dsn = '';
    private ?string $user = null;
    private ?string $pass = null;
    private ?driver_interface $driver = null;

    // Cache özellikleri
    private bool $query_cache_enabled = false;
    private int $query_cache_timeout = 3600;
    private int $query_cache_size_limit = 100;

    // Statement cache özellikleri
    private array $statement_cache = [];
    private array $statement_cache_usage = [];
    private int $statement_cache_limit = 100;

    // Sorgu sonuçları
    private array $last_results = [];

    private bool $streaming = false;

    private static ?int $last_memory_check = null;
    private static int $current_chunk_size = 1000; // Varsayılan değer
    private static array $memory_stats = [
        'peak_usage' => 0,
        'warning_count' => 0,
        'critical_count' => 0,
    ];

    /**
     * Static değişkenleri başlat
     */
    private static function initialize_static_vars(): void
    {
        if (! isset(self::$current_chunk_size)) {
            self::$current_chunk_size = config::default_chunk_size;
        }
        if (! isset(self::$last_memory_check)) {
            self::$last_memory_check = null;
        }
    }

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
        self::initialize_static_vars();
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

    private function log_error(string $message, array $context = [], int $level = \nsql\database\logging\logger::ERROR): void
    {
        // Yeni structured logger kullan
        if ($this->logger === null) {
            $this->logger = new \nsql\database\logging\logger(
                $this->log_file,
                null, // Environment-based level
                true // Structured format
            );
        }

        $this->logger->log($level, $message, $context);
    }

    // Log path metodları artık log_path_trait'te (GELISTIRME-010)

    // rotate_if_needed metodu artık logger sınıfında (GELISTIRME-005)

    /**
     * Üretim ortamında ayrıntılı hata mesajlarını gizler, sadece genel mesaj döndürür ve hatayı loglar.
     * Geliştirme ortamında ise gerçek hatayı döndürür.
     *
     * @param Exception|Throwable $e
     * @param string $generic_message Kullanıcıya gösterilecek genel mesaj (örn: "Bir hata oluştu.")
     * @return string Kullanıcıya gösterilecek mesaj
     */
    public function handle_exception(Exception|Throwable $e, string $generic_message = 'Bir hata oluştu.'): string
    {
        $context = [
            'exception' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        
        if (method_exists($e, 'getTraceAsString')) {
            $context['trace'] = $e->getTraceAsString();
        }
        
        $this->log_error($e->getMessage(), $context, \nsql\database\logging\logger::ERROR);
        
        if ($this->debug_mode) {
            return $e->getMessage();
        } else {
            return $generic_message;
        }
    }

    /**
     * Son yakalanan exception'ı saklar (hata ayıklama için)
     */
    private ?\Throwable $last_exception = null;

    /**
     * Son yakalanan exception'ı döndürür
     * 
     * @return \Throwable|null Son exception veya null
     */
    public function get_last_exception(): ?\Throwable
    {
        return $this->last_exception;
    }

    /**
     * Uygulama genelinde güvenli try-catch örüntüsü için yardımcı fonksiyon.
     * Kapatıcı (callable) fonksiyonu güvenli şekilde çalıştırır, hata olursa handleException ile işler.
     * 
     * İyileştirme: Exception'ı wrap edip döndürür, böylece hata türü korunur ve getPrevious() ile erişilebilir.
     *
     * @param callable $fn
     * @param string $generic_message
     * @return mixed Başarılı ise fonksiyon sonucu, hata durumunda false veya wrapped exception (debug mode)
     * @throws \RuntimeException Debug mode'da exception fırlatır
     */
    public function safe_execute(callable $fn, string $generic_message = 'Bir hata oluştu.'): mixed
    {
        try {
            $this->last_exception = null; // Başarılı çağrıda temizle
            return $fn();
        } catch (\nsql\database\exceptions\DatabaseException $e) {
            // Database exception'ları doğrudan kullan (zaten wrap edilmiş)
            $this->last_error = $e->getMessage();
            $this->last_exception = $e;
            $this->log_error("Database Exception: " . $e->getMessage(), [
                'exception' => get_class($e),
                'code' => $e->getCode(),
                'query' => $e->getQuery(),
            ]);
            
            if ($this->debug_mode) {
                throw $e; // Debug mode'da exception'ı olduğu gibi fırlat
            }
            
            // Production'da wrapped exception döndür (getPrevious() ile erişilebilir)
            return new \RuntimeException($generic_message, 0, $e);
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            $this->last_exception = $e;
            $this->log_error("PDO Error: " . $e->getMessage(), [
                'exception' => get_class($e),
                'code' => $e->getCode(),
                'error_info' => $e->errorInfo ?? [],
            ]);
            
            if ($this->debug_mode) {
                throw new \RuntimeException($generic_message . ': ' . $e->getMessage(), 0, $e);
            }
            
            // Production'da wrapped exception döndür
            return new \RuntimeException($generic_message, 0, $e);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->last_exception = $e;
            $this->log_error("General Error: " . $e->getMessage(), [
                'exception' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            if ($this->debug_mode) {
                throw $e;
            }
            
            // Production'da wrapped exception döndür
            return new \RuntimeException($generic_message, 0, $e);
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            $this->last_exception = $e;
            $this->log_error("Fatal Error: " . $e->getMessage(), [
                'exception' => get_class($e),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            if ($this->debug_mode) {
                throw $e;
            }
            
            // Production'da wrapped exception döndür
            return new \RuntimeException($generic_message, 0, $e);
        }
    }

    private static ?session_manager $session = null;

    /**
     * Session manager'ı başlatır veya mevcut instance'ı döndürür
     */
    public static function session(array $config = []): session_manager
    {
        if (self::$session === null) {
            self::$session = new session_manager($config);
        }

        return self::$session;
    }

    /**
     * Güvenli oturum başlatma ve cookie ayarları
     */
    public static function secure_session_start(array $config = []): void
    {
        self::session($config)->start();
    }

    /**
     * Session güvenli şekilde sonlandır
     */
    public static function end_session(): void
    {
        if (self::$session !== null) {
            self::$session->destroy();
            self::$session = null;
        }
    }

    /**
     * XSS koruması için HTML çıktısı kaçışlama fonksiyonu
     */
    public static function escape_html(mixed $string): string
    {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * CSRF token al veya oluştur
     */
    public static function csrf_token(): string
    {
        return self::session()->get_csrf_token();
    }

    /**
     * CSRF token doğrulaması yap
     */
    public static function validate_csrf(mixed $token): bool
    {
        return self::session()->validate_csrf_token((string)$token);
    }

    /**
     * Sorguyu çalıştırır (GELISTIRME-011: Complexity azaltma - helper metodlara bölündü)
     */
    private function execute_query(string $sql, array $params = [], ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $this->set_last_called_method();

        if ($this->streaming) {
            throw new \RuntimeException(
                'Unbuffered get_yield() akışı sürerken aynı bağlantıda sorgu çalıştırılamaz. '
                . 'Döngü içinde sorgu gerekiyorsa chunk_by_id() kullanın, ayrı bir nsql örneği açın '
                . 'veya get_yield(..., unbuffered: false) çağırın.'
            );
        }

        $this->ensure_connection();

        // PDO bağlantısı kontrolü
        if (!$this->validate_pdo_connection()) {
            return false;
        }

        // Sorgu bilgilerini kaydet
        $this->prepare_query_context($sql, $params);
        
        // Parametreleri validate et
        $this->validate_param_types($params);
        
        return $this->run_with_reconnect($sql, $params, $fetch_mode, ...$fetch_mode_args);
    }

    /**
     * Statement'ı hazırlar (veya cache'den alır), parametreleri bağlar ve fetch mode'u ayarlar.
     */
    private function prepare_bound_statement(string $sql, array $params, ?int $fetch_mode, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $stmt = $this->prepare_or_get_cached_statement($sql, $params);
        if ($stmt === false) {
            return false;
        }

        $this->bind_parameters($stmt, $params);

        if ($fetch_mode !== null) {
            $stmt->setFetchMode($fetch_mode, ...$fetch_mode_args);
        }

        return $stmt;
    }

    /**
     * PDO bağlantısını validate eder (GELISTIRME-011: Helper metod)
     */
    private function validate_pdo_connection(): bool
    {
        if ($this->pdo === null) {
            $this->last_error = 'PDO bağlantısı kurulamadı';
            $this->log_error($this->last_error);
            return false;
        }
        return true;
    }

    /**
     * Sorgu context'ini hazırlar (GELISTIRME-011: Helper metod)
     */
    private function prepare_query_context(string $sql, array $params): void
    {
        $this->last_query = $sql;
        $this->last_params = $params;
        $this->last_error = null;
    }

    /**
     * Statement'ı hazırlar veya cache'den alır (GELISTIRME-011: Helper metod)
     */
    private function prepare_or_get_cached_statement(string $sql, array $params): PDOStatement|false
    {
        $cache_key = $this->get_statement_cache_key($sql, $params);

        if (!isset($this->statement_cache[$cache_key])) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $this->add_to_statement_cache($cache_key, $stmt);
            } catch (PDOException $e) {
                $this->handle_prepare_error($e);
                return false;
            }
        } else {
            $stmt = $this->statement_cache[$cache_key];
        }

        $this->statement_cache_usage[$cache_key] = microtime(true);
        return $stmt;
    }

    /**
     * Parametreleri statement'a bağlar (GELISTIRME-011: Helper metod)
     */
    private function bind_parameters(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $param) {
            $param_name = $this->normalize_parameter_name($key);

            if (is_array($param) && array_key_exists('value', $param) && isset($param['type'])) {
                // Query Builder'dan gelen yapılandırılmış parametre
                $stmt->bindValue($param_name, $param['value'], $param['type']);
            } else {
                // Doğrudan değer olarak gelen parametre
                $param_type = $this->determine_param_type($param);
                $stmt->bindValue($param_name, $param, $param_type);
            }
        }
    }

    /**
     * Sorguyu çalıştırır; bağlantı koptuysa yeniden bağlanıp statement'ı yeni bağlantıda tekrar hazırlar.
     */
    private function run_with_reconnect(string $sql, array $params, ?int $fetch_mode, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $attempts = 0;

        while (true) {
            $stmt = $this->prepare_bound_statement($sql, $params, $fetch_mode, ...$fetch_mode_args);
            if ($stmt === false) {
                return false;
            }

            try {
                @$stmt->execute();
                $this->touch_connection();

                return $stmt;
            } catch (PDOException $e) {
                $attempts++;
                $this->handle_execution_error($e);

                if (! $this->should_retry($e, $attempts)) {
                    return false;
                }

                // Eski bağlantının statement'ları geçersiz; transaction içindeyse exception fırlatır
                $this->reconnect($e);
            }
        }
    }

    /**
     * Prepare hatasını handle eder (GELISTIRME-011: Helper metod)
     */
    private function handle_prepare_error(PDOException $e): void
    {
        $this->last_error = $e->getMessage();
        $this->log_error($this->last_error);
        $this->last_results = [];
    }

    /**
     * Execution hatasını handle eder (GELISTIRME-011: Helper metod)
     */
    private function handle_execution_error(PDOException $e): void
    {
        $this->last_error = $e->getMessage();
        $this->log_error($this->last_error);
        $this->last_results = [];
    }

    /**
     * Retry yapılmalı mı kontrol eder (GELISTIRME-011: Helper metod)
     */
    private function should_retry(PDOException $e, int $attempts): bool
    {
        return $this->is_connection_lost_error($e) && $attempts <= $this->retry_limit;
    }

    public function query(string $query, ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $this->set_last_called_method();
        
        // GELISTIRME-009: Error handling - exception fırlatma
        $result = $this->execute_query($query, [], $fetch_mode, ...$fetch_mode_args);
        
        if ($result === false && $this->last_error) {
            // Exception fırlat (testErrorHandling için)
            throw new \nsql\database\exceptions\QueryException(
                $this->last_error,
                $query,
                [],
                0,
                new \PDOException($this->last_error)
            );
        }

        if ($result !== false && ! preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $query)) {
            $this->invalidate_cache_for_write($query);
        }
        
        return $result;
    }

    public function insert(string $sql, array $params = []): int|false
    {
        $this->set_last_called_method();
        $this->last_results = [];
        $this->last_insert_id = 0;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt !== false && $this->pdo !== null) {
            // Driver'a göre last insert ID al
            if ($this->driver) {
                $this->last_insert_id = $this->driver->get_last_insert_id($this->pdo);
            } else {
                $this->last_insert_id = (int)$this->pdo->lastInsertId();
            }

            $this->invalidate_cache_for_write($sql);

            return $this->last_insert_id;
        }

        return false;
    }

    /**
     * Batch insert işlemi yapar (toplu ekleme)
     *
     * @param string $table Tablo adı
     * @param array $data İnsert edilecek veriler (her eleman bir satır)
     * @param bool $use_transaction Transaction kullanılsın mı? (varsayılan: true)
     * @return int Eklenen satır sayısı
     * @throws exceptions\QueryException
     */
    public function batch_insert(string $table, array $data, bool $use_transaction = true): int
    {
        if (empty($data)) {
            return 0;
        }

        // İlk satırdan sütun adlarını al
        $first_row = reset($data);
        if (! is_array($first_row)) {
            throw new exceptions\QueryException('Batch insert için her satır bir array olmalıdır.');
        }

        $columns = array_keys($first_row);
        $columns_str = implode(', ', array_map(fn($col) => $this->quote_identifier($col), $columns));
        
        // Placeholder'ları oluştur
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        
        // Tüm satırlar için placeholder'ları birleştir
        $all_placeholders = implode(', ', array_fill(0, count($data), $placeholders));
        
        // Tüm değerleri düzleştir
        $values = [];
        foreach ($data as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $sql = "INSERT INTO {$this->quote_identifier($table)} ({$columns_str}) VALUES {$all_placeholders}";

        try {
            if ($use_transaction) {
                $this->begin();
            }

            $stmt = $this->execute_query($sql, $values);
            
            if ($stmt === false) {
                if ($use_transaction) {
                    $this->rollback();
                }
                throw new exceptions\QueryException('Batch insert başarısız oldu.', $sql, $values);
            }

            $affected_rows = $stmt->rowCount();
            $this->invalidate_cache_for_write($sql);

            if ($use_transaction) {
                $this->commit();
            }

            return $affected_rows;
        } catch (\Exception $e) {
            if ($use_transaction) {
                $this->rollback();
            }
            
            if ($e instanceof exceptions\QueryException) {
                throw $e;
            }
            
            throw new exceptions\QueryException('Batch insert hatası: ' . $e->getMessage(), $sql, $values, 0, $e);
        }
    }

    /**
     * Batch update işlemi yapar (toplu güncelleme)
     *
     * @param string $table Tablo adı
     * @param array $data Güncellenecek veriler (her eleman bir satır, 'id' veya belirtilen key ile eşleşir)
     * @param string $key_column Eşleştirme için kullanılacak sütun (varsayılan: 'id')
     * @param bool $use_transaction Transaction kullanılsın mı? (varsayılan: true)
     * @return int Güncellenen satır sayısı
     * @throws exceptions\QueryException
     */
    public function batch_update(string $table, array $data, string $key_column = 'id', bool $use_transaction = true): int
    {
        if (empty($data)) {
            return 0;
        }

        $total_affected = 0;

        try {
            if ($use_transaction) {
                $this->begin();
            }

            foreach ($data as $row) {
                if (! is_array($row) || ! isset($row[$key_column])) {
                    continue;
                }

                $key_value = $row[$key_column];
                unset($row[$key_column]);

                if (empty($row)) {
                    continue;
                }

                // SET clause oluştur
                $set_parts = [];
                $params = [];
                foreach ($row as $column => $value) {
                    $set_parts[] = $this->quote_identifier($column) . ' = ?';
                    $params[] = $value;
                }

                $set_clause = implode(', ', $set_parts);
                $params[] = $key_value;

                $sql = "UPDATE {$this->quote_identifier($table)} SET {$set_clause} WHERE {$this->quote_identifier($key_column)} = ?";

                $stmt = $this->execute_query($sql, $params);
                
                if ($stmt !== false) {
                    $total_affected += $stmt->rowCount();
                }
            }

            $this->invalidate_cache_for_write("UPDATE {$this->quote_identifier($table)}");

            if ($use_transaction) {
                $this->commit();
            }

            return $total_affected;
        } catch (\Exception $e) {
            if ($use_transaction) {
                $this->rollback();
            }
            
            if ($e instanceof exceptions\QueryException) {
                throw $e;
            }
            
            throw new exceptions\QueryException('Batch update hatası: ' . $e->getMessage(), '', [], 0, $e);
        }
    }

    /**
     * Identifier'ı quote eder (driver'a göre)
     *
     * @param string $identifier Identifier
     * @return string Quoted identifier
     */
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
            // Hata yönetimi: PDO hatasını tetikle
            $errorInfo = ($this->pdo !== null) ? $this->pdo->errorInfo() : ['Hata', 0, 'Sorgu çalıştırılamadı'];
            trigger_error('get_row: Sorgu başarısız! PDO error: ' . print_r($errorInfo, true), E_USER_WARNING);
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
     * Büyük veri setlerini satır satır döndürür (Generator)
     *
     * Unbuffered modda (YIELD_UNBUFFERED=true veya $unbuffered=true) sorgu tek seferde çalışır ve
     * satırlar sunucudan okundukça döner: sabit bellek, OFFSET yok. Akış sürerken aynı nsql
     * örneğinde başka sorgu çalıştırılamaz (MySQL kısıtı); döngü içinde yazma gerekiyorsa
     * chunk_by_id() kullanın. Varsayılan (1.x) mod: LIMIT/OFFSET ile parça parça okuma.
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @param bool|null $unbuffered null = YIELD_UNBUFFERED ayarı
     * @return \Generator<int, object>
     */
    public function get_yield(string $query, array $params = [], ?bool $unbuffered = null): \Generator
    {
        $this->set_last_called_method();

        if ($unbuffered ?? (bool) config::get('yield_unbuffered', false)) {
            yield from $this->stream_query($query, $params);

            return;
        }

        if ($this->has_top_level_limit($query)) {
            throw new \InvalidArgumentException('get_yield() metodu LIMIT veya OFFSET içeren sorgularla kullanılamaz.');
        }

        $offset = 0;
        $chunk_size = (int) config::get('default_chunk_size', config::default_chunk_size);
        $total_rows = 0;
        $stmt = null;

        // PDO bağlantısı kontrolü
        if ($this->pdo === null) {
            $this->ensure_connection();
            if ($this->pdo === null) {
                return;
            }
        }

        try {
            while (true) {
                $this->check_memory_status();
                $this->adjust_chunk_size();

                // Önceki statement'ı temizle (memory leak önleme)
                if ($stmt !== null) {
                    $stmt->closeCursor();
                    $stmt = null;
                }

                // Chunk sorgusu oluştur ve çalıştır
                $chunk_query = $query . " LIMIT " . $chunk_size . " OFFSET " . $offset;
                $stmt = $this->execute_query($chunk_query, $params);

                if ($stmt === false) {
                    break;
                }

                // Satırları yield et
                $found_rows = false;
                while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
                    $found_rows = true;
                    $total_rows++;

                    // Memory optimizasyonu (config'den al)
                    $cleanup_interval = \nsql\database\config::get('generator_cleanup_interval', 1000);
                    if ($total_rows % $cleanup_interval === 0) {
                        $this->cleanup_resources();
                    }

                    yield $row;
                }

                // Tüm satırlar okunduysa çık
                if (! $found_rows) {
                    break;
                }

                $offset += $chunk_size;

                // Maksimum limit kontrolü
                if ($offset >= $this->max_result_set_size()) {
                    throw new \RuntimeException(
                        sprintf(
                            'Maksimum sonuç kümesi boyutu aşıldı! (Limit: %d)',
                            $this->max_result_set_size()
                        )
                    );
                }

                // Her chunk'tan sonra GC çağır (daha agresif cleanup)
                $gc_interval_multiplier = \nsql\database\config::get('generator_gc_interval_multiplier', 5);
                if ($offset % (config::default_chunk_size * $gc_interval_multiplier) === 0) {
                    gc_collect_cycles();
                }
            }
        } finally {
            // Explicit cleanup: Statement'ı temizle
            if ($stmt !== null) {
                $stmt->closeCursor();
                $stmt = null;
            }
            
            // Final GC çağrısı
            gc_collect_cycles();
        }
    }

    public function update(string $sql, array $params = []): bool
    {
        $this->set_last_called_method();
        $this->last_results = [];

        $result = $this->execute_query($sql, $params) !== false;
        if ($result) {
            $this->invalidate_cache_for_write($sql);
        }
        
        return $result;
    }

    public function delete(string $sql, array $params = []): bool
    {
        $this->set_last_called_method();
        $this->last_results = [];

        $result = $this->execute_query($sql, $params) !== false;
        if ($result) {
            $this->invalidate_cache_for_write($sql);
        }
        
        return $result;
    }

    /**
     * Son eklenen kaydın ID değerini döndürür.
     *
     * @return int Son eklenen kaydın ID değeri.
     */
    public function insert_id(): int|string
    {
        if ($this->driver && $this->pdo) {
            // Driver'a göre last insert ID al
            return $this->driver->get_last_insert_id($this->pdo);
        }
        return $this->last_insert_id;
    }

    /**
     * Memory durumunu kontrol eder (optimize edilmiş)
     */
    private function check_memory_status(): void
    {
        $now = time();

        if (self::$last_memory_check !== null &&
            ($now - self::$last_memory_check) < (int) config::get('memory_check_interval', config::memory_check_interval)) {
            return;
        }

        self::$last_memory_check = $now;
        $current_usage = memory_get_usage(true);
        $peak_usage = memory_get_peak_usage(true);
        
        // Peak usage'ı sadece artış varsa güncelle (performans optimizasyonu)
        if ($peak_usage > self::$memory_stats['peak_usage']) {
            self::$memory_stats['peak_usage'] = $peak_usage;
        }

        $thresholds = $this->memory_thresholds();

        if ($current_usage > $thresholds['critical']) {
            self::$memory_stats['critical_count']++;
            $this->cleanup_resources();
            
            // Daha detaylı hata mesajı
            throw new \RuntimeException(
                sprintf(
                    'Kritik bellek kullanımı aşıldı! Mevcut: %s, Limit: %s',
                    $this->format_bytes($current_usage),
                    $this->format_bytes($thresholds['critical'])
                )
            );
        }

        if ($current_usage > $thresholds['warning']) {
            self::$memory_stats['warning_count']++;
            $this->cleanup_resources();
            
            // Debug modunda uyarı logla
            if ($this->debug_mode) {
                $this->log_debug_info(
                    'Memory Warning',
                    sprintf(
                        'Bellek uyarı seviyesi aşıldı: %s (Limit: %s)',
                        $this->format_bytes($current_usage),
                        $this->format_bytes($thresholds['warning'])
                    )
                );
            }
        }
    }

    /**
     * Bellek eşikleri (byte).
     *
     * MEMORY_LIMIT_WARNING / MEMORY_LIMIT_CRITICAL açıkça ayarlanmışsa mutlak değer kullanılır;
     * aksi halde ini memory_limit × MEMORY_WARNING_RATIO (0.75) / MEMORY_CRITICAL_RATIO (0.9).
     * memory_limit=-1 ise kritik eşik yoktur, uyarı eşiği config::memory_limit_warning'dir.
     *
     * @return array{warning: int, critical: int}
     */
    private function memory_thresholds(): array
    {
        $limit = $this->get_memory_limit();
        $unlimited = $limit === PHP_INT_MAX;

        $warning = config::has('memory_limit_warning')
            ? (int) config::get('memory_limit_warning')
            : ($unlimited ? config::memory_limit_warning : (int) ($limit * (float) config::get('memory_warning_ratio', 0.75)));

        $critical = config::has('memory_limit_critical')
            ? (int) config::get('memory_limit_critical')
            : ($unlimited ? PHP_INT_MAX : (int) ($limit * (float) config::get('memory_critical_ratio', 0.9)));

        return ['warning' => max(1, $warning), 'critical' => max(1, $critical)];
    }

    private function max_result_set_size(): int
    {
        return (int) config::get('max_result_set_size', config::max_result_set_size);
    }

    /**
     * Sorgunun en dış seviyesinde LIMIT/OFFSET var mı? Subquery ve string literal'ler yok sayılır.
     */
    private function has_top_level_limit(string $query): bool
    {
        $stripped = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`/s', "''", $query);
        do {
            $stripped = (string) preg_replace('/\([^()]*\)/', ' ', $stripped, -1, $count);
        } while ($count > 0);

        return preg_match('/\b(LIMIT|OFFSET)\b/i', $stripped) === 1;
    }

    /**
     * Sorguyu tek seferde çalıştırıp satırları sunucudan okundukça döndürür (MySQL'de unbuffered).
     *
     * @return \Generator<int, object>
     */
    private function stream_query(string $query, array $params): \Generator
    {
        if ($this->streaming) {
            throw new \RuntimeException('Bu bağlantıda zaten aktif bir get_yield() akışı var.');
        }

        $this->ensure_connection();
        $pdo = $this->pdo;
        if ($pdo === null) {
            $this->validate_pdo_connection();

            return;
        }

        $is_mysql = $this->get_driver_name() === 'mysql';
        $previous_buffered = $is_mysql ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;

        $this->prepare_query_context($query, $params);
        $this->validate_param_types($params);

        $stmt = null;
        try {
            if ($is_mysql) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }

            // Statement cache'e alınmaz: unbuffered cursor kapanmadan aynı statement yeniden kullanılamaz
            try {
                $stmt = $pdo->prepare($query);
                if ($stmt === false) {
                    return;
                }
                $this->bind_parameters($stmt, $params);
                $stmt->execute();
                $this->touch_connection();
            } catch (PDOException $e) {
                $this->handle_execution_error($e);

                return;
            }

            $this->streaming = true;
            $cleanup_interval = max(1, (int) config::get('generator_cleanup_interval', 1000));
            $rows = 0;

            while (($row = $stmt->fetch(PDO::FETCH_OBJ)) !== false) {
                if (++$rows % $cleanup_interval === 0) {
                    $this->check_memory_status();
                }

                yield $row;
            }
        } finally {
            $this->streaming = false;
            if ($stmt instanceof PDOStatement) {
                $stmt->closeCursor();
            }
            $stmt = null;
            if ($is_mysql && $this->pdo === $pdo) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previous_buffered);
            }
        }
    }

    /**
     * Keyset (seek) tabanlı parça parça okuma: OFFSET kullanmaz, satır atlamaz/tekrarlamaz.
     *
     * Sorgu türetilmiş tablo olarak sarılır:
     * SELECT * FROM (<sorgu>) nsql_chunk WHERE <kolon> > :son ORDER BY <kolon> LIMIT <boyut>
     * Kolon sonuçta benzersiz ve sıralanabilir olmalıdır (genellikle birincil anahtar).
     * Her parça ayrı sorgu olduğundan döngü içinde aynı bağlantıda yazma yapılabilir.
     *
     * @param string $query SQL sorgusu (kendi ORDER BY / LIMIT'i olmamalı)
     * @param array $params Sorgu parametreleri (named veya positional)
     * @param string $column Keyset kolonu (sonuç kümesindeki ad)
     * @param int $size Parça boyutu
     * @return \Generator<int, list<object>>
     */
    public function chunk_by_id(string $query, array $params = [], string $column = 'id', int $size = 1000): \Generator
    {
        $this->set_last_called_method();

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException("Geçersiz keyset kolonu: {$column}");
        }
        if ($size < 1) {
            throw new \InvalidArgumentException('Parça boyutu en az 1 olmalıdır.');
        }

        $quoted = $this->get_driver_name() === 'mysql' ? "`{$column}`" : "\"{$column}\"";
        $positional = $params !== [] && array_is_list($params);
        $placeholder = $positional ? '?' : ':nsql_chunk_last';
        $base = 'SELECT * FROM (' . rtrim(trim($query), ';') . ') nsql_chunk';

        $last = null;
        while (true) {
            $sql = $base
                . ($last === null ? '' : " WHERE nsql_chunk.{$quoted} > {$placeholder}")
                . " ORDER BY nsql_chunk.{$quoted} LIMIT {$size}";

            $bound = $params;
            if ($last !== null) {
                if ($positional) {
                    $bound[] = $last;
                } else {
                    $bound['nsql_chunk_last'] = $last;
                }
            }

            $stmt = $this->execute_query($sql, $bound);
            if ($stmt === false) {
                return;
            }
            $rows = $stmt->fetchAll(PDO::FETCH_OBJ);
            $stmt->closeCursor();

            if ($rows === []) {
                return;
            }

            $tail = $rows[count($rows) - 1];
            if (! property_exists($tail, $column)) {
                throw new \InvalidArgumentException("chunk_by_id(): sorgu sonucunda '{$column}' kolonu yok.");
            }
            $last = $tail->{$column};

            yield $rows;

            if (count($rows) < $size) {
                return;
            }
        }
    }

    /**
     * Kaynakları temizler
     */
    private function cleanup_resources(): void
    {
        $this->clear_statement_cache();
        $this->clear_query_cache();
        gc_collect_cycles();
    }

    /**
     * Byte değerini okunabilir formata çevirir
     */
    private function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Chunk boyutunu bellek kullanımına göre ayarlar (optimize edilmiş)
     */
    private function adjust_chunk_size(): void
    {
        self::initialize_static_vars();

        if (! (bool) config::get('auto_adjust_chunk_size', config::auto_adjust_chunk_size)) {
            self::$current_chunk_size = (int) config::get('default_chunk_size', config::default_chunk_size);
            return;
        }

        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->memory_thresholds()['warning'];
        $usage_ratio = $memory_usage / $memory_limit;

        // Daha agresif chunk size ayarlaması (performans optimizasyonu)
        if ($usage_ratio > 0.75) {
            // Bellek kullanımı yüksekse chunk size'ı daha agresif azalt
            self::$current_chunk_size = max(
                (int) config::get('min_chunk_size', config::min_chunk_size),
                (int)(self::$current_chunk_size * 0.6) // 0.5 → 0.6 (daha yumuşak azalma)
            );
        } elseif ($usage_ratio < 0.4) {
            // Bellek kullanımı düşükse chunk size'ı artır
            self::$current_chunk_size = min(
                (int) config::get('max_chunk_size', config::max_chunk_size),
                (int)(self::$current_chunk_size * 1.3) // 1.5 → 1.3 (daha yumuşak artış)
            );
        }

        // Debug modunda chunk size değişikliklerini logla
        if ($this->debug_mode && $usage_ratio > 0.7) {
            $this->log_debug_info(
                'Chunk Size Adjustment',
                sprintf(
                    'Chunk size ayarlandı: %d (Memory usage: %s, Ratio: %.2f)',
                    self::$current_chunk_size,
                    $this->format_bytes($memory_usage),
                    $usage_ratio
                )
            );
        }
    }

    /**
     * Büyük veri setlerini chunk'lar halinde döndürür (LIMIT/OFFSET).
     *
     * Büyük tablolarda OFFSET maliyeti artar ve ORDER BY yoksa satır sırası garanti değildir;
     * birincil anahtarlı tablolarda chunk_by_id() tercih edin.
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @param int|null $chunk_size Chunk boyutu (opsiyonel, verilmezse config'deki default değer kullanılır)
     * @return \Generator Her chunk için bir array döndürür
     */
    public function get_chunk(string $query, array $params = [], ?int $chunk_size = null): \Generator
    {
        $this->set_last_called_method();

        if ($this->has_top_level_limit($query)) {
            throw new \InvalidArgumentException('get_chunk() metodu LIMIT veya OFFSET içeren sorgularla kullanılamaz.');
        }

        $offset = 0;
        // Chunk size belirtilmişse kullan, yoksa config'deki default değeri kullan
        if ($chunk_size !== null && $chunk_size > 0) {
            self::$current_chunk_size = $chunk_size;
        } else {
            self::$current_chunk_size = (int) config::get('default_chunk_size', config::default_chunk_size);
        }
        $total_rows = 0;

        try {
            // Chunk size sabit belirtilmişse auto-adjust'u devre dışı bırak
            $use_auto_adjust = ($chunk_size === null);
            
            while (true) {
                // Memory kontrolü
                $this->check_memory_status();
                
                // Chunk size sabit belirtilmemişse auto-adjust kullan
                if ($use_auto_adjust) {
                    $this->adjust_chunk_size();
                }

                // Chunk sorgusu oluştur
                $chunk_query = $query . " LIMIT " . self::$current_chunk_size . " OFFSET " . $offset;

                $stmt = $this->execute_query($chunk_query, $params);
                if ($stmt === false) {
                    return;
                }
                $results = $stmt->fetchAll(PDO::FETCH_OBJ);
                $stmt->closeCursor();

                // Sonuç yoksa döngüyü bitir
                if (empty($results)) {
                    break;
                }

                // Sonuçları yield et
                yield $results;

                // Sayaçları güncelle
                $total_rows += count($results);
                $offset += self::$current_chunk_size;

                // Maksimum limit kontrolü
                if ($offset >= $this->max_result_set_size()) {
                    throw new \RuntimeException(
                        sprintf(
                            'Maksimum sonuç kümesi boyutu aşıldı! (Limit: %d)',
                            $this->max_result_set_size()
                        )
                    );
                }

                // Bellek optimizasyonu
                if ($offset % (config::default_chunk_size * 10) === 0) {
                    $this->cleanup_resources();
                    gc_collect_cycles();
                }
            }
        } finally {
            gc_collect_cycles();
        }
    }

    /**
     * Memory istatistiklerini döndürür
     */
    public function get_memory_stats(): array
    {
        $limit = $this->get_memory_limit();
        $thresholds = $this->memory_thresholds();

        return array_merge(self::$memory_stats, [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'limit' => $limit,
            'warning_threshold' => $thresholds['warning'],
            'critical_threshold' => $thresholds['critical'],
            'current_chunk_size' => self::$current_chunk_size ?? config::default_chunk_size,
        ]);
    }

    /**
     * Memory limit'i döndürür
     */
    private function get_memory_limit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        
        $limit_bytes = $this->parse_memory_limit($limit);
        return $limit_bytes > 0 ? $limit_bytes : config::memory_limit_critical;
    }

    /**
     * Memory limit string'ini bytes'a çevirir
     */
    private function parse_memory_limit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtolower(substr($limit, -1));
        $value = (int)$limit;
        
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
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

    // Query analyzer ilgili metodlar query_analyzer_trait içinde tanımlanmıştır

    /**
     * Test uyumu için camelCase statik proxy'ler
     */

    /**
     * Son hatayı döndürür
     */
    public function get_last_error(): ?string
    {
        return $this->last_error;
    }

    /**
     * Debug bilgilerini loglar (structured logging ile)
     */
    public function log_debug_info(string $message, mixed $data = null): void
    {
        if ($this->debug_mode) {
            if ($this->logger === null) {
                $this->logger = new \nsql\database\logging\logger(
                    $this->log_file,
                    null,
                    true
                );
            }
            
            $context = [];
            if ($data !== null) {
                $context['data'] = $data;
            }
            
            $this->logger->debug($message, $context);
        }
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
