<?php

namespace nsql\database;

use Exception;
use Generator;
use InvalidArgumentException;
use nsql\database\security\session_manager;
use nsql\database\traits\{
    cache_trait,
    connection_trait,
    debug_trait,
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

    // Debug özellikleri
    protected ?string $last_error = null;
    protected string $last_query = '';
    protected array $last_params = [];
    protected string $last_called_method = 'unknown';
    protected bool $debug_mode = false;
    protected string $log_file = 'error_log.txt';

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
        ?bool $debug = null
    ) {
        // Config sınıfından değerleri al
        $host = $host ?? config::get('db_host', 'localhost');
        $db = $db ?? config::get('db_name', 'etiyop');
        $user = $user ?? config::get('db_user', 'root');
        $pass = $pass ?? config::get('db_pass', '');
        $charset = $charset ?? config::get('db_charset', 'utf8mb4');

        // PDO bağlantı seçeneklerini ayarla
        $this->options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => config::get('connection_timeout', 5),
            \PDO::ATTR_PERSISTENT => config::get('persistent_connection', false),
        ];

        // DSN ve diğer özellikleri ayarla
        $this->dsn = "mysql:host=" . (string)$host . ";dbname=" . (string)$db . ";charset=" . (string)$charset;
        $this->user = (string)$user;
        $this->pass = (string)$pass;
        $this->debug_mode = (bool)($debug ?? config::get('debug_mode', false));
        $this->log_file = (string)config::get('log_file', 'error_log.txt');
        $this->statement_cache_limit = (int)config::get('statement_cache_limit', 100);

        // Parent PDO constructor'ı çağır
        parent::__construct($this->dsn, $this->user, $this->pass, $this->options);

        self::initialize_static_vars();
        $this->initialize_pool();
        $this->initialize_connection();
        $this->load_cache_config();
    }    public static function connect(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null): static
    {
        // dsn'den host, db ve charset bilgilerini ayıkla
        $pattern = '/mysql:host=([^;]+);dbname=([^;]+);charset=([^;]+)/';
        if (preg_match($pattern, $dsn, $matches)) {
            $host = $matches[1];
            $db = $matches[2];
            $charset = $matches[3];

            return new static($host, $db, $username, $password, $charset);
        }

        throw new InvalidArgumentException('Geçersiz DSN formatı. Beklenen format: mysql:host=HOST;dbname=DB;charset=CHARSET');
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

    private function log_error(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message" . PHP_EOL;

        $file = $this->resolve_log_path($this->log_file);
        $this->ensure_log_directory(dirname($file));
        $this->rotate_if_needed($file);

        file_put_contents($file, $log_message, FILE_APPEND | LOCK_EX);
    }

    private function ensure_log_directory(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function resolve_log_path(string $path): string
    {
        if (preg_match('/^[A-Za-z]:\\\\|^\//', $path)) {
            return $path;
        }
        $root = dirname(__DIR__, 1);
        $dir = config::get('log_dir', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs');

        return rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private function rotate_if_needed(string $file): void
    {
        $max = (int)config::get('log_max_size', 1048576);
        if (is_file($file) && filesize($file) > $max) {
            $rotated = $file . '.' . date('Ymd_His');
            @rename($file, $rotated);
        }
    }

    /**
     * Üretim ortamında ayrıntılı hata mesajlarını gizler, sadece genel mesaj döndürür ve hatayı loglar.
     * Geliştirme ortamında ise gerçek hatayı döndürür.
     *
     * @param Exception|Throwable $e
     * @param string $generic_message Kullanıcıya gösterilecek genel mesaj (örn: "Bir hata oluştu.")
     * @return string Kullanıcıya gösterilecek mesaj
     */
    public function handle_exception($e, string $generic_message = 'Bir hata oluştu.'): string
    {
        $this->log_error($e->getMessage() . (method_exists($e, 'getTraceAsString') ? "\n" . $e->getTraceAsString() : ''));
        if ($this->debug_mode) {
            return $e->getMessage();
        } else {
            return $generic_message;
        }
    }

    /**
     * Uygulama genelinde güvenli try-catch örüntüsü için yardımcı fonksiyon.
     * Kapatıcı (callable) fonksiyonu güvenli şekilde çalıştırır, hata olursa handleException ile işler.
     *
     * @param callable $fn
     * @param string $generic_message
     * @return mixed
     */
    public function safe_execute(callable $fn, string $generic_message = 'Bir hata oluştu.')
    {
        try {
            return $fn();
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            $this->log_error("PDO Error: " . $e->getMessage());
            
            if ($this->debug_mode) {
                throw new \RuntimeException($generic_message . ': ' . $e->getMessage(), 0, $e);
            }
            
            return false;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->log_error("General Error: " . $e->getMessage());
            
            if ($this->debug_mode) {
                throw $e;
            }
            
            return false;
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            $this->log_error("Fatal Error: " . $e->getMessage());
            
            if ($this->debug_mode) {
                throw $e;
            }
            
            return false;
        }
    }

    /**
     * Veritabanı bağlantısının canlı olup olmadığını kontrol eder, kopmuşsa yeniden bağlanır.
     * @return void
     */
    public function ensure_connection(): void
    {
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

    private function execute_query(string $sql, array $params = [], ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $this->set_last_called_method();
        $this->ensure_connection();

        // Sorgu türünü belirle ve kaydet
        $this->last_query = $sql;
        $this->last_params = $params;
        $this->last_error = null;

        $this->validate_param_types($params);
        $cache_key = $this->get_statement_cache_key($sql, $params);

        $attempts = 0;
        do {
            try {
                if (! isset($this->statement_cache[$cache_key])) {
                    $stmt = $this->pdo->prepare($sql);
                    $this->add_to_statement_cache($cache_key, $stmt);
                } else {
                    $stmt = $this->statement_cache[$cache_key];
                }

                $this->statement_cache_usage[$cache_key] = microtime(true);

                // Parametreleri bağla
                foreach ($params as $key => $param) {
                    $param_name = is_int($key) ? ($key + 1) : (strpos($key, ':') === 0 ? $key : ":{$key}");

                    if (is_array($param) && isset($param['value'], $param['type'])) {
                        // Query Builder'dan gelen yapılandırılmış parametre
                        $stmt->bindValue($param_name, $param['value'], $param['type']);
                    } else {
                        // Doğrudan değer olarak gelen parametre
                        $param_type = $this->determine_param_type($param);
                        $stmt->bindValue($param_name, $param, $param_type);
                    }
                }

                if ($fetch_mode !== null) {
                    $stmt->setFetchMode($fetch_mode, ...$fetch_mode_args);
                }

                $stmt->execute();

                // Sorgu sonuçlarını last_results'a kaydet
                if ($fetch_mode === null) {
                    $this->last_results = $stmt->fetchAll(PDO::FETCH_OBJ);
                }

                return $stmt;

            } catch (PDOException $e) {
                $attempts++;

                $error_message = $e->getMessage();
                $this->last_error = $error_message;
                $this->log_error($error_message);
                $this->last_results = [];

                $error_code = $e->errorInfo[1] ?? null;
                if (in_array($error_code, [2006, 2013]) && $attempts <= $this->retry_limit) {
                    $this->initialize_connection();

                    continue;
                }

                return false;
            }
        } while ($attempts <= $this->retry_limit);

        return false;
    }

    public function query(string $query, ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
    {
        $this->set_last_called_method();

        return $this->execute_query($query, [], $fetch_mode, ...$fetch_mode_args);
    }

    public function insert(string $sql, array $params = []): bool
    {
        $this->set_last_called_method();
        $this->last_results = [];
        $this->last_insert_id = 0;

        $stmt = $this->execute_query($sql, $params);
        if ($stmt !== false) {
            $this->last_insert_id = (int)$this->pdo->lastInsertId();

            return true;
        }

        return false;
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
            return null;
        }

        // Sonucu al ve cache'le
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        if ($result && $this->query_cache_enabled) {
            $this->add_to_query_cache($cache_key, $result);
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

        // Sonuçları al ve cache'le
        $results = $stmt->fetchAll(PDO::FETCH_OBJ);
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

        // İlk sorgu için prepared statement oluştur
        $base_stmt = $this->pdo->prepare($query);
        if ($base_stmt === false) {
            return;
        }

        while (true) {
            $this->check_memory_status();
            $this->adjust_chunk_size();

            // Chunk sorgusu oluştur ve çalıştır
            $chunk_query = $query . " LIMIT " . $chunk_size . " OFFSET " . $offset;
            $stmt = $this->execute_query($chunk_query, $params);

            if ($stmt === false) {
                return;
            }

            // Satırları yield et
            $found_rows = false;
            while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
                $found_rows = true;
                $total_rows++;

                // Memory optimizasyonu
                if ($total_rows % 1000 === 0) {
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

            // Statement'ı temizle
            $stmt = null;

            // GC çağır
            if ($offset % (config::default_chunk_size * 10) === 0) {
                gc_collect_cycles();
            }
        }
    }

    public function update(string $sql, array $params = []): bool
    {
        $this->set_last_called_method();
        $this->last_results = [];

        return $this->execute_query($sql, $params) !== false;
    }

    public function delete(string $sql, array $params = []): bool
    {
        $this->set_last_called_method();
        $this->last_results = [];

        return $this->execute_query($sql, $params) !== false;
    }

    /**
     * Son eklenen kaydın ID değerini döndürür.
     *
     * @return int Son eklenen kaydın ID değeri.
     */
    public function insert_id(): int
    {
        return $this->last_insert_id;
    }

    /**
     * Bir veritabanı işlemi başlatır.
     *
     * @return void
     */
    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Bir veritabanı işlemini tamamlar ve değişiklikleri kaydeder.
     *
     * @return void
     */    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Bir veritabanı işlemini geri alır.
     *
     * @return bool İşlem başarılıysa true, değilse false döndürür
     */
    public function rollback(): bool
    {
        return $this->pdo->rollBack();
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
     * @return \Generator Her chunk için bir array döndürür
     */
    public function get_chunk(string $query, array $params = []): \Generator
    {
        $this->set_last_called_method();

        // LIMIT ve OFFSET kontrolü
        if (preg_match('/\b(LIMIT|OFFSET)\b/i', $query)) {
            throw new \InvalidArgumentException('get_chunk() metodu LIMIT veya OFFSET içeren sorgularla kullanılamaz.');
        }

        $offset = 0;
        self::$current_chunk_size = config::default_chunk_size;
        $total_rows = 0;

        // Prepared statement hazırla
        $base_stmt = $this->pdo->prepare($query);
        if ($base_stmt === false) {
            return;
        }

        try {
            while (true) {
                // Memory ve chunk boyutu kontrolü
                $this->check_memory_status();
                $this->adjust_chunk_size();

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
                        $this->add_to_query_cache($cache_key, $results);
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
        return array_merge(self::$memory_stats, [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'current_chunk_size' => self::$current_chunk_size ?? config::default_chunk_size,
        ]);
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
     * Debug bilgilerini loglar
     */
    public function log_debug_info(string $message, mixed $data = null): void
    {
        if ($this->debug_mode) {
            $log_message = "[DEBUG] {$message}";
            if ($data !== null) {
                $log_message .= ": " . print_r($data, true);
            }
            error_log($log_message);
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
}
