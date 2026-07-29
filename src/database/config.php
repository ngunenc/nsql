<?php

namespace nsql\database;

/**
 * config
 *
 * Basit bir yapılandırma yöneticisi.
 * - .env dosyasını otomatik yükler (harici paket gerekmez)
 * - Proje kökü: NSQL_PROJECT_ROOT, ardından getcwd()/.env, sonra config.php üzerinden yukarı yürüyerek .env aranır
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
    /** @var string|null Uygulama kökü (set_project_root veya tespit) */
    private static ?string $project_root_override = null;

    // Sık kullanılan varsayılan sabitler
    public const default_chunk_size = 1000;

    // PDO / bağlantı
    public const connection_timeout = 5;
    public const persistent_connection = false;

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
     * Env / config anahtar eşlemeleri (canonical => alternatifler).
     * Örn. DB_MIN_CONNECTIONS ↔ MIN_CONNECTIONS
     *
     * @var array<string, list<string>>
     */
    private const KEY_ALIASES = [
        'MIN_CONNECTIONS' => ['DB_MIN_CONNECTIONS'],
        'MAX_CONNECTIONS' => ['DB_MAX_CONNECTIONS'],
        'HEALTH_CHECK_INTERVAL' => ['DB_HEALTH_CHECK_INTERVAL'],
        'CONNECTION_TIMEOUT' => ['DB_CONNECTION_TIMEOUT'],
        'CONNECTION_IDLE_TIMEOUT' => ['DB_CONNECTION_IDLE_TIMEOUT'],
        'POOL_LOG_FILE' => ['DB_POOL_LOG_FILE'],
        'READ_WRITE_SPLIT' => ['DB_READ_WRITE_SPLIT'],
        'MAX_RETRY_ATTEMPTS' => ['DB_MAX_RETRY_ATTEMPTS'],
        'CLEANUP_PROBABILITY' => ['DB_CLEANUP_PROBABILITY'],
        'MAX_FAILED_CONNECTIONS' => ['DB_MAX_FAILED_CONNECTIONS'],
    ];

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

        foreach (self::resolve_keys($key) as $candidate) {
            if (array_key_exists($candidate, self::$config)) {
                return self::$config[$candidate];
            }

            $env_value = getenv($candidate);
            if ($env_value !== false) {
                $value = self::cast_value($env_value);
                self::$config[$candidate] = $value;

                return $value;
            }
        }

        return $default;
    }

    /**
     * Yapılandırma değerini ayarlar (runtime override)
     */
    public static function set(string $key, mixed $value): void
    {
        $canonical = self::canonical_key($key);

        foreach (self::KEY_ALIASES as $canon => $aliases) {
            if ($canonical === $canon || in_array($canonical, $aliases, true)) {
                self::$config[$canon] = $value;
                foreach ($aliases as $alias) {
                    self::$config[$alias] = $value;
                }

                return;
            }
        }

        self::$config[$canonical] = $value;
    }

    /** Ortam değişkeni mevcut mu? */
    public static function has(string $key): bool
    {
        self::ensure_bootstrapped();

        foreach (self::resolve_keys($key) as $candidate) {
            if (array_key_exists($candidate, self::$config) || getenv($candidate) !== false) {
                return true;
            }
        }

        return false;
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

    /**
     * .env ve göreli yollar için proje kök dizinini sabitler (ör. index.php içinde `__DIR__`).
     * Çağrılmazsa NSQL_PROJECT_ROOT, getcwd() veya vendor üstünde .env aranır.
     */
    public static function set_project_root(?string $path): void
    {
        self::$env_loaded = false;
        self::$config = [];
        self::$project_root = null;
        if ($path === null || trim($path) === '') {
            self::$project_root_override = null;

            return;
        }
        $trimmed = rtrim(trim($path), '/\\');
        $real = realpath($trimmed);
        self::$project_root_override = $real !== false ? $real : $trimmed;
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
        self::normalize_aliases();
        self::apply_defaults();
        self::detect_and_set_environment();
        self::$env_loaded = true;
    }

    /** Proje kök dizinini bulur (.env dosyasının aranacağı dizin) */
    private static function detect_project_root(): string
    {
        if (self::$project_root_override !== null) {
            return self::prefer_application_root(self::$project_root_override);
        }

        $fromEnv = getenv('NSQL_PROJECT_ROOT');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            $base = rtrim(trim($fromEnv), '/\\');
            if (is_dir($base)) {
                return self::prefer_application_root($base);
            }
        }

        $cwd = getcwd();
        if ($cwd !== false) {
            $fromCwd = self::find_application_root_upwards($cwd);
            if ($fromCwd !== null) {
                return $fromCwd;
            }
        }

        // Paket içinden yukarı yürü (src/database → …); vendor/ngunenc/nsql atlanır
        $fromPackage = self::find_application_root_upwards(__DIR__);
        if ($fromPackage !== null) {
            return $fromPackage;
        }

        $fallback = dirname(__DIR__, 2);
        if ($fallback === '') {
            return $cwd !== false ? $cwd : __DIR__;
        }

        return self::prefer_application_root($fallback);
    }

    /**
     * Composer vendor paket yolu mu? (…/vendor/vendorName/packageName)
     */
    public static function is_vendor_package_path(string $path): bool
    {
        $normalized = str_replace('\\', '/', rtrim($path, '/\\'));

        return (bool) preg_match('#(?:^|/)vendor/[^/]+/[^/]+(?:/|$)#', $normalized);
    }

    /**
     * vendor/vendor/package altındaysa uygulama köküne (vendor'ın üstü) çıkar.
     */
    public static function resolve_away_from_vendor(string $path): string
    {
        $normalized = str_replace('\\', '/', rtrim($path, '/\\'));
        if (preg_match('#^(.*?)/vendor/[^/]+/[^/]+#', $normalized, $matches)) {
            $appRoot = $matches[1];
            if ($appRoot !== '') {
                return str_replace('/', DIRECTORY_SEPARATOR, $appRoot);
            }
        }

        return rtrim($path, '/\\');
    }

    /**
     * Vendor paket köküyse uygulama köküne taşı; aksi halde olduğu gibi bırak.
     */
    public static function prefer_application_root(string $path): string
    {
        $path = rtrim($path, '/\\');
        if (self::is_vendor_package_path($path)) {
            return self::resolve_away_from_vendor($path);
        }

        return $path;
    }

    /**
     * Yukarı doğru uygulama kökü ara (.env veya composer.json + vendor/autoload.php).
     * Composer paket dizinleri aday olarak reddedilir.
     */
    private static function find_application_root_upwards(string $start): ?string
    {
        $dir = rtrim($start, '/\\');
        for ($i = 0; $i < 16; $i++) {
            if (! self::is_vendor_package_path($dir)) {
                $hasEnv = is_file($dir . DIRECTORY_SEPARATOR . '.env');
                $hasComposerApp = is_file($dir . DIRECTORY_SEPARATOR . 'composer.json')
                    && is_file($dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');

                if ($hasEnv || $hasComposerApp) {
                    return $dir;
                }
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /** .env dosyasını okuyup self::$config içine yükler (stream-based okuma ile optimize edilmiş) */
    private static function load_env_file(string $env_path): void
    {
        if (! is_file($env_path) || ! is_readable($env_path)) {
            return;
        }

        // Stream-based okuma (büyük dosyalar için memory-friendly)
        $handle = @fopen($env_path, 'r');
        if ($handle === false) {
            return;
        }

        try {
            $line_number = 0;
            $max_lines = 10000; // Güvenlik: maksimum satır sayısı (dosya boyutu kontrolü)
            
            while (($line = fgets($handle)) !== false && $line_number < $max_lines) {
                $line_number++;
                $line = trim($line);
                
                // Boş satır veya yorum satırı
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
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || 
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                self::$config[$key] = self::cast_value($value);
            }
            
            // Maksimum satır sayısı aşıldıysa uyar
            if ($line_number >= $max_lines) {
                error_log("Config: .env dosyası çok büyük (max {$max_lines} satır), kalan satırlar okunmadı");
            }
        } finally {
            fclose($handle);
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

    /** Varsayılanları uygular (sadece set edilmemişse) — anahtarlar UPPER_SNAKE */
    private static function apply_defaults(): void
    {
        foreach (self::default_values() as $k => $v) {
            if (! self::has_any_key($k)) {
                self::$config[$k] = $v;
            }
        }
    }

    /**
     * Tek doğruluk kaynağı: env/config varsayılanları (class constant'larla hizalı).
     *
     * @return array<string, mixed>
     */
    public static function default_values(): array
    {
        return [
            // DB
            'DB_HOST' => 'localhost',
            'DB_PORT' => 3306,
            'DB_NAME' => 'nsql',
            'DB_USER' => 'root',
            'DB_PASS' => '',
            'DB_CHARSET' => 'utf8mb4',
            'DB_DRIVER' => 'mysql',

            // PDO/options
            'CONNECTION_TIMEOUT' => self::connection_timeout,
            'PERSISTENT_CONNECTION' => self::persistent_connection,

            // Connection pool
            'HEALTH_CHECK_INTERVAL' => self::health_check_interval,
            'CONNECTION_IDLE_TIMEOUT' => self::connection_idle_timeout,
            'MIN_CONNECTIONS' => self::min_connections,
            'MAX_CONNECTIONS' => self::max_connections,
            'MAX_RETRY_ATTEMPTS' => self::max_retry_attempts,
            'CLEANUP_PROBABILITY' => self::cleanup_probability,
            'MAX_FAILED_CONNECTIONS' => self::max_failed_connections,

            // Debug & Log
            'DEBUG_MODE' => false,
            'LOG_FILE' => 'error_log.txt',
            'AUDIT_LOG_FILE' => 'audit_log.txt',
            'LOG_DIR' => null,
            'LOG_MAX_SIZE' => 1048576,

            // Cache
            'STATEMENT_CACHE_LIMIT' => self::statement_cache_limit,
            'QUERY_CACHE_ENABLED' => self::query_cache_enabled,
            'QUERY_CACHE_TIMEOUT' => self::query_cache_timeout,
            'QUERY_CACHE_SIZE_LIMIT' => self::query_cache_size_limit,
            'CACHE_CLEANUP_PROBABILITY' => self::cache_cleanup_probability,

            // Performans
            'LARGE_RESULT_WARNING' => self::large_result_warning,
            'MAX_RESULT_SET_SIZE' => self::max_result_set_size,
            'MEMORY_CHECK_INTERVAL' => self::memory_check_interval,
            'MEMORY_LIMIT_WARNING' => self::memory_limit_warning,
            'MEMORY_LIMIT_CRITICAL' => self::memory_limit_critical,
            'AUTO_ADJUST_CHUNK_SIZE' => self::auto_adjust_chunk_size,
            'MIN_CHUNK_SIZE' => self::min_chunk_size,
            'MAX_CHUNK_SIZE' => self::max_chunk_size,

            // Rate limiting
            'RATE_LIMIT_DECAY' => self::rate_limit_decay,
            'RATE_LIMIT_BURST' => self::rate_limit_burst,
            'RATE_LIMIT_WINDOW' => self::rate_limit_window,
            'RATE_LIMIT_MAX_REQUESTS' => self::rate_limit_max_requests,

            // Güvenlik
            'SECURITY_STRICT_MODE' => false,
        ];
    }

    /**
     * DB_* pool anahtarlarını canonical anahtarlara kopyalar.
     */
    private static function normalize_aliases(): void
    {
        foreach (self::KEY_ALIASES as $canonical => $aliases) {
            if (array_key_exists($canonical, self::$config)) {
                continue;
            }
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, self::$config)) {
                    self::$config[$canonical] = self::$config[$alias];
                    break;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function resolve_keys(string $key): array
    {
        $canonical = self::canonical_key($key);
        $keys = [$canonical];

        if (isset(self::KEY_ALIASES[$canonical])) {
            foreach (self::KEY_ALIASES[$canonical] as $alias) {
                $keys[] = $alias;
            }
        }

        // Ters yön: DB_MIN_CONNECTIONS istendiğinde MIN_CONNECTIONS de dene
        foreach (self::KEY_ALIASES as $canon => $aliases) {
            if (in_array($canonical, $aliases, true) && ! in_array($canon, $keys, true)) {
                $keys[] = $canon;
            }
        }

        return $keys;
    }

    private static function canonical_key(string $key): string
    {
        return strtoupper(trim($key));
    }

    private static function has_any_key(string $canonical): bool
    {
        foreach (self::resolve_keys($canonical) as $candidate) {
            if (array_key_exists($candidate, self::$config) || getenv($candidate) !== false) {
                return true;
            }
        }

        return false;
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