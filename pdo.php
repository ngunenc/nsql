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

    public function __construct(
        string $host = 'localhost',
        string $db = 'etiyop',
        string $user = 'root',
        string $pass = '',
        string $charset = 'utf8mb4',
        bool $debug = false
    ) {
        $this->dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $this->user = $user;
        $this->pass = $pass;
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

    public function query(string $sql, array $params = []): ?PDOStatement {
        $this->lastQuery = $sql;
        $this->lastParams = $params;
        $this->lastError = null;
    
        // IN (...) desteği için dizi parametrelerini genişlet
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $placeholders = [];
                foreach ($value as $i => $val) {
                    $newKey = "{$key}_$i";
                    $placeholders[] = ":$newKey";
                    $params[$newKey] = $val;
                }
                unset($params[$key]);
                $sql = preg_replace("/:$key\b/", implode(', ', $placeholders), $sql);
            }
        }
    
        $attempts = 0;
        do {
            try {
                // Statement cache
                if (!isset($this->statementCache[$sql])) {
                    $this->statementCache[$sql] = $this->pdo->prepare($sql);
                }
    
                $stmt = $this->statementCache[$sql];
                $stmt->execute($params);
                return $stmt;
    
            } catch (PDOException $e) {
                $attempts++;
    
                $this->lastError = $e->getMessage();
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

    public function get_row(string $sql, array $params): ?object {
        $stmt = $this->query($sql, $params);

        if ($stmt) {
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            $this->lastResults = $result ? [$result] : [];
            return $result ?: null;
        }

        $this->lastResults = [];
        return null;
    }

    public function get_results(string $sql, array $params): array {
        $stmt = $this->query($sql, $params);

        if ($stmt) {
            $results = $stmt->fetchAll(PDO::FETCH_OBJ);
            $this->lastResults = $results;
            return $results;
        }

        $this->lastResults = [];
        return [];
    }

    public function update(string $sql, array $params): bool {
        $this->lastResults = [];
        return $this->query($sql, $params) !== null;
    }

    public function delete(string $sql, array $params): bool {
        $this->lastResults = [];
        return $this->query($sql, $params) !== null;
    }

    public function insert_id(): int {
        return $this->lastInsertId;
    }

    public function begin(): void {
        $this->pdo->beginTransaction();
    }

    public function commit(): void {
        $this->pdo->commit();
    }

    public function rollback(): void {
        $this->pdo->rollBack();
    }

    public function debug(): void {
        $query = $this->interpolateQuery($this->lastQuery, $this->lastParams);
        $paramsJson = json_encode($this->lastParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

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

    private function interpolateQuery(string $query, array $params): string {
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
