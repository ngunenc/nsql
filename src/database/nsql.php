<?php

namespace nsql\database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use InvalidArgumentException;
use Exception;
use Throwable;
use Generator;
use nsql\database\traits\{
    query_parameter_trait,
    cache_trait,
    debug_trait,
    statement_cache_trait,
    connection_trait,
    transaction_trait
};

class nsql extends PDO {
    use query_parameter_trait;
    use cache_trait;
    use debug_trait;
    use statement_cache_trait;
    use connection_trait;
    use transaction_trait;

    // Debug özellikleri
    protected ?string $last_error = null;
    protected string $last_query = '';
    protected array $last_params = [];
    protected string $last_called_method = 'unknown';
    protected bool $debug_mode = false;
    protected string $log_file = 'error_log.txt';

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
    private static int $current_chunk_size;
    private static array $memory_stats = [
        'peak_usage' => 0,
        'warning_count' => 0,
        'critical_count' => 0
    ];

    /**
     * Query Builder oluşturur
     * 
     * @param string|null $table Tablo adı (opsiyonel)
     * @return query_builder
     */
    public function table(?string $table = null): query_builder {
        $builder = new query_builder($this);
        return $table ? $builder->table($table) : $builder;
    }

    private function initialize_connection(): void {
        try {
            $this->pdo = connection_pool::get_connection();
        } catch (PDOException $e) {
            throw new RuntimeException("Veritabanı bağlantı hatası: " . $e->getMessage());
        }
    }

    private function initialize_pool(): void {
        if (!self::$pool_initialized) {
            self::$pool_config = [
                'dsn' => $this->dsn,
                'username' => $this->user,
                'password' => $this->pass,
                'options' => $this->options
            ];

            connection_pool::initialize(
                self::$pool_config,
                Config::get('DB_MIN_CONNECTIONS', 2),
                Config::get('DB_MAX_CONNECTIONS', 10)
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
        $host = $host ?? Config::get('DB_HOST', 'localhost');
        $db = $db ?? Config::get('DB_NAME', 'etiyop');
        $user = $user ?? Config::get('DB_USER', 'root');
        $pass = $pass ?? Config::get('DB_PASS', '');
        $charset = $charset ?? Config::get('DB_CHARSET', 'utf8mb4');
        
        $this->dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $this->user = $user;
        $this->pass = $pass;
        $this->debug_mode = $debug ?? Config::get('DEBUG_MODE', false);
        $this->log_file = Config::get('LOG_FILE', 'error_log.txt');
        $this->statement_cache_limit = Config::get('STATEMENT_CACHE_LIMIT', 100);
        
        $this->options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $this->initialize_pool();
        $this->initialize_connection();
        $this->load_cache_config();
    }    public static function connect(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null): static {
        return new static($dsn, $username, $password, $options);
    }

    private function disconnect(): void {
        if ($this->pdo !== null) {
            connection_pool::release_connection($this->pdo);
            $this->pdo = null;
        }
    }
    
    public function __destruct() {
        $this->disconnect();
    }
    
    // Connection Pool istatistiklerini almak için yeni metod
    public static function get_pool_stats(): array {
        return connection_pool::get_stats();
    }

    private function log_error(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->log_file, $log_message, FILE_APPEND);
    }

    /**
     * Üretim ortamında ayrıntılı hata mesajlarını gizler, sadece genel mesaj döndürür ve hatayı loglar.
     * Geliştirme ortamında ise gerçek hatayı döndürür.
     *
     * @param Exception|Throwable $e
     * @param string $genericMessage Kullanıcıya gösterilecek genel mesaj (örn: "Bir hata oluştu.")
     * @return string Kullanıcıya gösterilecek mesaj
     */
    public function handle_exception($e, string $genericMessage = 'Bir hata oluştu.'): string {
        $this->log_error($e->getMessage() . (method_exists($e, 'getTraceAsString') ? "\n" . $e->getTraceAsString() : ''));
        if ($this->debug_mode) {
            return $e->getMessage();
        } else {
            return $genericMessage;
        }
    }

    /**
     * Uygulama genelinde güvenli try-catch örüntüsü için yardımcı fonksiyon.
     * Kapatıcı (callable) fonksiyonu güvenli şekilde çalıştırır, hata olursa handleException ile işler.
     *
     * @param callable $fn
     * @param string $genericMessage
     * @return mixed
     */
    public function safe_execute(callable $fn, string $genericMessage = 'Bir hata oluştu.') {
        try {
            return $fn();
        } catch (Exception $e) {
            echo $this->handle_exception($e, $genericMessage);
            return null;
        } catch (Throwable $e) {
            echo $this->handle_exception($e, $genericMessage);
            return null;
        }
    }

    /**
     * Veritabanı bağlantısının canlı olup olmadığını kontrol eder, kopmuşsa yeniden bağlanır.
     * @return void
     */
    public function ensure_connection(): void {
        try {
            $stmt = $this->pdo->query('SELECT 1');
            if ($stmt === false) {
                $this->connect();
            }
        } catch (PDOException $e) {
            $this->connect();
        }
    }

    /**
     * Güvenli oturum başlatma ve cookie ayarları
     */
    public static function secure_session_start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
            // Session fixation önlemi: yeni oturumda ID yenile
            if (!isset($_SESSION['initiated'])) {
                session_regenerate_id(true);
                $_SESSION['initiated'] = true;
            }
        }
    }

    /**
     * Oturum ID'sini güvenli şekilde yenile (isteğe bağlı olarak kullanılabilir)
     */
    public static function regenerate_session_id(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * XSS koruması için HTML çıktısı kaçışlama fonksiyonu
     */
    public static function escape_html($string): string {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Benzersiz CSRF token üretir ve oturuma kaydeder
     */
    public static function generate_csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * CSRF token doğrulaması yapar
     */
    public static function validate_csrf_token($token): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    private function execute_query(string $sql, array $params = [], ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->set_last_called_method();
        $this->ensure_connection();
        $this->last_query = $sql;
        $this->last_params = $params;
        $this->last_error = null; // Artık nullable string olduğu için sorun olmayacak

        $this->validate_param_types($params);
        $cache_key = $this->get_statement_cache_key($sql, $params);

        $attempts = 0;
        do {
            try {
                if (!isset($this->statement_cache[$cache_key])) {
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

                if ($fetchMode !== null) {
                    $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
                }

                $stmt->execute();
                
                // Sorgu sonuçlarını last_results'a kaydet
                // Sorgu sonuçlarını last_results'a kaydet (null fetch mode için)
                if ($fetchMode === null) {
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

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->set_last_called_method();
        return $this->execute_query($query, [], $fetchMode, ...$fetchModeArgs);
    }
    
    public function insert(string $sql, array $params = []): bool {
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

    public function get_row(string $query, array $params = []): ?object {
        $this->set_last_called_method();
        $cache_key = $this->generate_query_cache_key($query, $params);
        
        $cached = $this->get_from_query_cache($cache_key);
        if ($cached !== null) {
            return $cached;
        }
        
        $stmt = $this->execute_query($query, $params);
        if ($stmt === false) {
            return null;
        }
        
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        if ($result) {
            $this->add_to_query_cache($cache_key, $result);
        }
        
        return $result ?: null;
    }
    
    public function get_results(string $query, array $params = []): array {
        $this->set_last_called_method();
        $cache_key = $this->generate_query_cache_key($query, $params);
        
        $cached = $this->get_from_query_cache($cache_key);
        if ($cached !== null) {
            return $cached;
        }
        
        $stmt = $this->execute_query($query, $params);
        if ($stmt === false) {
            return [];
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_OBJ);
        $this->add_to_query_cache($cache_key, $results);
        
        return $results;
    }
    
    /**
     * Büyük veri setlerini satır satır döndürür (Generator)
     * 
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @return \Generator
     */
    public function get_yield(string $query, array $params = []): \Generator {
        $this->set_last_called_method();
        $offset = 0;
        $chunk_size = config::DEFAULT_CHUNK_SIZE;

        while (true) {
            $this->check_memory_status();
            
            $chunk_query = $query . " LIMIT " . $chunk_size . " OFFSET " . $offset;
            $stmt = $this->execute_query($chunk_query, $params);
            
            if ($stmt === false) {
                return;
            }

            $found_rows = false;
            while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
                $found_rows = true;
                yield $row;
            }

            if (!$found_rows) {
                break;
            }

            $offset += $chunk_size;
            
            if ($offset >= config::MAX_RESULT_SET_SIZE) {
                throw new \RuntimeException('Maksimum sonuç kümesi boyutu aşıldı!');
            }
        }
    }

    public function update(string $sql, array $params = []): bool {
        $this->set_last_called_method();
        $this->last_results = [];
        return $this->execute_query($sql, $params) !== false;
    }

    public function delete(string $sql, array $params = []): bool {
        $this->set_last_called_method();
        $this->last_results = [];
        return $this->execute_query($sql, $params) !== false;
    }

    /**
     * Son eklenen kaydın ID değerini döndürür.
     *
     * @return int Son eklenen kaydın ID değeri.
     */
    public function insert_id(): int {
        return $this->last_insert_id;
    }

    /**
     * Bir veritabanı işlemi başlatır.
     *
     * @return void
     */
    public function begin(): void {
        $this->pdo->beginTransaction();
    }

    /**
     * Bir veritabanı işlemini tamamlar ve değişiklikleri kaydeder.
     *
     * @return void
     */    public function commit(): bool {
        return $this->pdo->commit();
    }

    /**
     * Bir veritabanı işlemini geri alır.
     *
     * @return bool İşlem başarılıysa true, değilse false döndürür
     */
    public function rollback(): bool {
        return $this->pdo->rollBack();
    }

    /**
     * Son çalıştırılan sorgunun detaylarını ve hata ayıklama bilgilerini gösterir.
     *
     * @return void
     */
    private function set_last_called_method(): void {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $this->last_called_method = $trace[1]['function'] ?? 'unknown';
    }

    /**
     * Memory durumunu kontrol eder
     */
    private function check_memory_status(): void {
        $now = time();
        
        if (self::$last_memory_check !== null && 
            ($now - self::$last_memory_check) < config::MEMORY_CHECK_INTERVAL) {
            return;
        }
        
        self::$last_memory_check = $now;
        $current_usage = memory_get_usage(true);
        self::$memory_stats['peak_usage'] = memory_get_peak_usage(true);
        
        if ($current_usage > config::MEMORY_LIMIT_CRITICAL) {
            self::$memory_stats['critical_count']++;
            $this->cleanup_resources();
            throw new \RuntimeException('Kritik bellek kullanımı aşıldı!');
        }
        
        if ($current_usage > config::MEMORY_LIMIT_WARNING) {
            self::$memory_stats['warning_count']++;
            $this->cleanup_resources();
        }
    }

    /**
     * Kaynakları temizler
     */
    private function cleanup_resources(): void {
        $this->clear_statement_cache();
        $this->clear_query_cache();
        gc_collect_cycles();
    }

    /**
     * Chunk boyutunu bellek kullanımına göre ayarlar
     */
    private function adjust_chunk_size(): void {
        if (!config::AUTO_ADJUST_CHUNK_SIZE) {
            self::$current_chunk_size = config::DEFAULT_CHUNK_SIZE;
            return;
        }

        $memory_usage = memory_get_usage(true);
        $memory_limit = config::MEMORY_LIMIT_WARNING;
        
        $usage_ratio = $memory_usage / $memory_limit;
        
        if ($usage_ratio > 0.8) {
            self::$current_chunk_size = max(config::MIN_CHUNK_SIZE, self::$current_chunk_size / 2);
        } elseif ($usage_ratio < 0.5) {
            self::$current_chunk_size = min(config::MAX_CHUNK_SIZE, self::$current_chunk_size * 1.5);
        }
    }

    /**
     * Büyük veri setlerini chunk'lar halinde döndürür
     * 
     * @param string $query SQL sorgusu
     * @param array $params Sorgu parametreleri
     * @return \Generator Her chunk için bir array döndürür
     */
    public function get_chunk(string $query, array $params = []): \Generator {
        $offset = 0;
        self::$current_chunk_size = config::DEFAULT_CHUNK_SIZE;

        while (true) {
            $this->check_memory_status();
            $this->adjust_chunk_size();

            $chunk_query = $query . " LIMIT " . self::$current_chunk_size . " OFFSET " . $offset;
            $results = $this->get_results($chunk_query, $params);
            
            if (empty($results)) {
                break;
            }

            yield $results;
            $offset += self::$current_chunk_size;

            if ($offset >= config::MAX_RESULT_SET_SIZE) {
                throw new \RuntimeException('Maksimum sonuç kümesi boyutu aşıldı!');
            }
        }
    }

    /**
     * Memory istatistiklerini döndürür
     */
    public function get_memory_stats(): array {
        return array_merge(self::$memory_stats, [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'current_chunk_size' => self::$current_chunk_size ?? config::DEFAULT_CHUNK_SIZE
        ]);
    }
}
