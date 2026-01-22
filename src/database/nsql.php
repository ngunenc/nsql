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

class nsql extends PDO
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

    // Database bağlantı özellikleri
    private ?PDO $pdo = null;
    private int $last_insert_id = 0;
    private array $options = [];
    private string $dsn = '';
    private ?string $user = null;
    private ?string $pass = null;
    private int $retry_limit = 2;
    private static bool $pool_initialized = false;
    private static array $pool_config = [];
    private ?driver_interface $driver = null;

    // Cache özellikleri
    private array $query_cache = [];
    private array $query_cache_usage = [];
    private bool $query_cache_enabled = false;
    private int $query_cache_timeout = 3600;
    private int $query_cache_size_limit = 100;

    // Statement cache özellikleri
    private array $statement_cache = [];
    private array $statement_cache_usage = [];
    private int $statement_cache_limit = 100;

    // Sorgu sonuçları
    private array $last_results = [];

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

    private function initialize_connection(): void
    {
        try {
            $this->pdo = connection_pool::get_connection();
        } catch (PDOException $e) {
            throw new RuntimeException("Veritabanı bağlantı hatası: " . $e->getMessage());
        }
    }

    private function initialize_pool(): void
    {
        if (! self::$pool_initialized) {
            self::$pool_config = [
                'dsn' => $this->dsn,
                'username' => $this->user,
                'password' => $this->pass,
                'options' => $this->options,
            ];

            connection_pool::initialize(
                self::$pool_config,
                (int)config::get('min_connections', 2),
                (int)config::get('max_connections', 10)
            );

            self::$pool_initialized = true;
        }
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
        $db = $db ?? config::get('db_name', 'etiyop');
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
            \PDO::ATTR_PERSISTENT => (int)(bool)config::get('persistent_connection', false),
        ]);
        
        // MySQL için timeout DSN'e eklenir (PDO attribute olarak desteklenmez)
        if ($driver_name === 'mysql' && config::has('connection_timeout')) {
            $timeout = (int)config::get('connection_timeout', 5);
            if (strpos($this->dsn, 'timeout=') === false) {
                $this->dsn .= ";timeout={$timeout}";
            }
        }

        $this->debug_mode = (bool)($debug ?? config::get('debug_mode', false));
        $this->log_file = (string)config::get('log_file', 'error_log.txt');
        $this->statement_cache_limit = (int)config::get('statement_cache_limit', 100);

        // Parent PDO constructor'ı çağır
        parent::__construct($this->dsn, $this->user, $this->pass, $this->options);

        self::initialize_static_vars();
        $this->initialize_pool();
        $this->initialize_connection();
        $this->load_cache_config();
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

    private function disconnect(): void
    {
        if ($this->pdo !== null) {
            connection_pool::release_connection($this->pdo);
            $this->pdo = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    // Connection Pool istatistiklerini almak için yeni metod
    public static function get_pool_stats(): array
    {
        return connection_pool::get_stats();
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

    /**
     * Veritabanı bağlantısının canlı olup olmadığını kontrol eder, kopmuşsa yeniden bağlanır.
     * @return void
     */
    public function ensure_connection(): void
    {
        if ($this->pdo === null) {
            $this->connect();
            return;
        }
        
        try {
            $stmt = $this->pdo->query('SELECT 1');
            if ($stmt === false) {
                $this->connect();
            }
        } catch (PDOException $e) {
            $this->connect();
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
        $this->ensure_connection();

        // PDO bağlantısı kontrolü
        if (!$this->validate_pdo_connection()) {
            return false;
        }

        // Sorgu bilgilerini kaydet
        $this->prepare_query_context($sql, $params);
        
        // Parametreleri validate et
        $this->validate_param_types($params);
        
        // Statement'ı hazırla veya cache'den al
        $stmt = $this->prepare_or_get_cached_statement($sql, $params);
        if ($stmt === false) {
            return false;
        }

        // Parametreleri bağla
        $this->bind_parameters($stmt, $params);

        // Fetch mode ayarla
        if ($fetch_mode !== null) {
            $stmt->setFetchMode($fetch_mode, ...$fetch_mode_args);
        }

        // Sorguyu çalıştır (retry logic ile)
        return $this->execute_with_retry($stmt, $sql);
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

            if (is_array($param) && isset($param['value'], $param['type'])) {
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
     * Retry logic ile sorguyu çalıştırır (GELISTIRME-011: Helper metod)
     */
    private function execute_with_retry(PDOStatement $stmt, string $sql): PDOStatement|false
    {
        $attempts = 0;
        
        do {
            try {
                $stmt->execute();
                return $stmt;
            } catch (PDOException $e) {
                $attempts++;
                $this->handle_execution_error($e);

                $error_code = $e->errorInfo[1] ?? null;
                if ($this->should_retry($error_code, $attempts)) {
                    $this->initialize_connection();
                    continue;
                }

                return false;
            }
        } while ($attempts <= $this->retry_limit);

        return false;
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
    private function should_retry(?int $error_code, int $attempts): bool
    {
        $recoverable_codes = [2006, 2013]; // MySQL server has gone away, Lost connection
        return in_array($error_code, $recoverable_codes, true) && $attempts <= $this->retry_limit;
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

            // Cache invalidation: INSERT işlemi sonrası ilgili tabloların cache'ini temizle
            if ($this->query_cache_enabled) {
                $tables = $this->extract_tables_from_query($sql);
                if (!empty($tables)) {
                    $this->invalidate_cache_by_table($tables);
                }
            }

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
    private function quote_identifier(string $identifier): string
    {
        if ($this->driver) {
            $quote = $this->driver->get_identifier_quote();
            return $quote . $identifier . $quote;
        }
        
        // Varsayılan: backtick (MySQL)
        return '`' . $identifier . '`';
    }

    public function get_row(string $query, array $params = []): ?object
    {
        $this->set_last_called_method();
        // LIMIT 1 ekle eğer yoksa
        if (! preg_match('/\bLIMIT\s+\d+(?:\s*,\s*\d+)?$/i', $query)) {
            $query .= ' LIMIT 1';
        }

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

        // Büyük veri setleri için optimizasyon
        $result_count = $stmt->rowCount();
        if ($result_count > config::large_result_warning) {
            trigger_error(
                "Büyük veri seti ($result_count satır). get_chunk() veya get_yield() kullanmayı düşünün.",
                E_USER_NOTICE
            );
        }

        // Sonuçları al, last_results ve cache'i guncelle
        $results = $stmt->fetchAll(PDO::FETCH_OBJ);
        $this->last_results = $results;
        if ($this->query_cache_enabled && count($results) <= $this->query_cache_size_limit) {
            $this->add_to_query_cache($cache_key, $results);
        }

        return $results;
    }

    /**
     * Büyük veri setlerini satır satır döndürür (Generator)
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @return \Generator
     */
    public function get_yield(string $query, array $params = []): \Generator
    {
        $this->set_last_called_method();

        // LIMIT ve OFFSET kontrolü
        if (preg_match('/\b(LIMIT|OFFSET)\b/i', $query)) {
            throw new \InvalidArgumentException('get_yield() metodu LIMIT veya OFFSET içeren sorgularla kullanılamaz.');
        }

        $offset = 0;
        $chunk_size = config::default_chunk_size;
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
                if ($offset >= config::max_result_set_size) {
                    throw new \RuntimeException(
                        sprintf(
                            'Maksimum sonuç kümesi boyutu aşıldı! (Limit: %d)',
                            config::max_result_set_size
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
        
        // Cache invalidation: UPDATE işlemi sonrası ilgili tabloların cache'ini temizle
        if ($result && $this->query_cache_enabled) {
            $tables = $this->extract_tables_from_query($sql);
            if (!empty($tables)) {
                $this->invalidate_cache_by_table($tables);
            }
        }
        
        return $result;
    }

    public function delete(string $sql, array $params = []): bool
    {
        $this->set_last_called_method();
        $this->last_results = [];

        $result = $this->execute_query($sql, $params) !== false;
        
        // Cache invalidation: DELETE işlemi sonrası ilgili tabloların cache'ini temizle
        if ($result && $this->query_cache_enabled) {
            $tables = $this->extract_tables_from_query($sql);
            if (!empty($tables)) {
                $this->invalidate_cache_by_table($tables);
            }
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
     * Bir veritabanı işlemi başlatır.
     * Trait'teki begin() metodunu kullanır (nested transaction desteği ile)
     *
     * @return void
     * @throws RuntimeException PDO bağlantısı yoksa
     */
    public function begin(): void
    {
        if ($this->pdo === null) {
            throw new RuntimeException('PDO bağlantısı kurulamadı');
        }
        
        // Trait'teki begin() metodunu kullan (nested transaction desteği ile)
        // transaction_trait'teki begin() metodu zaten transaction_level kontrolü yapıyor
        if ($this->transaction_level === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT trans{$this->transaction_level}");
        }
        $this->transaction_level++;
    }

    /**
     * Bir veritabanı işlemini tamamlar ve değişiklikleri kaydeder.
     * Trait'teki commit() metodunu kullanır (nested transaction desteği ile)
     *
     * @return bool İşlem başarılıysa true, değilse false döndürür
     * @throws RuntimeException PDO bağlantısı yoksa
     */
    public function commit(): bool
    {
        if ($this->pdo === null) {
            throw new RuntimeException('PDO bağlantısı kurulamadı');
        }
        
        // Trait'teki commit() metodunu kullan (nested transaction desteği ile)
        if ($this->transaction_level === 0) {
            return false; // Transaction yok
        }
        
        $this->transaction_level--;
        
        if ($this->transaction_level === 0) {
            return $this->pdo->commit();
        } elseif ($this->transaction_level > 0) {
            return $this->pdo->exec("RELEASE SAVEPOINT trans{$this->transaction_level}") !== false;
        }
        
        return false;
    }

    /**
     * Bir veritabanı işlemini geri alır.
     * Trait'teki rollback() metodunu kullanır (nested transaction desteği ile)
     *
     * @return bool İşlem başarılıysa true, değilse false döndürür
     */
    public function rollback(): bool
    {
        if ($this->pdo === null) {
            throw new RuntimeException('PDO bağlantısı kurulamadı');
        }
        
        // Trait'teki rollback() metodunu kullan (nested transaction desteği ile)
        if ($this->transaction_level === 0) {
            return false; // Transaction yok
        }
        
        $this->transaction_level--;
        
        if ($this->transaction_level === 0) {
            return $this->pdo->rollBack();
        } else {
            return $this->pdo->exec("ROLLBACK TO SAVEPOINT trans{$this->transaction_level}") !== false;
        }
    }

    /**
     * Son çalıştırılan sorgunun detaylarını ve hata ayıklama bilgilerini gösterir.
     *
     * @return void
     */
    private function set_last_called_method(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $this->last_called_method = $trace[1]['function'] ?? 'unknown';
    }

    /**
     * Memory durumunu kontrol eder (optimize edilmiş)
     */
    private function check_memory_status(): void
    {
        $now = time();

        if (self::$last_memory_check !== null &&
            ($now - self::$last_memory_check) < config::memory_check_interval) {
            return;
        }

        self::$last_memory_check = $now;
        $current_usage = memory_get_usage(true);
        $peak_usage = memory_get_peak_usage(true);
        
        // Peak usage'ı sadece artış varsa güncelle (performans optimizasyonu)
        if ($peak_usage > self::$memory_stats['peak_usage']) {
            self::$memory_stats['peak_usage'] = $peak_usage;
        }

        if ($current_usage > config::memory_limit_critical) {
            self::$memory_stats['critical_count']++;
            $this->cleanup_resources();
            
            // Daha detaylı hata mesajı
            throw new \RuntimeException(
                sprintf(
                    'Kritik bellek kullanımı aşıldı! Mevcut: %s, Limit: %s',
                    $this->format_bytes($current_usage),
                    $this->format_bytes(config::memory_limit_critical)
                )
            );
        }

        if ($current_usage > config::memory_limit_warning) {
            self::$memory_stats['warning_count']++;
            $this->cleanup_resources();
            
            // Debug modunda uyarı logla
            if ($this->debug_mode) {
                $this->log_debug_info(
                    'Memory Warning',
                    sprintf(
                        'Bellek uyarı seviyesi aşıldı: %s (Limit: %s)',
                        $this->format_bytes($current_usage),
                        $this->format_bytes(config::memory_limit_warning)
                    )
                );
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

        if (! config::auto_adjust_chunk_size) {
            self::$current_chunk_size = config::default_chunk_size;
            return;
        }

        $memory_usage = memory_get_usage(true);
        $memory_limit = config::memory_limit_warning;
        $usage_ratio = $memory_usage / $memory_limit;

        // Daha agresif chunk size ayarlaması (performans optimizasyonu)
        if ($usage_ratio > 0.75) {
            // Bellek kullanımı yüksekse chunk size'ı daha agresif azalt
            self::$current_chunk_size = max(
                config::min_chunk_size,
                (int)(self::$current_chunk_size * 0.6) // 0.5 → 0.6 (daha yumuşak azalma)
            );
        } elseif ($usage_ratio < 0.4) {
            // Bellek kullanımı düşükse chunk size'ı artır
            self::$current_chunk_size = min(
                config::max_chunk_size,
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
     * Büyük veri setlerini chunk'lar halinde döndürür
     *
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @param int|null $chunk_size Chunk boyutu (opsiyonel, verilmezse config'deki default değer kullanılır)
     * @return \Generator Her chunk için bir array döndürür
     */
    public function get_chunk(string $query, array $params = [], ?int $chunk_size = null): \Generator
    {
        $this->set_last_called_method();

        // LIMIT ve OFFSET kontrolü
        if (preg_match('/\b(LIMIT|OFFSET)\b/i', $query)) {
            throw new \InvalidArgumentException('get_chunk() metodu LIMIT veya OFFSET içeren sorgularla kullanılamaz.');
        }

        $offset = 0;
        // Chunk size belirtilmişse kullan, yoksa config'deki default değeri kullan
        if ($chunk_size !== null && $chunk_size > 0) {
            self::$current_chunk_size = $chunk_size;
        } else {
            self::$current_chunk_size = config::default_chunk_size;
        }
        $total_rows = 0;

        // PDO bağlantısı kontrolü
        if ($this->pdo === null) {
            $this->ensure_connection();
            if ($this->pdo === null) {
                return;
            }
        }
        
        // Prepared statement hazırla
        $base_stmt = $this->pdo->prepare($query);
        if ($base_stmt === false) {
            return;
        }

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

                // Cache kontrolü ile birlikte sonuçları al
                $cache_key = $this->generate_query_cache_key($chunk_query, $params);
                $results = null;

                if ($this->query_cache_enabled) {
                    $results = $this->get_from_query_cache($cache_key);
                }

                if ($results === null) {
                    $stmt = $this->execute_query($chunk_query, $params);
                    if ($stmt === false) {
                        return;
                    }

                    $results = $stmt->fetchAll(PDO::FETCH_OBJ);

                    // Küçük chunk'ları cache'le
                    if ($this->query_cache_enabled && count($results) <= $this->query_cache_size_limit) {
                        $tables = $this->extract_tables_from_query($chunk_query);
                        $this->add_to_query_cache($cache_key, $results, [], $tables);
                    }
                }

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
                if ($offset >= config::max_result_set_size) {
                    throw new \RuntimeException(
                        sprintf(
                            'Maksimum sonuç kümesi boyutu aşıldı! (Limit: %d)',
                            config::max_result_set_size
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
            // Kaynakları temizle
            $base_stmt = null;
            gc_collect_cycles();
        }
    }

    /**
     * Memory istatistiklerini döndürür
     */
    public function get_memory_stats(): array
    {
        $limit = $this->get_memory_limit();
        
        return array_merge(self::$memory_stats, [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'limit' => $limit,
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
     * Transaction metodları için alias'lar
     */
    public function begin_transaction(): void
    {
        $this->begin();
    }

    public function commit_transaction(): bool
    {
        return $this->commit();
    }

    public function rollback_transaction(): bool
    {
        return $this->rollback();
    }

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

            // Cache'e ekle
            $this->add_to_query_cache($cache_key, $results, $tags, $tables);
            
            return true;
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
