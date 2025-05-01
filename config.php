<?

class Database {
    private string $host = 'localhost';
    private string $db   = 'etiyop';
    private string $user = 'root';
    private string $pass = '';
    private string $charset = 'utf8mb4';

    private ?PDO $pdo = null;
    private static ?Database $instance = null;

    private function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset={$this->charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Hata yönetimi
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Varsayılan fetch modu
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Emülasyon kapalı, native prepared statements
        ];

        try {
            $this->pdo = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("Veritabanı bağlantısı başarısız: " . $e->getMessage());
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->pdo;
    }
}
