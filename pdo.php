<?php

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
    private string $logFile = 'error_log.txt';

    public function __construct(
        string $host = 'localhost',
        string $db = 'etiyop',
        string $user = 'root',
        string $pass = '',
        string $charset = 'utf8mb4',
        bool $debug = false
    ) {
        // Ortam değişkenlerini kullanarak veritabanı kimlik bilgilerini güvence altına al
        $this->dsn = getenv('DB_DSN') ?: "mysql:host=$host;dbname=$db;charset=$charset";
        $this->user = getenv('DB_USER') ?: $user;
        $this->pass = getenv('DB_PASS') ?: $pass;
        $this->debugMode = $debug;
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
        $this->lastQuery = $sql;
        $this->lastParams = $params;
        $this->lastError = null;

        $attempts = 0;
        do {
            try {
                // Statement cache
                if (!isset($this->statementCache[$sql])) {
                    $this->statementCache[$sql] = $this->pdo->prepare($sql);
                }

                $stmt = $this->statementCache[$sql];

                // Bind parameters securely
                foreach ($params as $key => $value) {
                    $stmt->bindValue(is_int($key) ? $key + 1 : ":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
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
        return $this->fetch($sql, $params, false);
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
        </style>
        <div class="nsql-debug">
HTML;

        if ($this->lastError) {
            echo "<div class='error'>⚠️ <strong>Hata:</strong> {$this->lastError}</div>";
        }

        echo "<h4>🧠 SQL Sorgusu:</h4><pre>{$query}</pre>";
        echo "<h4>📦 Parametreler:</h4><pre>{$paramsJson}</pre>";

        if (!empty($this->lastResults) && is_array($this->lastResults)) {
            echo "<h4>📊 Sonuç Verisi:</h4><table><thead><tr>";
            foreach ((array)$this->lastResults[0] as $key => $_) {
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
