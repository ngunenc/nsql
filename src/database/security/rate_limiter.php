<?php

namespace nsql\database\security;

use nsql\database\nsql;

class rate_limiter {
    private ?nsql $db;
    private string $rate_limit_table = 'rate_limits';
    private int $default_limit = 1000; // Varsayılan sorgu limiti
    private int $window_size = 3600;   // Zaman penceresi (saniye)
    private bool $is_processing = false; // İşlem kontrolü için flag

    public function __construct(?nsql $db = null) {
        $this->db = $db;
        if ($db !== null) {
            $this->init_rate_limit_table();
        }
    }

    /**
     * Rate limit tablosunu oluşturur
     */
    private function init_rate_limit_table(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->rate_limit_table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL,
            requests INT NOT NULL DEFAULT 0,
            window_start INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_identifier (identifier),
            INDEX idx_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->query($sql);
    }

    /**
     * İstek sayısını kontrol eder ve limiti aşıp aşmadığını belirler
     */
    public function check_rate_limit(string $identifier, ?int $limit = null): bool {
        if ($this->db === null) {
            return true; // Veritabanı bağlantısı yoksa limiti kontrol etme
        }

        // Sonsuz döngüyü önlemek için işlem kontrolü
        if ($this->is_processing) {
            return true;
        }

        $this->is_processing = true;
        try {
            $limit = $limit ?? $this->default_limit;
            $window_start = time() - $this->window_size;

            // Eski kayıtları temizle
            $this->cleanup_old_records($window_start);

            // Mevcut istek sayısını kontrol et
            $count = $this->get_request_count($identifier, $window_start);
            
            if ($count >= $limit) {
                return false;
            }

            // İstek sayısını artır
            $this->increment_request_count($identifier, $window_start);
            
            return true;
        } finally {
            $this->is_processing = false;
        }
    }

    /**
     * Belirli bir tanımlayıcı için istek sayısını alır
     */
    private function get_request_count(string $identifier, int $window_start): int {
        $result = $this->db->get_row(
            "SELECT SUM(requests) as total FROM {$this->rate_limit_table} 
            WHERE identifier = :identifier AND window_start >= :window_start",
            ['identifier' => $identifier, 'window_start' => $window_start]
        );

        return (int)($result->total ?? 0);
    }

    /**
     * İstek sayısını artırır
     */
    private function increment_request_count(string $identifier, int $window_start): void {
        $this->db->insert(
            "INSERT INTO {$this->rate_limit_table} (identifier, requests, window_start)
            VALUES (:identifier, 1, :window_start)
            ON DUPLICATE KEY UPDATE requests = requests + 1",
            ['identifier' => $identifier, 'window_start' => $window_start]
        );
    }

    /**
     * Eski kayıtları temizler
     */
    private function cleanup_old_records(int $window_start): void {
        $this->db->delete(
            "DELETE FROM {$this->rate_limit_table} WHERE window_start < :window_start",
            ['window_start' => $window_start]
        );
    }

    /**
     * Varsayılan sorgu limitini ayarlar
     */
    public function set_default_limit(int $limit): void {
        $this->default_limit = $limit;
    }

    /**
     * Zaman penceresi boyutunu ayarlar (saniye cinsinden)
     */
    public function set_window_size(int $seconds): void {
        $this->window_size = $seconds;
    }
}
