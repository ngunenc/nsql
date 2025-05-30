<?php

namespace nsql\database;

use RuntimeException;

class config {
    private static array $config = [];
    private static bool $loaded = false;
    private static string $environment = 'production';

    // Cache yapılandırma sabitleri
    public const QUERY_CACHE_ENABLED = true;
    public const QUERY_CACHE_TIMEOUT = 300; // 5 dakika
    public const QUERY_CACHE_SIZE_LIMIT = 2000; // Cache limiti artırıldı
    public const STATEMENT_CACHE_LIMIT = 200; // Statement cache limiti artırıldı

    // Connection pool yapılandırma sabitleri  
    public const MIN_CONNECTIONS = 5;
    public const MAX_CONNECTIONS = 50; // Maksimum bağlantı sayısı artırıldı
    public const CONNECTION_TIMEOUT = 10; // Timeout süresi optimize edildi
    public const CONNECTION_LIFETIME = 1800; // 30 dakika
    public const CONNECTION_IDLE_TIMEOUT = 180; // Boşta kalma süresi optimize edildi
    public const MAX_RETRY_ATTEMPTS = 3;
    public const HEALTH_CHECK_INTERVAL = 30; // Sağlık kontrolü sıklaştırıldı
    public const PERSISTENT_CONNECTION = false; // Kalıcı bağlantı varsayılan olarak kapalı

    // Memory limitleri (bytes)
    public const MEMORY_LIMIT_WARNING = 134217728; // 128MB
    public const MEMORY_LIMIT_CRITICAL = 268435456; // 256MB 
    public const MEMORY_CHECK_INTERVAL = 20; // Memory kontrol sıklığı artırıldı

    // Chunk processing ayarları
    public const DEFAULT_CHUNK_SIZE = 1000;
    public const MAX_CHUNK_SIZE = 5000;
    public const MIN_CHUNK_SIZE = 200; // Minimum chunk boyutu artırıldı
    public const AUTO_ADJUST_CHUNK_SIZE = true;
    
    // Resource limitleri
    public const MAX_EXECUTION_TIME = 300; // 5 dakika
    public const MAX_RESULT_SET_SIZE = 100000; // Sonuç seti limiti artırıldı
    public const ENABLE_RESOURCE_MONITORING = true;
    public const LARGE_RESULT_WARNING = 5000; // Uyarı eşiği artırıldı

    // Rate limiting sabitleri
    public const RATE_LIMIT_WINDOW = 60; // 1 dakika
    public const RATE_LIMIT_MAX_REQUESTS = 1000; // Dakikada maksimum istek
    public const RATE_LIMIT_BURST = 50; // Burst istek limiti
    public const RATE_LIMIT_DECAY = 2.0; // Token decay hızı
    
    // Sorgu performans sabitleri
    public const SLOW_QUERY_THRESHOLD = 1.0; // 1 saniye üzeri yavaş sorgu sayılır
    public const QUERY_TIMEOUT = 30; // Sorgu timeout süresi (saniye)
    public const MAX_FAILED_ATTEMPTS = 3; // Maksimum başarısız sorgu denemesi

    // Audit log sabitleri
    public const AUDIT_LOG_FILE = 'audit_log.txt';
    public const AUDIT_LOG_ROTATION = 'daily'; // Log rotasyon periyodu
    public const AUDIT_LOG_MAX_SIZE = 104857600; // 100MB
    public const AUDIT_LOG_MAX_FILES = 30; // Maksimum log dosyası sayısı

    /**
     * Yapılandırmayı yükler
     * 
     * @throws RuntimeException
     */
    public static function load(): void {
        if (self::$loaded) {
            return;
        }

        $envFile = self::getEnvironmentFile();
        
        if (!file_exists($envFile)) {
            throw new \RuntimeException('.env dosyası bulunamadı. Lütfen .env.example dosyasını .env olarak kopyalayın ve yapılandırın.');
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('.env dosyası okunamadı.');
        }

        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Özel değer dönüşümleri
            self::$config[$key] = self::parseValue($value);
        }

        self::validateRequiredConfig();
        self::$loaded = true;
    }

    /**
     * Yapılandırma değeri alır
     * 
     * @param string $key Anahtar
     * @param mixed $default Varsayılan değer
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        if (!self::$loaded) {
            self::load();
        }

        return self::$config[$key] ?? $default;
    }

    /**
     * Tüm yapılandırmayı döndürür
     * 
     * @return array
     */
    public static function all(): array {
        if (!self::$loaded) {
            self::load();
        }

        return self::$config;
    }

    /**
     * Çalışma ortamını ayarlar
     * 
     * @param string $env Ortam (production, development, testing)
     */
    public static function setEnvironment(string $env): void {
        if (!in_array($env, ['production', 'development', 'testing'])) {
            throw new \InvalidArgumentException('Geçersiz ortam: ' . $env);
        }
        self::$environment = $env;
    }

    /**
     * Çalışma ortamını döndürür
     * 
     * @return string
     */
    public static function getEnvironment(): string {
        return self::$environment;
    }

    /**
     * Değeri uygun tipe dönüştürür
     * 
     * @param string $value Değer
     * @return mixed
     */
    private static function parseValue(string $value) {
        // Boolean değerleri dönüştür
        if (strtolower($value) === 'true') return true;
        if (strtolower($value) === 'false') return false;
        if (strtolower($value) === 'null') return null;
        
        // Sayısal değerleri dönüştür
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        // Dizi değerleri dönüştür (virgülle ayrılmış)
        if (strpos($value, ',') !== false) {
            return array_map('trim', explode(',', $value));
        }

        return $value;
    }

    /**
     * Gerekli yapılandırma değerlerini kontrol eder
     * 
     * @throws RuntimeException
     */
    private static function validateRequiredConfig(): void {
        $required = ['DB_HOST', 'DB_NAME', 'DB_USER'];
        
        foreach ($required as $key) {
            if (!isset(self::$config[$key])) {
                throw new \RuntimeException("Gerekli yapılandırma değeri eksik: $key");
            }
        }
    }

    /**
     * Ortama göre yapılandırma dosyasını belirler
     * 
     * @return string
     */
    private static function getEnvironmentFile(): string {
        $env = self::$environment;
        $baseDir = dirname(__DIR__, 2); // Proje kök dizini

        if ($env === 'testing') {
            return $baseDir . '/.env.testing';
        }

        if ($env === 'development') {
            return $baseDir . '/.env.development';
        }

        return $baseDir . '/.env';
    }
}
