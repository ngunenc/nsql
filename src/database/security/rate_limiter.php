<?php

namespace nsql\database\security;

use nsql\database\nsql;

class rate_limiter {
    private nsql $db;
    private string $rate_limit_table = 'rate_limits';
    private float $token_rate = 1.0;
    private int $burst_limit = 50;
    private array $limit_types = ['api', 'auth', 'default'];

    public function __construct(?nsql $db = null) {
        $this->db = $db;
        if ($db !== null) {
            $this->init_rate_limit_table();
        }
        
        // Default değerleri config'den al
        $this->token_rate = config::RATE_LIMIT_DECAY;
        $this->burst_limit = config::RATE_LIMIT_BURST;
    }

    /**
     * Rate limit tablosunu oluşturur
     */
    private function init_rate_limit_table(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->rate_limit_table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL,
            request_type VARCHAR(50) NOT NULL DEFAULT 'default',
            tokens FLOAT NOT NULL DEFAULT 0,
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

        $this->db->query($sql);
    }

    /**
     * Rate limit kontrolü yapar
     */
    public function check_rate_limit(string $identifier, string $request_type = 'default'): bool {
        $now = time();
        $window_start = $now - config::RATE_LIMIT_WINDOW;
        
        // Mevcut tokenleri al
        $limit = $this->db->get_row(
            "SELECT * FROM {$this->rate_limit_table} 
             WHERE identifier = :identifier AND request_type = :type",
            ['identifier' => $identifier, 'type' => $request_type]
        );

        if (!$limit) {
            // Yeni kayıt oluştur
            $this->db->insert(
                "INSERT INTO {$this->rate_limit_table}
                 (identifier, request_type, tokens, last_update, window_start, total_requests)
                 VALUES (:identifier, :type, :tokens, :now, :window, 0)",
                [
                    'identifier' => $identifier,
                    'type' => $request_type,
                    'tokens' => config::RATE_LIMIT_MAX_REQUESTS,
                    'now' => $now,
                    'window' => $window_start
                ]
            );
            return true;
        }

        // Zaman penceresi kontrolü
        if ($limit->window_start < $window_start) {
            // Yeni pencere başlat
            $tokens = config::RATE_LIMIT_MAX_REQUESTS;
            $burst_count = 0;
            $burst_start = $now;
        } else {
            // Token yenileme
            $elapsed = $now - $limit->last_update;
            $new_tokens = min(
                config::RATE_LIMIT_MAX_REQUESTS,
                $limit->tokens + ($elapsed * $this->token_rate)
            );
            
            // Burst kontrolleri
            $in_burst = ($now - $limit->burst_start) < 1;
            if ($in_burst) {
                if ($limit->burst_count >= $this->burst_limit) {
                    return false;
                }
                $burst_count = $limit->burst_count + 1;
                $burst_start = $limit->burst_start;
            } else {
                $burst_count = 1;
                $burst_start = $now;
            }
            
            $tokens = $new_tokens - 1;
        }

        if ($tokens < 0) {
            return false;
        }

        // Rate limit bilgilerini güncelle
        $this->update_rate_limit(
            $identifier,
            $request_type,
            $tokens,
            $now,
            $burst_count,
            $burst_start,
            $window_start,
            $limit->total_requests + 1
        );

        return true;
    }

    /**
     * Rate limit verilerini günceller
     */
    private function update_rate_limit(
        string $identifier,
        string $request_type,
        float $tokens,
        int $last_update,
        int $burst_count,
        int $burst_start,
        int $window_start,
        int $total_requests
    ): void {
        $this->db->update(
            "UPDATE {$this->rate_limit_table}
             SET tokens = :tokens,
                 last_update = :last_update,
                 burst_count = :burst_count,
                 burst_start = :burst_start,
                 window_start = :window_start,
                 total_requests = :total_requests
             WHERE identifier = :identifier AND request_type = :type",
            [
                'identifier' => $identifier,
                'type' => $request_type,
                'tokens' => $tokens,
                'last_update' => $last_update,
                'burst_count' => $burst_count,
                'burst_start' => $burst_start,
                'window_start' => $window_start,
                'total_requests' => $total_requests
            ]
        );
    }
}
