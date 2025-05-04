<?
error_reporting(0);

class nsql {
    private PDO $pdo;
    private string $lastQuery = '';
    private array $lastParams = [];
    private array $lastResults = [];
    private ?string $lastError = null;
    private array $statementCache = [];
    private int $lastInsertId = 0;



    public function __construct(
        string $host = 'localhost',
        string $db = 'etiyop',
        string $user = 'root',
        string $pass = '',
        string $charset = 'utf8mb4'
    ) {
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("Veritabanı bağlantı hatası: " . $e->getMessage());
        }
    }

    public function query(string $sql, array $params = []): ?PDOStatement {
        $this->lastQuery = $sql;
        $this->lastParams = $params;
        $this->lastError = null;
    
        try {
            // Statement cache kontrolü
            if (!isset($this->statementCache[$sql])) {
                $this->statementCache[$sql] = $this->pdo->prepare($sql);
            }
    
            $stmt = $this->statementCache[$sql];
            $stmt->execute($params);
    
            return $stmt;
    
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->lastResults = [];
            $this->debug(); // Hatalı sorgu durumunda detaylı bilgi göster
            return null;
        }
    }
    private function prepareParamsFromSQL(string $sql): array {
        $params = [];
        $counter = 1;
    
        // Sayısal ve tırnaklı değerleri bul
        $sql = preg_replace_callback(
            "/(?<=\s|\=|\(|,)('(?:[^'\\\\]|\\\\.)*'|\d+(\.\d+)?)/",
            function ($matches) use (&$params, &$counter) {
                $placeholder = ":param$counter";
                $value = $matches[1];
    
                // Sayı mı yoksa string mi?
                if (is_numeric($value)) {
                    $params["param$counter"] = $value + 0; // tip dönüşümü
                } else {
                    $params["param$counter"] = trim($value, "'");
                }
    
                $counter++;
                return " $placeholder";
            },
            $sql
        );
    
        return ['sql' => $sql, 'params' => $params];
    }
    

    public function insert(string $sql, array $params = []): bool {
        $this->lastResults = [];
        $this->lastInsertId = 0;
    
        if (empty($params)) {
            $parsed = $this->prepareParamsFromSQL($sql);
            $sql = $parsed['sql'];
            $params = $parsed['params'];
        }
    
        $stmt = $this->query($sql, $params);
    
        if ($stmt) {
            $this->lastInsertId = (int)$this->pdo->lastInsertId();
            return true;
        }
    
        return false;
    }
        
    
    
    public function get_row(string $sql, array $params = []): ?object {
        if (empty($params)) {
            $parsed = $this->prepareParamsFromSQL($sql);
            $sql = $parsed['sql'];
            $params = $parsed['params'];
        }
    
        $stmt = $this->query($sql, $params);
    
        if ($stmt) {
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            $this->lastResults = $result ? [$result] : [];
            return $result ?: null;
        } else {
            $this->lastResults = [];
            return null;
        }
    }
    
    public function get_results(string $sql, array $params = []): array {
        if (empty($params)) {
            $parsed = $this->prepareParamsFromSQL($sql);
            $sql = $parsed['sql'];
            $params = $parsed['params'];
        }
    
        $stmt = $this->query($sql, $params);
    
        if ($stmt) {
            $results = $stmt->fetchAll(PDO::FETCH_OBJ);
            $this->lastResults = $results;
            return $results;
        } else {
            $this->lastResults = [];
            return [];
        }
    }
    
    

    public function update(string $sql, array $params = []): bool {
        $this->lastResults = [];
    
        if (empty($params)) {
            $parsed = $this->prepareParamsFromSQL($sql);
            $sql = $parsed['sql'];
            $params = $parsed['params'];
        }
    
        $stmt = $this->query($sql, $params);
        return $stmt !== null;
    }
    

    public function delete(string $sql, array $params = []): bool {
        $this->lastResults = [];
    
        if (empty($params)) {
            $parsed = $this->prepareParamsFromSQL($sql);
            $sql = $parsed['sql'];
            $params = $parsed['params'];
        }
    
        $stmt = $this->query($sql, $params);
        return $stmt !== null;
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

    private function printError(string $message): void {
        if (!$this->debugMode) return;
        echo <<<HTML
    <div style="
        background-color: #ffecec;
        color: #c00;
        border: 1px solid #ff5e5e;
        padding: 10px;
        margin: 16px 0;
        font-family: monospace;
        border-radius: 6px;
    ">
        <strong>⚠️ PDO Hatası:</strong> $message
    </div>
    HTML;
    }
    
    private function emptyStatement(): PDOStatement {
        return new class extends PDOStatement {
            public function fetch($mode = null, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0) { return false; }
            public function fetchAll($mode = null, $fetch_argument = null, array $ctor_args = []): array { return []; }
        };
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
    
        // Eğer hata varsa en üstte göster
        if ($this->lastError) {
            echo "<div class='error'>⚠️ <strong>Hata:</strong> {$this->lastError}</div>";
        }
    
        echo <<<HTML
            <h4>🧠 SQL Sorgusu:</h4>
            <pre>{$query}</pre>
    
            <h4>📦 Parametreler:</h4>
            <pre>{$paramsJson}</pre>
    HTML;
    
        if (!empty($this->lastResults) && is_array($this->lastResults)) {
            echo "<h4>📊 Sonuç Verisi:</h4>";
            echo "<table><thead><tr>";
    
            // Başlıkları al
            foreach ((array)$this->lastResults[0] as $key => $_) {
                echo "<th>" . htmlspecialchars((string)$key) . "</th>";
            }
    
            echo "</tr></thead><tbody>";
    
            // Veriyi yaz
            foreach ($this->lastResults as $row) {
                echo "<tr>";
                foreach ($row as $val) {
                    $val = htmlspecialchars((string)$val);
                    echo "<td>{$val}</td>";
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
