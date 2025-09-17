<?php

namespace nsql\database\traits;

use PDO;
use PDOException;
use RuntimeException;
use nsql\database\connection_pool;
use nsql\database\config;

trait connection_trait
{
    private int $retry_limit = 2;
    private static bool $pool_initialized = false;
    private static array $pool_config = [];
    private ?PDO $pdo = null;

    /**
     * Bağlantıyı başlatır
     */
    private function initialize_connection(): void
    {
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
    private function disconnect(): void
    {
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
     */
    public function ensure_connection(): void
    {
        $attempts = 0;
        $max_attempts = $this->retry_limit;

        while ($attempts <= $max_attempts) {
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
                $this->log_error("Bağlantı kontrol hatası (Deneme $attempts/$max_attempts): " . $e->getMessage());

                if ($attempts > $max_attempts) {
                    throw new RuntimeException("Bağlantı kurulamadı ($max_attempts deneme sonrası)", 0, $e);
                }

                $this->pdo = null;
                sleep(1); // Yeni deneme öncesi kısa bekleme
            }
        }
    }

    /**
     * Connection Pool yapılandırmasını başlatır
     */
    private function initialize_connection_pool(array $config): void
    {
        if (!self::$pool_initialized) {
            self::$pool_config = $config;

            connection_pool::initialize(
                self::$pool_config,
                (int)config::get('min_connections', 2),
                (int)config::get('max_connections', 10)
            );

            self::$pool_initialized = true;
        }
    }
}
