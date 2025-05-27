<?php

namespace nsql\database\security;

class security_manager {
    private rate_limiter $rateLimiter;
    private sensitive_data_filter $dataFilter;
    private encryption $encryption;
    private audit_logger $auditLogger;

    public function __construct() {
        $this->rateLimiter = new rate_limiter();
        $this->dataFilter = new sensitive_data_filter();
        $this->encryption = new encryption();
        $this->auditLogger = new audit_logger();
    }

    /**
     * Güvenli oturum başlatma ve cookie ayarları
     */
    public static function secure_session_start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
            // Session fixation önlemi: yeni oturumda ID yenile
            if (!isset($_SESSION['initiated'])) {
                session_regenerate_id(true);
                $_SESSION['initiated'] = true;
            }
        }
    }

    /**
     * Oturum ID'sini güvenli şekilde yenile
     */
    public static function regenerate_session_id(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * XSS koruması için HTML çıktısı kaçışlama fonksiyonu
     */
    public static function escape_html($string): string {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Benzersiz CSRF token üretir ve oturuma kaydeder
     */
    public static function generate_csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * CSRF token doğrulaması yapar
     */
    public static function validate_csrf_token($token): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * SQL enjeksiyonuna karşı parametre temizleme
     */
    public static function sanitize_sql_params(array $params): array {
        return array_map(function($value) {
            if (is_string($value)) {
                // Temel SQL enjeksiyon karakterlerini temizle
                $value = str_replace(['\\', "\0", "\n", "\r", "'", '"', "\x1a"], '', $value);
                // Ek SQL anahtar kelimelerini engelle
                $value = preg_replace('/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|INTO|LOAD_FILE)\b/i', '', $value);
            }
            return $value;
        }, $params);
    }

    /**
     * Güvenli SQL sorgusu oluşturur
     */
    public static function prepare_safe_query(string $query, array $params): string {
        // SQL anahtar kelimelerini kontrol et
        $dangerous_patterns = [
            '/\bUNION\b/i',
            '/\bLOAD_FILE\b/i',
            '/\bINTO\s+OUTFILE\b/i',
            '/\bINTO\s+DUMPFILE\b/i',
            '/;\s*\w+/i', // Çoklu sorguları engelle
        ];

        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                throw new \InvalidArgumentException('Güvenlik ihlali: Tehlikeli SQL kalıbı tespit edildi.');
            }
        }

        return $query;
    }

    /**
     * Debug bilgilerini güvenli şekilde loglar
     */
    public static function log_debug_info(string $message, array $context = [], string $log_file = 'error_log.txt'): void {
        // Hassas bilgileri maskele
        $masked_context = self::mask_sensitive_data($context);
        
        $log_entry = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $message,
            !empty($masked_context) ? json_encode($masked_context, JSON_UNESCAPED_UNICODE) : ''
        );
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }

    /**
     * Hassas verileri maskeler
     */
    private static function mask_sensitive_data(array $data): array {
        $sensitive_keys = ['password', 'pass', 'secret', 'key', 'token', 'auth'];
        
        array_walk_recursive($data, function(&$value, $key) use ($sensitive_keys) {
            foreach ($sensitive_keys as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $value = '********';
                }
            }
        });
        
        return $data;
    }

    /**
     * SQL sorgusunu prepared statement kontrolü yapar
     */
    public static function enforce_prepared_statement(string $sql): void {
        // Doğrudan değer içeren sorgular için kontrol
        $patterns = [
            '/VALUES\s*\(\s*[\'"][^\'"]*[\'"]\s*\)/i',  // VALUES ('value')
            '/SET\s+\w+\s*=\s*[\'"][^\'"]*[\'"]/',      // SET column = 'value'
            '/WHERE\s+\w+\s*=\s*[\'"][^\'"]*[\'"]/',    // WHERE column = 'value'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw new \InvalidArgumentException(
                    'Güvenlik ihlali: Doğrudan değer kullanımı tespit edildi. ' .
                    'Lütfen prepared statements kullanın.'
                );
            }
        }
    }

    /**
     * SQL enjeksiyon kontrolü
     */
    public static function check_query_security(string $sql): void {
        $dangerousPatterns = [
            '/;\s*DROP/i',
            '/;\s*DELETE/i',
            '/;\s*UPDATE/i',
            '/;\s*INSERT/i',
            '/UNION\s+ALL/i',
            '/UNION\s+SELECT/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw new \RuntimeException('Potansiyel SQL Enjeksiyon tespit edildi!');
            }
        }
    }

    /**
     * Parametrelerin güvenlik kontrolü
     */
    public static function validate_sql_params(array $params): array {
        $validatedParams = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                throw new \InvalidArgumentException('Dizi tipinde parametre kullanılamaz');
            }
            // XSS ve SQL Enjeksiyon önleme
            if (is_string($value)) {
                $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                // SQL Enjeksiyon için özel karakterleri temizle
                $value = str_replace(['--', ';', '/*', '*/', 'xp_'], '', $value);
            }
            $validatedParams[$key] = $value;
        }
        return $validatedParams;
    }

    /**
     * Rate limiting kontrolü
     */
    public function check_rate_limit(string $identifier): bool {
        return $this->rateLimiter->check_rate_limit($identifier);
    }

    /**
     * Hassas veri filtreleme
     */
    public function filter_sensitive_data($data) {
        return $this->dataFilter->filter($data);
    }

    /**
     * Veri şifreleme
     */
    public function encrypt(string $data): string {
        return $this->encryption->encrypt($data);
    }

    /**
     * Veri şifre çözme
     */
    public function decrypt(string $encryptedData): string {
        return $this->encryption->decrypt($encryptedData);
    }

    /**
     * Güvenlik log kaydı
     */
    public function log_security_event(string $event, array $context = []): void {
        $this->auditLogger->log($event, $context);
    }
}
