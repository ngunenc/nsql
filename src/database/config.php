<?php

namespace nsql\database;

/**
 * config
 *
 * Basit bir yapılandırma yöneticisi.
 * - .env dosyasını otomatik yükler (harici paket gerekmez)
 * - Ortam (environment) yönetimi: production/development/testing
 * - Tip güvenli get/set (bool/int/float otomatik dönüştürme)
 * - Varsayılanlar ve önbellekleme
 */
class config
{
    /** @var array<string, mixed> */
    private static array $config = [];
    private static bool $env_loaded = false;
    private static string $environment = 'production';
    private static ?string $project_root = null;

    // Sık kullanılan varsayılan sabitler
    public const default_chunk_size = 1000;

    // Connection Pool sabitleri (optimize edilmiş)
    public const health_check_interval = 60; // 30s → 60s (performans artışı)
    public const connection_idle_timeout = 600; // 300s → 600s (daha uzun idle timeout)
    public const min_connections = 2; // 1 → 2 (daha iyi başlangıç)
    public const max_connections = 15; // 10 → 15 (daha yüksek kapasite)
    public const max_retry_attempts = 2; // 3 → 2 (daha hızlı hata yönetimi)
    public const cleanup_probability = 5; // 10 → 5 (daha az agresif temizlik)
    public const max_failed_connections = 3; // 5 → 3 (daha hızlı recovery)

    // Performans sabitleri (optimize edilmiş)
    public const large_result_warning = 15000; // 10000 → 15000 (daha yüksek threshold)
    public const max_result_set_size = 2000000; // 1000000 → 2000000 (daha yüksek limit)
    public const memory_check_interval = 60; // 30s → 60s (performans artışı)
    public const memory_limit_warning = 201326592; // 128MB → 192MB (daha yüksek warning)
    public const memory_limit_critical = 402653184; // 256MB → 384MB (daha yüksek critical)
    public const auto_adjust_chunk_size = true;
    public const min_chunk_size = 200; // 100 → 200 (daha büyük minimum chunk)
    public const max_chunk_size = 15000; // 10000 → 15000 (daha büyük maximum chunk)

    // Cache sabitleri (optimize edilmiş)
    public const query_cache_enabled = true;
    public const query_cache_timeout = 1800; // 30 dakika (3600 → 1800, daha kısa TTL)
    public const query_cache_size_limit = 200; // 100 → 200 (daha büyük cache)
    public const statement_cache_limit = 150; // 100 → 150 (daha büyük statement cache)
    public const cache_cleanup_probability = 10; // %10 olasılıkla temizlik

    // Rate Limiting sabitleri
    public const rate_limit_decay = 1;
    public const rate_limit_burst = 10;
    public const rate_limit_window = 60;
    public const rate_limit_max_requests = 100;

    /**
     * Ortamı ayarla (production/development/testing gibi)
     */
    public static function set_environment(string $environment): void
    {
        self::$environment = trim($environment) !== '' ? $environment : 'production';
    }

    /**
     * Geçerli ortamı döndürür
     */
    public static function get_environment(): string
    {
        return self::$environment;
    }

    /**
     * Yapılandırma değerini alır. .env > env var > dahili config > varsayılan
     * Tip dönüşümü yapar (true/false, int, float, null, JSON)
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensure_bootstrapped();

        $key = strtoupper($key);

        if (array_key_exists($key, self::$config)) {
            return self::$config[$key];
        }

        $env_value = getenv($key);
        if ($env_value !== false) {
            $value = self::cast_value($env_value);
            self::$config[$key] = $value;

            return $value;
        }

        return $default;
    }

    /**
     * Yapılandırma değerini ayarlar (runtime override)
     */
    public static function set(string $key, mixed $value): void
    {
        $key = strtoupper($key);
        self::$config[$key] = $value;
    }

    /** Ortam değişkeni mevcut mu? */
    public static function has(string $key): bool
    {
        self::ensure_bootstrapped();
        $key = strtoupper($key);

        return array_key_exists($key, self::$config) || getenv($key) !== false;
    }

    /** Tüm konfigürasyon değerlerini döndürür (kopya) */
    public static function all(): array
    {
        self::ensure_bootstrapped();

        return self::$config;
    }

    /** Proje kök dizinini döndürür */
    public static function get_project_root(): string
    {
        self::ensure_bootstrapped();

        return self::$project_root ?? (getcwd() ?: __DIR__);
    }

    /** Yüklenen .env ve config önbelleğini sıfırlar */
    public static function refresh(): void
    {
        self::$env_loaded = false;
        self::$config = [];
        self::ensure_bootstrapped();
    }

    /**
     * Başlatma: proje kökünü belirle, .env dosyasını yükle, varsayılanları uygula
     */
    private static function ensure_bootstrapped(): void
    {
        if (self::$env_loaded) {
            return;
        }

        self::$project_root = self::detect_project_root();
        self::load_env_file(self::$project_root . DIRECTORY_SEPARATOR . '.env');
        self::apply_defaults();
        self::detect_and_set_environment();
        self::$env_loaded = true;
    }

    /** Proje kök dizinini kaba tahmin ile bulur */
    private static function detect_project_root(): string
    {
        // src/database/config.php -> projeKökü = 2 seviye yukarı
        $root = dirname(__DIR__, 2);

        return $root !== '' ? $root : (getcwd() ?: __DIR__);
    }

    /** .env dosyasını okuyup self::$config içine yükler */
    private static function load_env_file(string $env_path): void
    {
        if (! is_file($env_path) || ! is_readable($env_path)) {
            return;
        }

        $lines = @file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // KEY=VALUE formatı
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = strtoupper(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));

            // Çift tırnak/tek tırnakları temizle
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            self::$config[$key] = self::cast_value($value);
        }
    }

    /** Ortamı ENV değişkenlerinden tahmin eder ve set eder */
    private static function detect_and_set_environment(): void
    {
        $env = getenv('ENV') ?: (self::$config['ENV'] ?? null);
        if (is_string($env) && $env !== '') {
            self::$environment = $env;
        }
    }

    /** Varsayılanları uygular (sadece set edilmemişse) */
    private static function apply_defaults(): void
    {
        $defaults = [
            // DB
            'DB_HOST' => 'localhost',
            'DB_NAME' => 'nsql',
            'DB_USER' => 'root',
            'DB_PASS' => '',
            'DB_CHARSET' => 'utf8mb4',

            // PDO/options
            'CONNECTION_TIMEOUT' => 5,
            'PERSISTENT_CONNECTION' => false,

            // Connection Pool ayarları
            'health_check_interval' => 30, // saniye
            'connection_idle_timeout' => 300, // 5 dakika
            'min_connections' => 1,
            'max_connections' => 10,
            'max_retry_attempts' => 3,
            'cleanup_probability' => 10, // %10 olasılık
            'max_failed_connections' => 5,

            // Debug & Log
            'debug_mode' => false,
            'log_file' => 'error_log.txt',
            'audit_log_file' => 'audit_log.txt',
            'log_dir' => null, // null = otomatik tespit
            'log_max_size' => 1048576, // 1MB

            // Cache ayarları
            'statement_cache_limit' => 100,
            'query_cache_enabled' => false,
            'query_cache_timeout' => 3600,
            'query_cache_size_limit' => 100,

            // Performans sabitleri
            'large_result_warning' => 10000,
            'max_result_set_size' => 1000000,
            'memory_check_interval' => 30,
            'memory_limit_warning' => 128 * 1024 * 1024, // 128MB
            'memory_limit_critical' => 256 * 1024 * 1024, // 256MB
            'auto_adjust_chunk_size' => true,
            'min_chunk_size' => 100,
            'max_chunk_size' => 10000,

            // Rate Limiting ayarları
            'rate_limit_decay' => 1, // saniye
            'rate_limit_burst' => 10, // burst limit
            'rate_limit_window' => 60, // 1 dakika
            'rate_limit_max_requests' => 100, // dakikada max istek

            // Güvenlik
            'security_strict_mode' => false,
        ];

        foreach ($defaults as $k => $v) {
            if (! array_key_exists($k, self::$config) && getenv($k) === false) {
                self::$config[$k] = $v;
            }
        }
    }

    /**
     * Dizge tabanlı değerleri uygun tipe dönüştürür
     */
    private static function cast_value(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $lower = strtolower(trim($value));
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }
        if ($lower === 'null' || $lower === '(null)') {
            return null;
        }
        if (is_numeric($value)) {
            // 1.0 -> float, 1 -> int
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }
        // JSON heuri: {, [, "
        $first = $value[0] ?? '';
        if ($first === '{' || $first === '[') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }
}