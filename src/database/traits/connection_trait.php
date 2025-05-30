<?php

namespace nsql\database\traits;

use PDO;
use PDOException;
use RuntimeException;

trait connection_trait {
    private int $retry_limit = 2;
    private static bool $pool_initialized = false;
    private static array $pool_config = [];    /**
     * Bağlantıyı başlatır
     */
    private function initialize_connection(): void {
        try {
            $this->pdo = connection_pool::get_connection();
            
            // PDO hata modunu kontrol et ve ayarla
            if ($this->pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
                $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }
        } catch (PDOException $e) {
            $this->log_error("Veritabanı bağlantı hatası: " . $e->getMessage());
            throw new RuntimeException("Veritabanı bağlantı hatası: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Bağlantıyı kapatır
     */
    private function disconnect(): void {
        if ($this->pdo !== null) {
            try {
                connection_pool::release_connection($this->pdo);
            } catch (PDOException $e) {
                $this->log_error("Bağlantı kapatma hatası: " . $e->getMessage());
            }
            $this->pdo = null;
        }
    }

    /**
     * Bağlantının canlı olup olmadığını kontrol eder
     */    public function ensure_connection(): void {
        $attempts = 0;
        $maxAttempts = $this->retry_limit;
        
        while ($attempts <= $maxAttempts) {
            try {
                if ($this->pdo === null) {
                    $this->initialize_connection();
                    return;
                }

                $stmt = $this->pdo->query('SELECT 1');
                if ($stmt !== false) {
                    return;
                }
            } catch (PDOException $e) {
                $attempts++;
                $this->log_error("Bağlantı kontrol hatası (Deneme $attempts/$maxAttempts): " . $e->getMessage());
                
                if ($attempts > $maxAttempts) {
                    throw new RuntimeException("Bağlantı kurulamadı ($maxAttempts deneme sonrası)", 0, $e);
                }
                
                $this->pdo = null;
                sleep(1); // Yeni deneme öncesi kısa bekleme
            }
        }
    }

    /**
     * Connection Pool yapılandırmasını başlatır
     */
    private function initializeConnectionPool(array $config): void {
        if (!self::$poolInitialized) {
            self::$poolConfig = $config;
            
            connection_pool::initialize(
                self::$poolConfig,
                Config::get('DB_MIN_CONNECTIONS', 2),
                Config::get('DB_MAX_CONNECTIONS', 10)
            );
            
            self::$poolInitialized = true;
        }
    }
}
