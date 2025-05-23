<?php

namespace Nsql\Database;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ConnectionPool.php';

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use InvalidArgumentException;
use Exception;
use Throwable;
use Generator;

class nsql extends PDO {
    private ?PDO $pdo = null;
    private string $lastQuery = '';
    private array $lastParams = [];
    private array $lastResults = [];
    private ?string $lastError = null;
    private array $statementCache = [];
    private int $lastInsertId = 0;
    private string $dsn;
    private string $user;
    private string $pass;
    private array $options;
    private int $retryLimit = 2;
    private bool $debugMode = false;
    private string $logFile;
    private int $statementCacheLimit;
    private array $statementCacheUsage = []; // LRU için kullanım sırası
    private static bool $poolInitialized = false;
    private static array $poolConfig = [];
    private string $lastCalledMethod = '';
    
    /**
     * Sorgu önbelleği
     */
    private $queryCache = [];
    private $queryCacheEnabled;
    private $queryCacheTimeout;
    private $queryCacheSizeLimit;

    /**
     * Query Builder oluşturur
     * 
     * @param string|null $table Tablo adı (opsiyonel)
     * @return QueryBuilder
     */
    public function table(?string $table = null): QueryBuilder {
        $builder = new QueryBuilder($this);
        return $table ? $builder->table($table) : $builder;
    }

    private function initializeConnection(): void {
        try {
            $this->pdo = ConnectionPool::getConnection();
        } catch (PDOException $e) {
            throw new RuntimeException("Veritabanı bağlantı hatası: " . $e->getMessage());
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
        $this->debugMode = $debug ?? Config::get('DEBUG_MODE', false);
        $this->logFile = Config::get('LOG_FILE', 'error_log.txt');
        $this->statementCacheLimit = Config::get('STATEMENT_CACHE_LIMIT', 100);
        
        $this->options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Connection Pool yapılandırması
        if (!self::$poolInitialized) {
            self::$poolConfig = [
                'dsn' => $this->dsn,
                'username' => $this->user,
                'password' => $this->pass,
                'options' => $this->options
            ];
            
            ConnectionPool::initialize(
                self::$poolConfig,
                Config::get('DB_MIN_CONNECTIONS', 2),
                Config::get('DB_MAX_CONNECTIONS', 10)
            );
            
            self::$poolInitialized = true;
        }

        $this->initializeConnection();
        $this->loadCacheConfig();
    }    public static function connect(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null): static {
        if (!self::$poolInitialized) {
            self::$poolConfig = [
                'dsn' => $dsn,
                'username' => $username,
                'password' => $password,
                'options' => $options ?? []
            ];
            
            ConnectionPool::initialize(
                self::$poolConfig,
                Config::get('DB_MIN_CONNECTIONS', 2),
                Config::get('DB_MAX_CONNECTIONS', 10)
            );
            
            self::$poolInitialized = true;
        }
        
        return new static(parse_url($dsn, PHP_URL_HOST), parse_url($dsn, PHP_URL_PATH), $username, $password);
    }

    private function disconnect(): void {
        if ($this->pdo !== null) {
            ConnectionPool::releaseConnection($this->pdo);
            $this->pdo = null;
        }
    }
    
    public function __destruct() {
        $this->disconnect();
    }
    
    // Connection Pool istatistiklerini almak için yeni metod
    public static function getPoolStats(): array {
        return ConnectionPool::getStats();
    }

    private function logError(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    /**
     * Üretim ortamında ayrıntılı hata mesajlarını gizler, sadece genel mesaj döndürür ve hatayı loglar.
     * Geliştirme ortamında ise gerçek hatayı döndürür.
     *
     * @param Exception|Throwable $e
     * @param string $genericMessage Kullanıcıya gösterilecek genel mesaj (örn: "Bir hata oluştu.")
     * @return string Kullanıcıya gösterilecek mesaj
     */
    public function handleException($e, string $genericMessage = 'Bir hata oluştu.'): string {
        $this->logError($e->getMessage() . (method_exists($e, 'getTraceAsString') ? "\n" . $e->getTraceAsString() : ''));
        if ($this->debugMode) {
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
    public function safeExecute(callable $fn, string $genericMessage = 'Bir hata oluştu.') {
        try {
            return $fn();
        } catch (Exception $e) {
            echo $this->handleException($e, $genericMessage);
            return null;
        } catch (Throwable $e) {
            echo $this->handleException($e, $genericMessage);
            return null;
        }
    }

    /**
     * Güvenli statement cache anahtarı oluşturucu
     */
    private function getStatementCacheKey(string $sql, array $params): string {
        return md5($sql . '|' . serialize(array_keys($params)) . '|' . serialize(array_map('gettype', $params)));
    }

    /**
     * Statement cache'e yeni bir anahtar ekler ve LRU algoritması ile sınırı korur.
     */
    private function addToStatementCache(string $key, $stmt): void {
        $this->statementCache[$key] = $stmt;
        $this->statementCacheUsage[$key] = microtime(true);
        if (count($this->statementCache) > $this->statementCacheLimit) {
            // En eski kullanılanı bul ve sil
            asort($this->statementCacheUsage);
            $oldestKey = array_key_first($this->statementCacheUsage);
            unset($this->statementCache[$oldestKey], $this->statementCacheUsage[$oldestKey]);
        }
    }

    /**
     * Parametre tiplerini kontrol eder (sadece int, float, string, null kabul edilir)
     */
    private function validateParamTypes(array $params): void {
        foreach ($params as $value) {
            if (!is_int($value) && !is_float($value) && !is_string($value) && !is_null($value)) {
                throw new InvalidArgumentException('Geçersiz parametre tipi: ' . gettype($value));
            }
        }
    }

    /**
     * Veritabanı bağlantısının canlı olup olmadığını kontrol eder, kopmuşsa yeniden bağlanır.
     * @return void
     */
    public function ensureConnection(): void {
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
    public static function secureSessionStart(): void {
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
    public static function regenerateSessionId(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * XSS koruması için HTML çıktısı kaçışlama fonksiyonu
     */
    public static function escapeHtml($string): string {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Benzersiz CSRF token üretir ve oturuma kaydeder
     */
    public static function generateCsrfToken(): string {
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
    public static function validateCsrfToken($token): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    private function executeQuery(string $sql, array $params = [], ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->setLastCalledMethod();
        $this->ensureConnection();
        $this->lastQuery = $sql;
        $this->lastParams = $params;
        $this->lastError = null;

        $this->validateParamTypes($params);
        $cacheKey = $this->getStatementCacheKey($sql, $params);

        $attempts = 0;
        do {
            try {
                if (!isset($this->statementCache[$cacheKey])) {
                    $stmt = $this->pdo->prepare($sql);
                    $this->addToStatementCache($cacheKey, $stmt);
                } else {
                    $stmt = $this->statementCache[$cacheKey];
                }
                
                $this->statementCacheUsage[$cacheKey] = microtime(true);

                foreach ($params as $key => $value) {
                    $paramType = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->bindValue(is_int($key) ? $key + 1 : ":$key", $value, $paramType);
                }

                if ($fetchMode !== null) {
                    $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
                }

                $stmt->execute();
                
                // Sorgu sonuçlarını lastResults'a kaydet
                if ($fetchMode === null) {
                    $this->lastResults = $stmt->fetchAll(PDO::FETCH_OBJ);
                    $stmt->closeCursor(); // Yeni sorgu için hazırla
                    $stmt = $this->pdo->prepare($sql); // Statement'ı yenile
                    $this->addToStatementCache($cacheKey, $stmt);
                    
                    // Parametreleri tekrar bağla
                    foreach ($params as $key => $value) {
                        $paramType = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
                        $stmt->bindValue(is_int($key) ? $key + 1 : ":$key", $value, $paramType);
                    }
                    
                    $stmt->execute(); // Yeniden çalıştır
                }
                
                return $stmt;

            } catch (PDOException $e) {
                $attempts++;

                $this->lastError = $e->getMessage();
                $this->logError($this->lastError);
                $this->lastResults = [];

                $errorCode = $e->errorInfo[1] ?? null;
                if (in_array($errorCode, [2006, 2013]) && $attempts <= $this->retryLimit) {
                    $this->initializeConnection();
                    continue;
                }

                return false;
            }
        } while ($attempts <= $this->retryLimit);

        return false;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->setLastCalledMethod();
        return $this->executeQuery($query, [], $fetchMode, ...$fetchModeArgs);
    }
    
    public function insert(string $sql, array $params = []): bool {
        $this->setLastCalledMethod();
        $this->lastResults = [];
        $this->lastInsertId = 0;

        $stmt = $this->executeQuery($sql, $params);
        if ($stmt !== false) {
            $this->lastInsertId = (int)$this->pdo->lastInsertId();
            return true;
        }
        return false;
    }

    public function get_row(string $query, array $params = []): ?object {
        $this->setLastCalledMethod();
        $cacheKey = $this->generateQueryCacheKey($query, $params);
        
        $cached = $this->getFromQueryCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        $stmt = $this->executeQuery($query, $params);
        if ($stmt === false) {
            return null;
        }
        
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        if ($result) {
            $this->addToQueryCache($cacheKey, $result);
        }
        
        return $result ?: null;
    }
    
    public function get_results(string $query, array $params = []): array {
        $this->setLastCalledMethod();
        $cacheKey = $this->generateQueryCacheKey($query, $params);
        
        $cached = $this->getFromQueryCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        $stmt = $this->executeQuery($query, $params);
        if ($stmt === false) {
            return [];
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_OBJ);
        $this->addToQueryCache($cacheKey, $results);
        
        return $results;
    }
    
    public function get_yield(string $query, array $params = []): Generator {
        $this->setLastCalledMethod();
        $stmt = $this->executeQuery($query, $params);
        if ($stmt === false) {
            return;
        }
        
        while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
            yield $row;
        }
    }

    public function update(string $sql, array $params = []): bool {
        $this->setLastCalledMethod();
        $this->lastResults = [];
        return $this->executeQuery($sql, $params) !== false;
    }

    public function delete(string $sql, array $params = []): bool {
        $this->setLastCalledMethod();
        $this->lastResults = [];
        return $this->executeQuery($sql, $params) !== false;
    }

    /**
     * Son eklenen kaydın ID değerini döndürür.
     *
     * @return int Son eklenen kaydın ID değeri.
     */
    public function insert_id(): int {
        return $this->lastInsertId;
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
    private function setLastCalledMethod(): void {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $this->lastCalledMethod = $trace[1]['function'] ?? 'unknown';
    }

    public function debug(): void {
        if (!$this->debugMode) {
            echo '<div style="color:red;font-weight:bold;">Debug modu kapalı! Detaylı sorgu ve hata bilgisi için nsql nesnesini debug modda başlatın.</div>';
            return;
        }

        try {
            $query = $this->interpolateQuery($this->lastQuery, $this->lastParams);
            $paramsJson = json_encode($this->lastParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $query = $this->lastQuery . ' [Parametre dönüştürme hatası]';
            $paramsJson = 'Parametreler görüntülenemedi: ' . $e->getMessage();
        }

        $debugMessage = sprintf(
            "Çalıştırılan Metod: %s\nSQL Sorgusu: %s\nParametreler: %s\n%s",
            $this->lastCalledMethod,
            $query,
            $paramsJson,
            $this->lastError ? "Hata: {$this->lastError}\n" : ''
        );

        $this->logError($debugMessage);        echo <<<HTML
        <style>
            .nsql-debug {
                font-family: monospace;
                background: #f9f9f9;
                border: 1px solid #ccc;
                padding: 16px;
                margin: 16px 0;
                border-radius: 8px;
                max-width: 100%;
                overflow-x: auto;
            }
            .method-header {
                background: #4a90e2;
                color: white;
                padding: 12px 16px;
                border-radius: 6px;
                margin-bottom: 16px;
                font-size: 18px;
                font-weight: bold;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .nsql-debug h4 {
                margin: 0 0 8px;
                font-size: 16px;
                color: #333;
            }
            .nsql-debug pre {
                background: #fff;
                border: 1px solid #ddd;
                padding: 10px;
                margin: 8px 0;
                border-radius: 5px;
                overflow-x: auto;
                white-space: pre-wrap;
                word-wrap: break-word;
            }
            .nsql-debug .method-info {
                background: #e8f5e9;
                border: 1px solid #c8e6c9;
                color: #2e7d32;
                padding: 10px;
                margin: 8px 0;
                border-radius: 5px;
                font-weight: bold;
            }
            .nsql-debug table {
                border-collapse: collapse;
                width: 100%;
                margin: 8px 0;
                background: #fff;
            }
            .nsql-debug table th,
            .nsql-debug table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
                font-size: 13px;
                word-break: break-word;
            }
            .nsql-debug table th {
                background: #f5f5f5;
                font-weight: bold;
            }
            .nsql-debug .error {
                background: #ffecec;
                border: 1px solid #f5aca6;
                color: #cc0033;
                padding: 10px;
                margin: 8px 0;
                border-radius: 5px;
            }
            .nsql-debug .info {
                background: #e7f6ff;
                border: 1px solid #b3e5fc;
                color: #0288d1;
                padding: 10px;
                margin: 8px 0;
                border-radius: 5px;
            }
            .nsql-debug .query-section {
                margin: 16px 0;
            }
            .nsql-debug .no-results {
                font-style: italic;
                color: #666;
            }
        </style>
        <div class="nsql-debug">
HTML;

        if ($this->lastError) {
            echo "<div class='error'>⚠️ <strong>Hata:</strong> " . htmlspecialchars($this->lastError) . "</div>";
        }

        echo "<div class='query-section'>";
        echo "<h4>🔍 SQL Sorgusu:</h4>";
        echo "<pre>" . htmlspecialchars($query) . "</pre>";
        
        echo "<h4>📋 Parametreler:</h4>";
        echo "<pre>" . htmlspecialchars($paramsJson) . "</pre>";
        echo "</div>";

        if (!empty($this->lastResults)) {
            echo "<div class='query-section'>";
            echo "<h4>📊 Sonuç Verisi:</h4>";
            
            if (is_array($this->lastResults) && count($this->lastResults) > 0) {
                $firstRow = is_object($this->lastResults[0]) ? (array)$this->lastResults[0] : $this->lastResults[0];
                
                echo "<table><thead><tr>";
                foreach ($firstRow as $key => $_) {
                    echo "<th>" . htmlspecialchars((string)$key) . "</th>";
                }
                echo "</tr></thead><tbody>";
                
                foreach ($this->lastResults as $row) {
                    echo "<tr>";
                    foreach ((array)$row as $value) {
                        $displayValue = is_null($value) ? '-' : 
                                    (is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value);
                        echo "<td>" . htmlspecialchars($displayValue) . "</td>";
                    }
                    echo "</tr>";
                }
                echo "</tbody></table>";
                
                echo "<div class='info'>✓ Toplam " . count($this->lastResults) . " kayıt bulundu.</div>";
            } else {
                echo "<div class='info'>ℹ️ Sonuç bulunamadı.</div>";
            }
            echo "</div>";
        } else {
            if ($this->lastQuery) {
                echo "<div class='info'>ℹ️ Bu sorgu herhangi bir sonuç döndürmedi veya sonuçlar henüz alınmadı.</div>";
            }
        }

        echo "</div>";
    }

    /**
     * Verilen sorguyu ve parametreleri birleştirerek hata ayıklama için kullanılabilir bir sorgu döndürür.
     *
     * @param string $query SQL sorgusu.
     * @param array $params Sorgu parametreleri.
     * @return string Birleştirilmiş sorgu.
     * @throws RuntimeException Eğer debug modu kapalıysa.
     */
    private function interpolateQuery(string $query, array $params): string {
        if (!$this->debugMode) {
            throw new RuntimeException("interpolateQuery metodu yalnızca debug modunda kullanılabilir.");
        }

        foreach ($params as $key => $value) {
            $escaped = $this->pdo->quote((string) $value);
            if (is_string($key)) {
                $query = str_replace(":$key", $escaped, $query);
            } else {
                $query = preg_replace('/\?/', $escaped, $query, 1);
            }
        }
        return $query;
    }

    /**
     * Sorgudan benzersiz önbellek anahtarı oluşturur
     */
    private function generateQueryCacheKey($query, $params = []): string {
        return md5($query . serialize($params));
    }
    
    /**
     * Sorgu sonucunu önbelleğe ekler
     */
    private function addToQueryCache(string $key, $data): void {
        if (!$this->queryCacheEnabled) {
            return;
        }
        
        // Önbellek boyut limitini kontrol et
        if (count($this->queryCache) >= $this->queryCacheSizeLimit) {
            array_shift($this->queryCache); // En eski kaydı sil
        }
        
        $this->queryCache[$key] = [
            'data' => $data,
            'time' => time()
        ];
    }
    
    /**
     * Önbellekten sorgu sonucunu getirir
     */
    private function getFromQueryCache(string $key) {
        if (!$this->queryCacheEnabled || !isset($this->queryCache[$key])) {
            return null;
        }
        
        $cached = $this->queryCache[$key];
        
        // Süre aşımını kontrol et
        if (!$this->isValidCache($cached['time'])) {
            unset($this->queryCache[$key]);
            return null;
        }
        
        return $cached['data'];
    }
    
    /**
     * Önbellek süre kontrolü
     */
    private function isValidCache(int $cacheTime): bool {
        return (time() - $cacheTime) < $this->queryCacheTimeout;
    }
    
    /**
     * Önbelleği temizler
     */
    public function clearQueryCache(): void {
        $this->queryCache = [];
    }
    
    /**
     * Yapılandırma ayarlarını yükle
     */
    private function loadCacheConfig(): void {
        $this->queryCacheEnabled = Config::QUERY_CACHE_ENABLED;
        $this->queryCacheTimeout = Config::QUERY_CACHE_TIMEOUT;
        $this->queryCacheSizeLimit = Config::QUERY_CACHE_SIZE_LIMIT;
    }
}
