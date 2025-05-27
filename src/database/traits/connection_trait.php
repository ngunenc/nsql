<?php

namespace nsql\database\traits;

use PDO;
use PDOException;
use RuntimeException;

trait connection_trait {
    private int $retry_limit = 2;
    private static bool $pool_initialized = false;
    private static array $pool_config = [];

    /**
     * Bağlantıyı başlatır
     */
    private function initialize_connection(): void {
        try {
            $this->pdo = connection_pool::get_connection();
        } catch (PDOException $e) {
            throw new RuntimeException("Veritabanı bağlantı hatası: " . $e->getMessage());
        }
    }

    /**
     * Bağlantıyı kapatır
     */
    private function disconnect(): void {
        if ($this->pdo !== null) {
            connection_pool::releaseConnection($this->pdo);
            $this->pdo = null;
        }
    }

    /**
     * Bağlantının canlı olup olmadığını kontrol eder
     */
    public function ensureConnection(): void {
        try {
            $stmt = $this->pdo->query('SELECT 1');
            if ($stmt === false) {
                $this->initializeConnection();
            }
        } catch (PDOException $e) {
            $this->initializeConnection();
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
