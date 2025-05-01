<?

class nsql {
    private PDO $pdo;
    private string $lastQuery = '';
    private array $lastParams = [];

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

    public function query(string $sql, array $params = []): PDOStatement {
        $this->lastQuery = $sql;
        $this->lastParams = $params;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    public function get_results(string $sql, array $params = []): array {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }
    
    public function get_row(string $sql, array $params = []): object|false {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    

    public function debug(): void {
        echo "🔍 Sorgu: " . $this->interpolateQuery($this->lastQuery, $this->lastParams) . PHP_EOL;
        echo "<br>";
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
