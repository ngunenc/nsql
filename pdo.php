<?php

require_once 'Config.php';

class nsql {
    private PDO $pdo;
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

        $this->connect();
    }

    private function connect(): void {
        try {
            $this->pdo = new PDO($this->dsn, $this->user, $this->pass, $this->options);
        } catch (PDOException $e) {
            throw new RuntimeException("Veritabanı bağlantı hatası: " . $e->getMessage());
        }
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

    /**
     * Verilen SQL sorgusunu çalıştırır ve PDOStatement döndürür.
     *
     * @param string $sql Çalıştırılacak SQL sorgusu.
     * @param array $params Sorgu için kullanılacak parametreler.
     * @return PDOStatement|null Başarılıysa PDOStatement, aksi halde null döner.
     */
    public function query(string $sql, array $params = []): ?PDOStatement {
        $this->ensureConnection();
        $this->lastQuery = $sql;
        $this->lastParams = $params;
        $this->lastError = null;

        $this->validateParamTypes($params); // Parametre tip kontrolü
        $cacheKey = $this->getStatementCacheKey($sql, $params);

        $attempts = 0;
        do {
            try {
                // Statement cache (SQL + parametre yapısına göre anahtar)
                if (!isset($this->statementCache[$cacheKey])) {
                    $stmt = $this->pdo->prepare($sql);
                    $this->addToStatementCache($cacheKey, $stmt);
                } else {
                    $stmt = $this->statementCache[$cacheKey];
                }
                // LRU güncelle
                $this->statementCacheUsage[$cacheKey] = microtime(true);

                // Bind parameters securely
                foreach ($params as $key => $value) {
                    $paramType = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->bindValue(is_int($key) ? $key + 1 : ":$key", $value, $paramType);
                }

                $stmt->execute();
                return $stmt;

            } catch (PDOException $e) {
                $attempts++;

                $this->lastError = $e->getMessage();
                $this->logError($this->lastError); // Hata günlüğüne yaz
                $this->lastResults = [];

                $errorCode = $e->errorInfo[1] ?? null;
                if (in_array($errorCode, [2006, 2013]) && $attempts <= $this->retryLimit) {
                    $this->connect(); // yeniden bağlan
                    continue;
                }

                return null;
            }
        } while ($attempts <= $this->retryLimit);

        return null;
    }
    
    /**
     * Verilen SQL sorgusunu çalıştırarak bir kayıt ekler.
     *
     * @param string $sql Çalıştırılacak SQL sorgusu.
     * @param array $params Sorgu için kullanılacak parametreler.
     * @return bool Başarılıysa true, aksi halde false döner.
     */
    public function insert(string $sql, array $params): bool {
        $this->lastResults = [];
        $this->lastInsertId = 0;

        $stmt = $this->query($sql, $params);

        if ($stmt) {
            $this->lastInsertId = (int)$this->pdo->lastInsertId();
            return true;
        }

        return false;
    }

    private function fetch(string $sql, array $params, bool $singleRow = false): mixed {
        $stmt = $this->query($sql, $params);

        if ($stmt) {
            $results = $singleRow ? $stmt->fetch(PDO::FETCH_OBJ) : $stmt->fetchAll(PDO::FETCH_OBJ);
            $this->lastResults = $singleRow ? ($results ? [$results] : []) : $results;
            return $singleRow ? ($results ?: null) : $results;
        }

        $this->lastResults = [];
        return $singleRow ? null : [];
    }

    /**
     * Verilen SQL sorgusunu çalıştırarak tek bir satır döndürür.
     *
     * @param string $sql Çalıştırılacak SQL sorgusu.
     * @param array $params Sorgu için kullanılacak parametreler.
     * @return object|null Tek bir satır döner, eğer sonuç yoksa null döner.
     */
    public function get_row(string $sql, array $params): ?object {
        $this->lastQuery = $sql;
        $this->lastParams = $params;
        return $this->fetch($sql, $params, true);
    }

    /**
     * Verilen SQL sorgusunu çalıştırarak birden fazla satır döndürür.
     *
     * @param string $sql Çalıştırılacak SQL sorgusu.
     * @param array $params Sorgu için kullanılacak parametreler.
     * @return array Sonuç olarak dönen satırların listesi.
     */
    public function get_results(string $sql, array $params): array {
        $this->lastQuery = $sql;
        $this->lastParams = $params;
        $this->lastResults = [];
        $stmt = $this->query($sql, $params);
        if (!$stmt) {
            $this->lastResults = [];
            return [];
        }
        $results = $stmt->fetchAll(PDO::FETCH_OBJ);
        $this->lastResults = $results;
        return $results;
    }

    /**
     * Çok büyük veri setleri için generator ile satır satır veri döndürür (memory friendly).
     *
     * @param string $sql
     * @param array $params
     * @return Generator
     */
    public function get_yield(string $sql, array $params): \Generator {
        $this->lastQuery = $sql;
        $this->lastParams = $params;
        $stmt = $this->query($sql, $params);
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
                yield $row;
            }
        }
    }

    /**
     * Verilen SQL sorgusunu çalıştırarak bir güncelleme işlemi yapar.
     *
     * @param string $sql Çalıştırılacak SQL sorgusu.
     * @param array $params Sorgu için kullanılacak parametreler.
     * @return bool Başarılıysa true, aksi halde false döner.
     */
    public function update(string $sql, array $params): bool {
        $this->lastResults = [];
        return $this->query($sql, $params) !== null;
    }

    /**
     * Verilen SQL sorgusunu çalıştırarak bir silme işlemi yapar.
     *
     * @param string $sql Çalıştırılacak SQL sorgusu.
     * @param array $params Sorgu için kullanılacak parametreler.
     * @return bool Başarılıysa true, aksi halde false döner.
     */
    public function delete(string $sql, array $params): bool {
        $this->lastResults = [];
        return $this->query($sql, $params) !== null;
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
     */
    public function commit(): void {
        $this->pdo->commit();
    }

    /**
     * Bir veritabanı işlemini geri alır.
     *
     * @return void
     */
    public function rollback(): void {
        $this->pdo->rollBack();
    }

    /**
     * Son çalıştırılan sorgunun detaylarını ve hata ayıklama bilgilerini gösterir.
     *
     * @return void
     */
    public function debug(): void {
        if (!$this->debugMode) {
            echo '<div style="color:red;font-weight:bold;">Debug modu kapalı! Detaylı sorgu ve hata bilgisi için nsql nesnesini debug modda başlatın.</div>';
            return;
        }
        $query = $this->interpolateQuery($this->lastQuery, $this->lastParams);
        $paramsJson = json_encode($this->lastParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $debugMessage = "SQL Sorgusu: $query\nParametreler: $paramsJson\n";

        if ($this->lastError) {
            $debugMessage .= "Hata: {$this->lastError}\n";
        }

        $this->logError($debugMessage); // Hata ayıklama bilgilerini log dosyasına yaz

        echo <<<HTML
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
            .nsql-debug h4 {
                margin: 0 0 8px;
                font-size: 16px;
                color: #333;
            }
            .nsql-debug pre {
                background: #fff;
                border: 1px solid #ddd;
                padding: 10px;
                border-radius: 5px;
                overflow-x: auto;
            }
            .nsql-debug table {
                border-collapse: collapse;
                width: 100%;
                margin-top: 10px;
            }
            .nsql-debug table th,
            .nsql-debug table td {
                border: 1px solid #ddd;
                padding: 6px 10px;
                text-align: left;
                font-size: 13px;
            }
            .nsql-debug .error {
                background: #ffecec;
                border: 1px solid #ff5e5e;
                color: #c00;
                padding: 10px;
                margin-bottom: 14px;
                border-radius: 5px;
            }
            .nsql-debug .info {
                background: #e7f3fe;
                border: 1px solid #b3d8fd;
                color: #31708f;
                padding: 10px;
                margin-bottom: 14px;
                border-radius: 5px;
            }
        </style>
        <div class="nsql-debug">
HTML;

        if ($this->lastError) {
            echo "<div class='error'>⚠️ <strong>Hata:</strong> {$this->lastError}</div>";
        }

        echo "<h4>🧠 SQL Sorgusu:</h4><pre>{$query}</pre>";
        echo "<h4>📦 Parametreler:</h4><pre>{$paramsJson}</pre>";

        // Sonuç verisi kontrolü
        if (is_array($this->lastResults)) {
            if (count($this->lastResults) > 0) {
                $firstRow = $this->lastResults[0];
                if (is_object($firstRow) && count((array)$firstRow) > 0) {
                    echo "<h4>📊 Sonuç Verisi:</h4><table><thead><tr>";
                    foreach ((array)$firstRow as $key => $_) {
                        echo "<th>" . htmlspecialchars((string)$key) . "</th>";
                    }
                    echo "</tr></thead><tbody>";
                    foreach ($this->lastResults as $row) {
                        echo "<tr>";
                        foreach ($row as $val) {
                            echo "<td>" . htmlspecialchars((string)$val) . "</td>";
                        }
                        echo "</tr>";
                    }
                    echo "</tbody></table>";
                } else {
                    echo "<div class='info'>Sonuç yok (Satırlar boş veya sadece başlık var).</div>";
                }
            } else {
                echo "<div class='info'>Sonuç yok (Hiç satır dönmedi).</div>";
            }
        } else {
            echo "<div class='info'>Sonuçlar dizi olarak gelmedi.</div>";
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
}
