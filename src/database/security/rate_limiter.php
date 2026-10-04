<?php

namespace nsql\database\security;

use nsql\database\config;
use nsql\database\nsql;

/**
 * Veritabanı destekli token bucket.
 *
 * - Kova kapasitesi RATE_LIMIT_MAX_REQUESTS; kova RATE_LIMIT_WINDOW saniyede tamamen dolar
 *   (saniyede max_requests / window token).
 * - RATE_LIMIT_BURST aynı saniye içinde izin verilen en fazla istek sayısıdır.
 * - Satır `SELECT ... FOR UPDATE` ile kilitlenir; eşzamanlı istekler aynı token'ı harcayamaz.
 */
class rate_limiter
{
    /** Eski şemalardaki FLOAT kolonun yuvarlama hatası */
    private const TOKEN_EPSILON = 1e-6;

    /** @var array<string, bool> Süreç içinde kurulmuş tablolar */
    private static array $installed_tables = [];

    private ?nsql $db;
    private string $table;
    private int $capacity;
    private int $window;
    private int $burst_limit;
    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(): int|null $clock Unix zamanı (saniye) döndüren saat; testlerde enjekte edilir
     * @param array{table?: string, max_requests?: int, window?: int, burst?: int} $options config değerlerini ezer
     */
    public function __construct(?nsql $db = null, ?callable $clock = null, array $options = [])
    {
        $this->db = $db;
        $this->clock = $clock ?? static fn (): int => time();

        $this->table = (string) ($options['table'] ?? 'rate_limits');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table)) {
            throw new \InvalidArgumentException("Geçersiz rate limit tablo adı: {$this->table}");
        }

        $this->capacity = max(1, (int) ($options['max_requests'] ?? config::get('RATE_LIMIT_MAX_REQUESTS', config::rate_limit_max_requests)));
        $this->window = max(1, (int) ($options['window'] ?? config::get('RATE_LIMIT_WINDOW', config::rate_limit_window)));
        $this->burst_limit = max(1, (int) ($options['burst'] ?? config::get('RATE_LIMIT_BURST', config::rate_limit_burst)));
    }

    /**
     * Tabloyu oluşturan DDL (migration'da kullanmak için).
     */
    public static function schema_sql(string $table = 'rate_limits'): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL,
            request_type VARCHAR(50) NOT NULL DEFAULT 'default',
            tokens DOUBLE NOT NULL DEFAULT 0,
            last_update INT NOT NULL,
            burst_count INT NOT NULL DEFAULT 0,
            burst_start INT NOT NULL DEFAULT 0,
            window_start INT NOT NULL,
            total_requests INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_identifier (identifier),
            INDEX idx_type (request_type),
            INDEX idx_window (window_start),
            UNIQUE KEY uk_identifier_type (identifier, request_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /**
     * Tabloyu oluşturur. check_rate_limit() ilk çağrıda bunu kendisi yapar; ancak MySQL'de DDL
     * açık transaction'ı örtük olarak commit eder, bu yüzden transaction içinde kullanılacaksa önceden çağırın.
     */
    public function install(): void
    {
        $this->require_db()->query(self::schema_sql($this->table));
        self::$installed_tables[$this->table] = true;
    }

    /**
     * İsteğe izin verilip verilmediğini döndürür ve izin verildiyse bir token harcar.
     */
    public function check_rate_limit(string $identifier, string $request_type = 'default'): bool
    {
        $db = $this->require_db();
        if (! isset(self::$installed_tables[$this->table])) {
            $this->install();
        }

        $now = (int) ($this->clock)();
        $key = ['identifier' => $identifier, 'type' => $request_type];

        $db->begin();

        try {
            // nsql::get_row()/insert() hataları yutar; kilit zaman aşımı gibi hatalar burada yayılmalı.
            $pdo = $db->get_pdo() ?? throw new \RuntimeException('PDO bağlantısı yok');

            $pdo->prepare(
                "INSERT INTO {$this->table} (identifier, request_type, tokens, last_update, burst_count, burst_start, window_start, total_requests)
                 VALUES (:identifier, :type, :tokens, :now, 0, 0, :now2, 0)
                 ON DUPLICATE KEY UPDATE identifier = identifier"
            )->execute($key + ['tokens' => $this->capacity, 'now' => $now, 'now2' => $now]);

            $select = $pdo->prepare(
                "SELECT tokens, last_update, burst_count, burst_start, total_requests
                 FROM {$this->table}
                 WHERE identifier = :identifier AND request_type = :type
                 FOR UPDATE"
            );
            $select->execute($key);
            $row = $select->fetch(\PDO::FETCH_ASSOC);
            $select->closeCursor();
            if (! is_array($row)) {
                throw new \RuntimeException('Rate limit kaydı okunamadı');
            }

            $elapsed = max(0, $now - (int) $row['last_update']);
            $tokens = min((float) $this->capacity, (float) $row['tokens'] + $elapsed * $this->refill_rate());
            $burst_count = (int) $row['burst_start'] === $now ? (int) $row['burst_count'] : 0;
            $total = (int) $row['total_requests'];

            $allowed = $tokens >= 1.0 - self::TOKEN_EPSILON && $burst_count < $this->burst_limit;
            if ($allowed) {
                $tokens = max(0.0, $tokens - 1.0);
                $burst_count++;
                $total++;
            }

            $pdo->prepare(
                "UPDATE {$this->table}
                 SET tokens = :tokens, last_update = :now, burst_count = :burst_count, burst_start = :now2, total_requests = :total
                 WHERE identifier = :identifier AND request_type = :type"
            )->execute($key + ['tokens' => $tokens, 'now' => $now, 'now2' => $now, 'burst_count' => $burst_count, 'total' => $total]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();

            throw $e;
        }

        return $allowed;
    }

    /**
     * Saniyede eklenen token sayısı.
     */
    public function refill_rate(): float
    {
        return $this->capacity / $this->window;
    }

    private function require_db(): nsql
    {
        if ($this->db === null) {
            throw new \RuntimeException('Database connection is required for rate limiting');
        }

        return $this->db;
    }
}
