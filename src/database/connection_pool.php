<?php

namespace nsql\database;

use PDO;
use PDOException;
use RuntimeException;

class connection_pool {
    private static array $connections = [];
    private static array $active_connections = [];
    private static array $idle_connections = [];
    private static array $configuration;
    private static bool $initialized = false;
    private static ?int $last_health_check = null;
    private static array $retry_counts = [];
    
    private static array $stats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'idle_connections' => 0,
        'connection_errors' => 0,
        'connection_timeouts' => 0,
        'connection_retries' => 0,
        'health_checks' => 0,
        'failed_health_checks' => 0
    ];

    /**
     * Connection Pool'u başlatır
     * 
     * @param array $config Veritabanı yapılandırması
     * @param int $minConnections Minimum bağlantı sayısı
     * @param int $maxConnections Maksimum bağlantı sayısı
     */
    public static function initialize(array $config, int $minConnections = 2, int $maxConnections = 10): void {
        if (self::$initialized) {
            return;
        }

        self::validate_configuration($config);
        self::$configuration = $config;

        // Başlangıç bağlantılarını oluştur
        for ($i = 0; $i < $minConnections; $i++) {
            self::create_connection();
        }

        self::$initialized = true;
    }

    /**
     * Periyodik sağlık kontrolü yapar
     */
    private static function perform_health_check(): void {
        $now = time();
        
        // Sağlık kontrolü aralığını kontrol et
        if (self::$last_health_check !== null && 
            ($now - self::$last_health_check) < config::HEALTH_CHECK_INTERVAL) {
            return;
        }
        
        self::$last_health_check = $now;
        self::$stats['health_checks']++;
        
        foreach (self::$connections as $key => $conn) {
            if (!self::is_connection_valid($conn)) {
                self::$stats['failed_health_checks']++;
                unset(self::$connections[$key], self::$active_connections[$key]);
                self::create_connection();
            }
        }
    }

    /**
     * Boşta kalan bağlantıları yönetir
     */
    private static function manage_idle_connections(): void {
        $now = time();
        
        foreach (self::$connections as $key => $conn) {
            if (!isset(self::$active_connections[$key])) {
                if (!isset(self::$idle_connections[$key])) {
                    self::$idle_connections[$key] = $now;
                } elseif (($now - self::$idle_connections[$key]) > config::CONNECTION_IDLE_TIMEOUT) {
                    // Boşta kalma süresi aşıldıysa ve minimum bağlantı sayısının üzerindeyse kapat
                    if (count(self::$connections) > config::MIN_CONNECTIONS) {
                        unset(self::$connections[$key], self::$idle_connections[$key]);
                        self::$stats['idle_connections']--;
                    }
                }
            }
        }
    }

    /**
     * Bağlantı alma denemesi yapar
     */
    private static function try_get_connection(int $attempt = 0): ?\PDO {
        try {
            // Aktif bağlantı kontrolü
            foreach (self::$connections as $key => $conn) {
                if (!isset(self::$active_connections[$key])) {
                    if (self::is_connection_valid($conn)) {
                        self::$active_connections[$key] = time();
                        unset(self::$idle_connections[$key]);
                        self::$stats['active_connections']++;
                        return $conn;
                    }
                }
            }

            // Yeni bağlantı oluştur
            if (count(self::$connections) < config::MAX_CONNECTIONS) {
                return self::create_connection();
            }

            return null;
        } catch (\PDOException $e) {
            if ($attempt < config::MAX_RETRY_ATTEMPTS) {
                self::$stats['connection_retries']++;
                usleep(100000 * ($attempt + 1)); // Her denemede artan bekleme süresi
                return self::try_get_connection($attempt + 1);
            }
            throw new \RuntimeException('Maksimum bağlantı deneme sayısı aşıldı: ' . $e->getMessage());
        }
    }

    /**
     * Bağlantı havuzundan bir bağlantı alır
     * 
     * @return PDO
     * @throws RuntimeException
     */
    public static function get_connection(): \PDO {
        if (!self::$initialized) {
            throw new \RuntimeException('Connection pool başlatılmamış.');
        }

        // Sağlık kontrolü yap
        self::perform_health_check();
        
        // Boşta kalan bağlantıları yönet
        self::manage_idle_connections();
        
        // Bağlantı almayı dene
        $connection = self::try_get_connection();
        
        if ($connection) {
            return $connection;
        }

        // Timeout kontrolü ve temizlik
        self::cleanup_stale_connections();
        
        // Tekrar dene
        $connection = self::try_get_connection();
        
        if ($connection) {
            return $connection;
        }

        throw new \RuntimeException('Bağlantı havuzu dolu ve yeni bağlantı oluşturulamıyor.');
    }

    /**
     * Bağlantıyı havuza iade eder
     * 
     * @param PDO $connection
     */
    public static function release_connection(\PDO $connection): void {
        $key = array_search($connection, self::$connections, true);
        if ($key !== false) {
            unset(self::$active_connections[$key]);
            self::$stats['active_connections']--;
        }
    }

    /**
     * Havuz istatistiklerini döndürür
     * 
     * @return array
     */
    public static function get_stats(): array {
        return array_merge(self::$stats, [
            'total_connections' => count(self::$connections),
            'idle_connections' => count(self::$connections) - count(self::$active_connections)
        ]);
    }

    /**
     * Tüm bağlantıları kapatır
     */
    public static function close_all(): void {
        foreach (self::$connections as $key => $conn) {
            unset(self::$connections[$key]);
            unset(self::$active_connections[$key]);
        }
        self::$stats['active_connections'] = 0;
        self::$stats['idle_connections'] = 0;
    }

    /**
     * Yeni bir veritabanı bağlantısı oluşturur
     * 
     * @return PDO
     * @throws RuntimeException
     */
    private static function create_connection(): \PDO {
        try {
            $conn = new \PDO(
                self::$configuration['dsn'],
                self::$configuration['username'],
                self::$configuration['password'],
                self::$configuration['options']
            );

            $key = spl_object_hash($conn);
            self::$connections[$key] = $conn;
            self::$active_connections[$key] = time();
            self::$stats['active_connections']++;
            
            return $conn;
        } catch (\PDOException $e) {
            self::$stats['connection_errors']++;
            throw new \RuntimeException('Veritabanı bağlantısı oluşturulamadı: ' . $e->getMessage());
        }
    }

    /**
     * Bağlantının geçerli olup olmadığını kontrol eder
     * 
     * @param PDO $connection
     * @return bool
     */
    private static function is_connection_valid(\PDO $connection): bool {
        try {
            return $connection->query('SELECT 1') !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Timeout olan bağlantıları temizler
     */
    private static function cleanup_stale_connections(): void {
        $now = time();
        $timeout = config::CONNECTION_TIMEOUT;

        foreach (self::$active_connections as $key => $timestamp) {
            if (($now - $timestamp) > $timeout) {
                unset(self::$connections[$key]);
                unset(self::$active_connections[$key]);
                self::$stats['connection_timeouts']++;
                self::$stats['active_connections']--;
            }
        }
    }

    /**
     * Yapılandırmayı doğrular
     * 
     * @param array $config
     * @throws InvalidArgumentException
     */
    private static function validate_configuration(array $config): void {
        $required = ['dsn', 'username', 'password', 'options'];
        
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new \InvalidArgumentException("Eksik yapılandırma parametresi: $key");
            }
        }
    }
}
