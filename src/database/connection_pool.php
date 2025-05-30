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
        'failed_health_checks' => 0,
        'peak_connections' => 0,
        'total_queries' => 0,
        'slow_queries' => 0
    ];

    /**
     * Connection Pool'u başlatır
     */
    public static function initialize(array $config, int $minConnections = 5, int $maxConnections = 20): void {
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
        self::$last_health_check = time();
    }

    /**
     * Sağlık kontrolü yapar
     */
    private static function perform_health_check(): void {
        $now = time();
        
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

        // Boşta kalan bağlantıları yönet
        self::manage_idle_connections();
        
        // Timeout olan bağlantıları temizle
        self::cleanup_stale_connections();
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
     * Havuzdan bir bağlantı alır
     * @throws RuntimeException Bağlantı alınamazsa
     */
    public static function get_connection(): \PDO {
        if (!self::$initialized) {
            throw new \RuntimeException('Connection pool başlatılmamış');
        }

        // Sağlık kontrolü yap
        self::perform_health_check();

        // Bağlantı almayı dene
        $conn = self::try_get_connection();
        
        if ($conn === null) {
            throw new \RuntimeException(
                'Kullanılabilir bağlantı yok. Aktif: ' . count(self::$active_connections) . 
                ', Toplam: ' . count(self::$connections)
            );
        }

        return $conn;
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

            // Tüm bağlantılar kullanımda, timeout kontrolü yap
            self::cleanup_stale_connections();

            return null;
        } catch (\PDOException $e) {
            if ($attempt < config::MAX_RETRY_ATTEMPTS) {
                self::$stats['connection_retries']++;
                sleep(1); // Kısa bir bekleme
                return self::try_get_connection($attempt + 1);
            }
            throw new \RuntimeException('Bağlantı hatası: ' . $e->getMessage());
        }
    }

    /**
     * Yeni bağlantı oluşturur
     */
    private static function create_connection(): \PDO {
        try {
            // Varsayılan PDO seçeneklerini ayarla
            $default_options = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_TIMEOUT            => config::CONNECTION_TIMEOUT,
                \PDO::ATTR_PERSISTENT         => config::PERSISTENT_CONNECTION
            ];

            $final_options = $default_options;
            
            // Özel yapılandırma seçeneklerini ekle
            if (isset(self::$configuration['options']) && is_array(self::$configuration['options'])) {
                foreach (self::$configuration['options'] as $key => $value) {
                    // PDO sabitlerini doğrula ve güvenli şekilde ayarla
                    if (is_string($key) && strpos($key, 'ATTR_') === 0) {
                        $attrName = substr($key, 5); // ATTR_ kısmını kaldır
                        if (defined("\\PDO::ATTR_$attrName")) {
                            $pdoKey = constant("\\PDO::ATTR_$attrName");
                            $final_options[$pdoKey] = $value;
                        }
                    } else {
                        // Sayısal anahtar veya doğrudan PDO sabiti
                        if (is_int($key) && defined("\\PDO::ATTR_$value")) {
                            $pdoKey = constant("\\PDO::ATTR_$value");
                            $final_options[$pdoKey] = true;
                        } else {
                            $final_options[$key] = $value;
                        }
                    }
                }
            }

            // PDO bağlantısını oluştur
            $conn = new \PDO(
                self::$configuration['dsn'],
                self::$configuration['username'],
                self::$configuration['password'],
                $final_options
            );

            // Bağlantı başarılı olduğunda yapılandırmayı doğrula
            $actualErrMode = $conn->getAttribute(\PDO::ATTR_ERRMODE);
            if ($actualErrMode !== \PDO::ERRMODE_EXCEPTION) {
                $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }

            $key = spl_object_hash($conn);
            self::$connections[$key] = $conn;
            self::$active_connections[$key] = time();
            
            self::$stats['total_connections']++;
            self::$stats['active_connections']++;
            
            // Peak bağlantı sayısını güncelle
            self::$stats['peak_connections'] = max(
                self::$stats['peak_connections'],
                count(self::$connections)
            );
            
            return $conn;
        } catch (\PDOException $e) {
            self::$stats['connection_errors']++;
            throw new \RuntimeException('Veritabanı bağlantısı oluşturulamadı: ' . $e->getMessage());
        }
    }

    /**
     * Bağlantının geçerli olup olmadığını kontrol eder
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
                unset(self::$connections[$key], self::$active_connections[$key]);
                self::$stats['connection_timeouts']++;
                self::$stats['active_connections']--;
            }
        }
    }

    /**
     * Yapılandırmayı doğrular
     */
    private static function validate_configuration(array $config): void {
        $required = ['dsn', 'username', 'password', 'options'];
        
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new \InvalidArgumentException("Eksik yapılandırma parametresi: $key");
            }
        }
    }

    /**
     * Pool istatistiklerini döndürür
     */
    public static function get_stats(): array {
        return array_merge(self::$stats, [
            'current_connections' => count(self::$connections),
            'idle_connections' => count(self::$connections) - count(self::$active_connections),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ]);
    }

    /**
     * Bağlantıyı havuza geri bırakır
     */
    public static function release_connection(\PDO $connection): void {
        $key = spl_object_hash($connection);
        
        // Bağlantı zaten havuzda değilse işlem yapma
        if (!isset(self::$connections[$key])) {
            return;
        }
        
        // Bağlantının geçerli olduğunu kontrol et
        if (!self::is_connection_valid($connection)) {
            // Geçersiz bağlantıyı kaldır ve yerine yeni bir tane oluştur
            unset(self::$connections[$key], self::$active_connections[$key]);
            self::$stats['failed_health_checks']++;
            self::create_connection();
            return;
        }
        
        // Bağlantıyı aktif listesinden çıkar
        if (isset(self::$active_connections[$key])) {
            unset(self::$active_connections[$key]);
            self::$stats['active_connections']--;
            
            // Bağlantıyı boşta kalanlar listesine ekle
            self::$idle_connections[$key] = time();
            self::$stats['idle_connections']++;
        }
    }

    /**
     * Tüm bağlantıları kapatır
     */
    public static function close_all(): void {
        foreach (self::$connections as $key => $conn) {
            unset(self::$connections[$key]);
            unset(self::$active_connections[$key]);
            unset(self::$idle_connections[$key]);
        }
        self::$stats['active_connections'] = 0;
        self::$stats['idle_connections'] = 0;
    }
}
