<?php

namespace nsql\database;

use PDO;
use RuntimeException;

class connection_pool
{
    private static array $connections = [];
    private static array $active_connections = [];
    private static array $idle_connections = [];
    private static array $configuration;
    private static bool $initialized = false;
    private static ?int $last_health_check = null;
    private static array $retry_counts = [];
    
    // Dinamik tuning için değişkenler
    private static int $current_min_connections;
    private static int $current_max_connections;
    private static int $adaptive_health_check_interval;
    private static array $load_history = []; // Son 10 dakikalık yük geçmişi (circular buffer)
    private static int $load_history_index = 0; // Circular buffer için index
    private static int $load_history_size = 0; // Dolu eleman sayısı
    private static int $last_load_check = 0;
    private static float $current_load_factor = 0.0; // 0.0 - 1.0 arası yük faktörü
    private const MAX_LOAD_HISTORY_ENTRIES = 60; // 10 dakika * 10 saniye = 60 entry (circular buffer boyutu)
    
    // Thread safety için lock mekanizması
    private static ?string $lock_file = null;
    private static ?resource $lock_handle = null;
    private const LOCK_TIMEOUT = 5; // Saniye cinsinden lock timeout (config'den alınabilir ama constant olarak bırakıldı - kritik güvenlik değeri)

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
        'slow_queries' => 0,
        'pool_adjustments' => 0, // Dinamik ayarlama sayısı
        'health_check_interval_adjustments' => 0, // Health check interval ayarlama sayısı
    ];

    /**
     * Connection Pool'u başlatır
     */
    public static function initialize(array $config, int $min_connections = 5, int $max_connections = 20): void
    {
        if (self::$initialized) {
            return;
        }

        self::validate_configuration($config);
        self::$configuration = $config;
        
        // Lock dosyasını hazırla
        self::initialize_lock();
        
        // Dinamik tuning için başlangıç değerleri
        self::$current_min_connections = $min_connections;
        self::$current_max_connections = $max_connections;
        self::$adaptive_health_check_interval = config::health_check_interval;
        self::$last_load_check = time();

        // Başlangıç bağlantılarını oluştur
        for ($i = 0; $i < $min_connections; $i++) {
            self::create_connection();
        }

        self::$initialized = true;
        self::$last_health_check = time();
    }
    
    /**
     * Lock dosyasını başlatır
     */
    private static function initialize_lock(): void
    {
        if (self::$lock_file !== null) {
            return;
        }
        
        // Lock dosyası için geçici dizin kullan
        $lock_dir = sys_get_temp_dir();
        $lock_file = $lock_dir . DIRECTORY_SEPARATOR . 'nsql_connection_pool.lock';
        
        // Lock dosyasını oluştur (yoksa)
        if (!file_exists($lock_file)) {
            touch($lock_file);
            chmod($lock_file, 0666); // Read/write for all (lock dosyası için yeterli)
        }
        
        self::$lock_file = $lock_file;
    }
    
    /**
     * Lock alır (exclusive lock)
     * 
     * @param int $timeout Timeout süresi (saniye)
     * @return bool Lock alındıysa true
     */
    private static function acquire_lock(int $timeout = self::LOCK_TIMEOUT): bool
    {
        if (self::$lock_file === null) {
            self::initialize_lock();
        }
        
        // Lock handle'ı aç
        if (self::$lock_handle === null) {
            self::$lock_handle = fopen(self::$lock_file, 'r+');
            if (self::$lock_handle === false) {
                return false;
            }
        }
        
        $start_time = time();
        
        // Non-blocking lock dene
        while (true) {
            if (flock(self::$lock_handle, LOCK_EX | LOCK_NB)) {
                return true;
            }
            
            // Timeout kontrolü
            if ((time() - $start_time) >= $timeout) {
                return false;
            }
            
            // Kısa bir bekleme (10ms)
            usleep(10000);
        }
    }
    
    /**
     * Lock'u serbest bırakır
     */
    private static function release_lock(): void
    {
        if (self::$lock_handle !== null) {
            flock(self::$lock_handle, LOCK_UN);
            // Handle'ı kapatma, tekrar kullanılabilir
        }
    }

    /**
     * Sağlık kontrolü yapar (optimize edilmiş, adaptive interval ile)
     */
    private static function perform_health_check(): void
    {
        $now = time();

        // Adaptive health check interval kullan
        if (self::$last_health_check !== null &&
            ($now - self::$last_health_check) < self::$adaptive_health_check_interval) {
            return;
        }

        self::$last_health_check = $now;
        self::$stats['health_checks']++;
        
        // Yük faktörünü güncelle ve adaptive interval'ı ayarla
        self::update_load_factor();
        self::adjust_health_check_interval();

        // Sadece aktif olmayan bağlantıları kontrol et (performans optimizasyonu)
        $connections_to_check = array_diff_key(self::$connections, self::$active_connections);
        
        foreach ($connections_to_check as $key => $conn) {
            if (! self::is_connection_valid($conn)) {
                self::$stats['failed_health_checks']++;
                unset(self::$connections[$key], self::$active_connections[$key]);
                
                // Dinamik minimum bağlantı sayısını kullan
                if (count(self::$connections) < self::$current_min_connections) {
                    self::create_connection();
                }
            }
        }

        // Boşta kalan bağlantıları yönet
        self::manage_idle_connections();
        
        // Dinamik pool tuning
        self::adjust_pool_size();

        // Timeout olan bağlantıları temizle (daha az sıklıkta)
        if (rand(1, 100) <= config::cleanup_probability) {
            self::cleanup_stale_connections();
        }
    }

    /**
     * Boşta kalan bağlantıları yönetir
     */
    private static function manage_idle_connections(): void
    {
        $now = time();

        foreach (self::$connections as $key => $conn) {
            if (! isset(self::$active_connections[$key])) {
                if (! isset(self::$idle_connections[$key])) {
                    self::$idle_connections[$key] = $now;
                } elseif (($now - self::$idle_connections[$key]) > config::connection_idle_timeout) {
                    // Boşta kalma süresi aşıldıysa ve dinamik minimum bağlantı sayısının üzerindeyse kapat
                    if (count(self::$connections) > self::$current_min_connections) {
                        unset(self::$connections[$key], self::$idle_connections[$key]);
                        self::$stats['idle_connections']--;
                    }
                }
            }
        }
    }
    
    /**
     * Yük faktörünü günceller (load-based tuning için)
     * Optimize edilmiş: Circular buffer kullanarak memory leak önlenir
     */
    private static function update_load_factor(): void
    {
        $now = time();
        $total_connections = count(self::$connections);
        $active_connections = count(self::$active_connections);
        
        if ($total_connections === 0) {
            self::$current_load_factor = 0.0;
            return;
        }
        
        // Aktif bağlantı oranı (0.0 - 1.0)
        $active_ratio = $active_connections / $total_connections;
        
        // Circular buffer kullanarak yük geçmişine ekle
        $entry = [
            'timestamp' => $now,
            'active_ratio' => $active_ratio,
            'total_connections' => $total_connections,
            'active_connections' => $active_connections,
        ];
        
        // Circular buffer'a ekle
        if (self::$load_history_size < self::MAX_LOAD_HISTORY_ENTRIES) {
            // Henüz dolu değilse sona ekle
            self::$load_history[] = $entry;
            self::$load_history_size++;
        } else {
            // Doluysa eski entry'yi üzerine yaz (circular buffer)
            self::$load_history[self::$load_history_index] = $entry;
            self::$load_history_index = (self::$load_history_index + 1) % self::MAX_LOAD_HISTORY_ENTRIES;
        }
        
        // 10 dakikadan eski kayıtları filtrele (timestamp kontrolü)
        $valid_entries = [];
        foreach (self::$load_history as $entry) {
            if (($now - $entry['timestamp']) < 600) {
                $valid_entries[] = $entry;
            }
        }
        
        // Ortalama yük faktörünü hesapla
        if (!empty($valid_entries)) {
            $avg_active_ratio = array_sum(array_column($valid_entries, 'active_ratio')) / count($valid_entries);
            self::$current_load_factor = min(1.0, max(0.0, $avg_active_ratio));
        } else {
            self::$current_load_factor = $active_ratio;
        }
    }
    
    /**
     * Adaptive health check interval'ı ayarlar (GELISTIRME-002)
     */
    private static function adjust_health_check_interval(): void
    {
        $base_interval = config::health_check_interval;
        $min_interval = 30; // Minimum 30 saniye
        $max_interval = 300; // Maximum 5 dakika
        
        // Yük faktörüne göre interval'ı ayarla
        // Yüksek yük → daha sık kontrol (küçük interval)
        // Düşük yük → daha seyrek kontrol (büyük interval)
        if (self::$current_load_factor > 0.8) {
            // Yüksek yük: interval'ı azalt (daha sık kontrol)
            $new_interval = max($min_interval, (int)($base_interval * 0.5));
        } elseif (self::$current_load_factor > 0.5) {
            // Orta yük: normal interval
            $new_interval = $base_interval;
        } else {
            // Düşük yük: interval'ı artır (daha seyrek kontrol)
            $new_interval = min($max_interval, (int)($base_interval * 1.5));
        }
        
        // Interval değiştiyse güncelle
        if ($new_interval !== self::$adaptive_health_check_interval) {
            self::$adaptive_health_check_interval = $new_interval;
            self::$stats['health_check_interval_adjustments']++;
        }
    }
    
    /**
     * Pool size'ı dinamik olarak ayarlar (GELISTIRME-001)
     */
    private static function adjust_pool_size(): void
    {
        $total_connections = count(self::$connections);
        $active_connections = count(self::$active_connections);
        $base_min = config::min_connections;
        $base_max = config::max_connections;
        
        // Yük faktörüne göre min/max connection'ları ayarla
        if (self::$current_load_factor > 0.8) {
            // Yüksek yük: pool size'ı artır
            $new_min = min($base_max, (int)($base_min * 1.5));
            $new_max = min($base_max * 2, (int)($base_max * 1.5));
        } elseif (self::$current_load_factor > 0.5) {
            // Orta yük: normal pool size
            $new_min = $base_min;
            $new_max = $base_max;
        } else {
            // Düşük yük: pool size'ı azalt (kaynak tasarrufu)
            $new_min = max(1, (int)($base_min * 0.75));
            $new_max = max($new_min + 1, (int)($base_max * 0.75));
        }
        
        // Min/Max değerleri güncelle
        $min_changed = false;
        $max_changed = false;
        
        if ($new_min !== self::$current_min_connections) {
            self::$current_min_connections = $new_min;
            $min_changed = true;
        }
        
        if ($new_max !== self::$current_max_connections) {
            self::$current_max_connections = $new_max;
            $max_changed = true;
        }
        
        // Eğer ayarlama yapıldıysa istatistikleri güncelle
        if ($min_changed || $max_changed) {
            self::$stats['pool_adjustments']++;
            
            // Minimum bağlantı sayısının altındaysa yeni bağlantılar oluştur
            if ($total_connections < self::$current_min_connections) {
                $needed = self::$current_min_connections - $total_connections;
                for ($i = 0; $i < $needed && $total_connections + $i < self::$current_max_connections; $i++) {
                    self::create_connection();
                }
            }
        }
    }

    /**
     * Havuzdan bir bağlantı alır
     * @throws RuntimeException Bağlantı alınamazsa
     */
    public static function get_connection(): \PDO
    {
        if (! self::$initialized) {
            throw new \RuntimeException('Connection pool başlatılmamış');
        }

        // Lock al (thread safety için)
        if (!self::acquire_lock()) {
            throw new \RuntimeException('Connection pool lock alınamadı (timeout)');
        }
        
        try {
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
        } finally {
            // Lock'u her durumda serbest bırak
            self::release_lock();
        }
    }

    /**
     * Bağlantı alma denemesi yapar
     * Not: Bu metod lock içinde çağrılmalıdır
     */
    private static function try_get_connection(int $attempt = 0): ?\PDO
    {
        try {
            // Aktif bağlantı kontrolü
            foreach (self::$connections as $key => $conn) {
                if (! isset(self::$active_connections[$key])) {
                    if (self::is_connection_valid($conn)) {
                        self::$active_connections[$key] = time();
                        unset(self::$idle_connections[$key]);
                        self::$stats['active_connections']++;

                        return $conn;
                    }
                }
            }

            // Yeni bağlantı oluştur (dinamik max_connections kullan)
            if (count(self::$connections) < self::$current_max_connections) {
                return self::create_connection();
            }

            // Tüm bağlantılar kullanımda, timeout kontrolü yap
            self::cleanup_stale_connections();

            return null;
        } catch (\PDOException $e) {
            if ($attempt < config::max_retry_attempts) {
                self::$stats['connection_retries']++;
                sleep(1); // Kısa bir bekleme

                return self::try_get_connection($attempt + 1);
            }

            throw new \RuntimeException('Bağlantı hatası: ' . $e->getMessage());
        }
    }

    /**
     * Yeni bağlantı oluşturur
     * Not: Bu metod lock içinde çağrılmalıdır
     */
    private static function create_connection(): \PDO
    {
        try {
            // Varsayılan PDO seçeneklerini ayarla
            $default_options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => 0, // PHP 8.4 için int gerekiyor
                \PDO::ATTR_TIMEOUT => (int)config::get('connection_timeout', 5),
                \PDO::ATTR_PERSISTENT => (int)(bool)config::get('persistent_connection', false),
            ];

            $final_options = $default_options;

            // Özel yapılandırma seçeneklerini ekle
            if (isset(self::$configuration['options']) && is_array(self::$configuration['options'])) {
                foreach (self::$configuration['options'] as $key => $value) {
                    // PDO sabitlerini doğrula ve güvenli şekilde ayarla
                    if (is_string($key) && strpos($key, 'ATTR_') === 0) {
                        $attr_name = substr($key, 5); // ATTR_ kısmını kaldır
                        if (defined("\\PDO::ATTR_$attr_name")) {
                            $pdo_key = constant("\\PDO::ATTR_$attr_name");
                            $final_options[$pdo_key] = $value;
                        }
                    } else {
                        // Sayısal anahtar veya doğrudan PDO sabiti
                        if (is_int($key) && defined("\\PDO::ATTR_$value")) {
                            $pdo_key = constant("\\PDO::ATTR_$value");
                            $final_options[$pdo_key] = true;
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
            $actual_err_mode = $conn->getAttribute(\PDO::ATTR_ERRMODE);
            if ($actual_err_mode !== \PDO::ERRMODE_EXCEPTION) {
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
    private static function is_connection_valid(\PDO $connection): bool
    {
        try {
            return $connection->query('SELECT 1') !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Timeout olan bağlantıları temizler
     */
    private static function cleanup_stale_connections(): void
    {
        $now = time();

        // Her istekte belirli bir olasılıkla temizlik yap
        if (mt_rand(1, 100) > config::cleanup_probability) {
            return;
        }

        // Aktif bağlantıları kontrol et
        foreach (self::$active_connections as $key => $timestamp) {
            // Timeout kontrolü
            if (($now - $timestamp) > config::get('connection_timeout', 5)) {
                unset(self::$connections[$key], self::$active_connections[$key]);
                self::$stats['connection_timeouts']++;
                self::$stats['active_connections']--;

                continue;
            }

            // Bağlantı geçerliliğini kontrol et
            if (! isset(self::$connections[$key]) || ! self::is_connection_valid(self::$connections[$key])) {
                unset(self::$connections[$key], self::$active_connections[$key]);
                self::$stats['failed_health_checks']++;
                self::$stats['active_connections']--;
            }
        }

        // Boşta kalan bağlantıları kontrol et
        foreach (self::$idle_connections as $key => $timestamp) {
                // Boşta kalma süresi kontrolü
            if (($now - $timestamp) > config::connection_idle_timeout) {
                // Dinamik minimum bağlantı sayısını koru
                if (count(self::$connections) > self::$current_min_connections) {
                    unset(self::$connections[$key], self::$idle_connections[$key]);
                    self::$stats['idle_connections']--;
                }

                continue;
            }

            // Bağlantı geçerliliğini kontrol et
            if (! isset(self::$connections[$key]) || ! self::is_connection_valid(self::$connections[$key])) {
                unset(self::$connections[$key], self::$idle_connections[$key]);
                self::$stats['failed_health_checks']++;
                self::$stats['idle_connections']--;
            }
        }

        // Yeterli aktif bağlantı yoksa yeni bağlantılar oluştur (dinamik min_connections kullan)
        $total_connections = count(self::$connections);
        if ($total_connections < self::$current_min_connections) {
            $needed = self::$current_min_connections - $total_connections;
            for ($i = 0; $i < $needed && ($total_connections + $i) < self::$current_max_connections; $i++) {
                self::create_connection();
            }
        }

        // Başarısız denemelerini sıfırla
        self::$retry_counts = array_filter(
            self::$retry_counts,
            fn ($count) => $count < config::max_failed_connections
        );
    }

    /**
     * Yapılandırmayı doğrular
     */
    private static function validate_configuration(array $config): void
    {
        $required = ['dsn', 'username', 'password', 'options'];

        foreach ($required as $key) {
            if (! isset($config[$key])) {
                throw new \InvalidArgumentException("Eksik yapılandırma parametresi: $key");
            }
        }
    }

    /**
     * Pool istatistiklerini döndürür
     */
    public static function get_stats(): array
    {
        return array_merge(self::$stats, [
            'current_connections' => count(self::$connections),
            'idle_connections' => count(self::$connections) - count(self::$active_connections),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            // Dinamik tuning istatistikleri
            'current_min_connections' => self::$current_min_connections ?? config::min_connections,
            'current_max_connections' => self::$current_max_connections ?? config::max_connections,
            'adaptive_health_check_interval' => self::$adaptive_health_check_interval ?? config::health_check_interval,
            'current_load_factor' => self::$current_load_factor,
            'load_history_size' => count(self::$load_history),
        ]);
    }

    /**
     * Bağlantıyı havuza geri bırakır
     */
    public static function release_connection(\PDO $connection): void
    {
        // Lock al (thread safety için)
        if (!self::acquire_lock()) {
            // Lock alınamazsa logla ama işlemi devam ettir (best effort)
            error_log('Connection pool: Lock alınamadı release_connection sırasında');
            return;
        }
        
        try {
            $key = spl_object_hash($connection);

            // Bağlantı zaten havuzda değilse işlem yapma
            if (! isset(self::$connections[$key])) {
                return;
            }

            // Bağlantının geçerli olduğunu kontrol et
            if (! self::is_connection_valid($connection)) {
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
        } finally {
            // Lock'u her durumda serbest bırak
            self::release_lock();
        }
    }

    /**
     * Tüm bağlantıları kapatır
     */
    public static function close_all(): void
    {
        // Lock al (thread safety için)
        if (!self::acquire_lock()) {
            error_log('Connection pool: Lock alınamadı close_all sırasında');
            return;
        }
        
        try {
            foreach (self::$connections as $key => $conn) {
                unset(self::$connections[$key]);
                unset(self::$active_connections[$key]);
                unset(self::$idle_connections[$key]);
            }
            self::$stats['active_connections'] = 0;
            self::$stats['idle_connections'] = 0;
        } finally {
            // Lock'u serbest bırak
            self::release_lock();
            
            // Lock handle'ı kapat
            if (self::$lock_handle !== null) {
                fclose(self::$lock_handle);
                self::$lock_handle = null;
            }
        }
    }
}
