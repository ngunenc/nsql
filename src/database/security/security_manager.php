<?php

namespace nsql\database\security;

use nsql\database\config;

class security_manager
{
    private rate_limiter $rate_limiter;
    private sensitive_data_filter $data_filter;
    private encryption $encryption;
    private audit_logger $audit_logger;

    public function __construct()
    {
        $this->rate_limiter = new rate_limiter();
        $this->data_filter = new sensitive_data_filter();
        $this->encryption = new encryption();
        $this->audit_logger = new audit_logger();
    }

    /**
     * Güvenli oturum başlatma ve cookie ayarları
     */
    public static function secure_session_start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // HTTPS kontrolü (proxy/load balancer desteği ile)
            $secure = self::is_https();
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
            if (! isset($_SESSION['initiated'])) {
                session_regenerate_id(true);
                $_SESSION['initiated'] = true;
            }
        }
    }

    /**
     * Oturum ID'sini güvenli şekilde yenile
     */
    public static function regenerate_session_id(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * XSS koruması için HTML çıktısı kaçışlama fonksiyonu
     */
    public static function escape_html(mixed $string): string
    {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Güvenli şekilde istemci IP adresini alır
     * 
     * Proxy, load balancer veya CDN arkasında çalışırken doğru IP'yi döndürür.
     * Öncelik sırası:
     * 1. X-Forwarded-For (ilk IP, güvenilir proxy'ler için)
     * 2. X-Real-IP (nginx ve bazı proxy'ler)
     * 3. CF-Connecting-IP (Cloudflare)
     * 4. REMOTE_ADDR (son çare)
     * 
     * @return string İstemci IP adresi veya 'unknown'
     */
    public static function get_client_ip(): string
    {
        // X-Forwarded-For header'ını kontrol et (proxy/load balancer)
        // Format: "client_ip, proxy1_ip, proxy2_ip"
        $forwarded_for = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!empty($forwarded_for)) {
            // İlk IP'yi al (gerçek istemci IP'si)
            $ips = explode(',', $forwarded_for);
            $ip = trim($ips[0]);
            
            // IP adresini validate et
            if (self::is_valid_ip($ip)) {
                return $ip;
            }
        }
        
        // X-Real-IP header'ını kontrol et (nginx ve bazı proxy'ler)
        $real_ip = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if (!empty($real_ip)) {
            $ip = trim($real_ip);
            if (self::is_valid_ip($ip)) {
                return $ip;
            }
        }
        
        // CF-Connecting-IP header'ını kontrol et (Cloudflare)
        $cf_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (!empty($cf_ip)) {
            $ip = trim($cf_ip);
            if (self::is_valid_ip($ip)) {
                return $ip;
            }
        }
        
        // Son çare: REMOTE_ADDR
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($remote_addr)) {
            $ip = trim($remote_addr);
            if (self::is_valid_ip($ip)) {
                return $ip;
            }
        }
        
        return 'unknown';
    }
    
    /**
     * IP adresinin geçerli olup olmadığını kontrol eder
     * 
     * @param string $ip Kontrol edilecek IP adresi
     * @return bool Geçerli ise true
     */
    private static function is_valid_ip(string $ip): bool
    {
        // IPv4 ve IPv6 kontrolü
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || filter_var($ip, FILTER_VALIDATE_IP) !== false; // Private/reserved range'e de izin ver (local network için)
    }
    
    /**
     * HTTPS bağlantısı kontrolü yapar (proxy/load balancer desteği ile)
     * 
     * @return bool HTTPS ise true
     */
    public static function is_https(): bool
    {
        // X-Forwarded-Proto header'ını kontrol et (proxy/load balancer)
        $forwarded_proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!empty($forwarded_proto)) {
            return strtolower($forwarded_proto) === 'https';
        }
        
        // X-Forwarded-Ssl header'ını kontrol et (bazı proxy'ler)
        $forwarded_ssl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
        if (!empty($forwarded_ssl) && strtolower($forwarded_ssl) === 'on') {
            return true;
        }
        
        // HTTPS server variable'ını kontrol et
        $https = $_SERVER['HTTPS'] ?? '';
        if (!empty($https) && strtolower($https) !== 'off') {
            return true;
        }
        
        // SERVER_PORT kontrolü
        $port = $_SERVER['SERVER_PORT'] ?? null;
        if ($port == 443) {
            return true;
        }
        
        return false;
    }

    /**
     * Benzersiz CSRF token üretir ve oturuma kaydeder
     */
    public static function generate_csrf_token(): string
    {
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
    public static function validate_csrf_token(mixed $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    }

    /**
     * SQL parametreleri için doğrulama (mutasyon yok). Array/objeleri reddeder.
     * Strict mode açık ise şüpheli içerikte istisna atar, değilse audit log'a yazar.
     */
    public static function sanitize_sql_params(array $params): array
    {
        return self::validate_sql_params($params);
    }
    
    /**
     * Input validation için validator kullanır (GELISTIRME-007)
     */
    public static function validate_input(mixed $value, array $rules): bool
    {
        return \nsql\database\validation\validator::validate($value, $rules);
    }
    
    /**
     * Birden fazla input'u validate eder
     */
    public static function validate_inputs(array $data, array $rules): array
    {
        return \nsql\database\validation\validator::validate_many($data, $rules);
    }

    /**
     * Güvenli SQL sorgusu oluşturur (değiştirmez). Tehlikeli kalıpları tespit eder.
     * Strict mode açık: istisna; kapalı: audit log ve devam.
     */
    public static function prepare_safe_query(string $query, array $params): string
    {
        $dangerous_patterns = [
            '/\bUNION\b/i',
            '/\bLOAD_FILE\b/i',
            '/\bINTO\s+OUTFILE\b/i',
            '/\bINTO\s+DUMPFILE\b/i',
            '/;\s*\w+/i', // Çoklu sorgular
        ];

        $matched = false;
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $matched = true;

                break;
            }
        }

        if ($matched) {
            if (config::get('security_strict_mode', false)) {
                throw new \InvalidArgumentException('Tehlikeli SQL kalıbı tespit edildi.');
            }
            self::log_event('sql_pattern_warning', 'Tehlikeli SQL kalıbı tespit edildi', [
                'query' => $query,
            ]);
        }

        return $query;
    }

    /**
     * Debug bilgilerini güvenli şekilde loglar
     */
    public static function log_debug_info(string $message, array $context = [], string $log_file = 'error_log.txt'): void
    {
        // Hassas bilgileri maskele
        $masked_context = self::mask_sensitive_data($context);

        $log_entry = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $message,
            ! empty($masked_context) ? json_encode($masked_context, JSON_UNESCAPED_UNICODE) : ''
        );

        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }

    /**
     * Hassas verileri maskeler
     */
    private static function mask_sensitive_data(array $data): array
    {
        $sensitive_keys = ['password', 'pass', 'secret', 'key', 'token', 'auth'];

        array_walk_recursive($data, function (&$value, $key) use ($sensitive_keys) {
            foreach ($sensitive_keys as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $value = '********';
                }
            }
        });

        return $data;
    }

    /**
     * Prepared statement kullanımını teşvik eder.
     * Strict mode: doğrudan string literal bağlanan kalıplarda istisna; değilse audit log.
     */
    public static function enforce_prepared_statement(string $sql): void
    {
        $patterns = [
            '/VALUES\s*\(\s*[\'\"][^\'\"]*[\'\"]\s*\)/i',  // VALUES ('value')
            '/SET\s+\w+\s*=\s*[\'\"][^\'\"]*[\'\"]/i',      // SET column = 'value'
            '/WHERE\s+\w+\s*=\s*[\'\"][^\'\"]*[\'\"]/i',    // WHERE column = 'value'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                if (config::get('security_strict_mode', false)) {
                    throw new \InvalidArgumentException('Doğrudan değer kullanımı tespit edildi. Prepared statements kullanın.');
                }
                self::log_event('prepared_stmt_warning', 'Doğrudan değer kullanımı tespit edildi', [
                    'sql' => $sql,
                ]);

                break;
            }
        }
    }

    /**
     * SQL enjeksiyon kontrolü
     * Strict mode: istisna; değilse audit log.
     */
    public static function check_query_security(string $sql): void
    {
        $dangerous_patterns = [
            '/;\s*DROP/i',
            '/;\s*DELETE/i',
            '/;\s*UPDATE/i',
            '/;\s*INSERT/i',
            '/UNION\s+ALL/i',
            '/UNION\s+SELECT/i',
        ];

        $matched = false;
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                $matched = true;

                break;
            }
        }

        if ($matched) {
            if (config::get('security_strict_mode', false)) {
                throw new \RuntimeException('Potansiyel SQL Enjeksiyon tespit edildi!');
            }
            self::log_event('sql_injection_warning', 'Potansiyel SQL Enjeksiyon tespit edildi', [
                'sql' => $sql,
            ]);
        }
    }

    /**
     * Parametrelerin güvenlik kontrolü (mutasyon yok). Diziler/objeler reddedilir.
     * Strict mode: şüpheli param içerikte istisna; değilse audit log.
     * GELISTIRME-007: Validator entegrasyonu ile genişletildi
     */
    public static function validate_sql_params(array $params): array
    {
        $validated_params = [];
        foreach ($params as $key => $value) {
            // Validator kullanarak SQL parametre validation
            if (!\nsql\database\validation\validator::validate_sql_param($value)) {
                throw new \InvalidArgumentException("Geçersiz parametre tipi: $key (array/object/resource kullanılamaz)");
            }

            if (is_string($value)) {
                // Potansiyel tehlikeli kalıplar (mutasyon yok, sadece tespit)
                $danger = (bool)preg_match('/(--|;|\/\*|\*\/|xp_|INTO\s+OUTFILE|UNION\s+SELECT)/i', $value);
                if ($danger) {
                    if (config::get('security_strict_mode', false)) {
                        throw new \InvalidArgumentException('Şüpheli parametre içeriği tespit edildi');
                    }
                    self::log_event('param_suspicious', 'Şüpheli parametre içeriği tespit edildi', [
                        'param' => $key,
                        'value_preview' => mb_substr($value, 0, 64),
                    ]);
                }
            }
            $validated_params[$key] = $value;
        }

        return $validated_params;
    }

    /**
     * Rate limiting kontrolü
     */
    public function check_rate_limit(string $identifier): bool
    {
        return $this->rate_limiter->check_rate_limit($identifier);
    }

    /**
     * Hassas veri filtreleme
     */
    public function filter_sensitive_data(mixed $data): mixed
    {
        return $this->data_filter->filter($data);
    }

    /**
     * Veri şifreleme
     */
    public function encrypt(string $data): string
    {
        return $this->encryption->encrypt($data);
    }

    /**
     * Veri şifre çözme
     */
    public function decrypt(string $encrypted_data): string
    {
        return $this->encryption->decrypt($encrypted_data);
    }

    /**
     * Güvenlik log kaydı (kolaylaştırıcı)
     */
    public function log_security_event(string $event, array $context = []): void
    {
        $this->audit_logger->log_security_event($event, 'Security event', $context, 'info');
    }

    /**
     * Audit log'a yazmak için yardımcı (static kapsamdan erişim için)
     */
    private static function log_event(string $event_type, string $description, array $context = [], string $severity = 'warning'): void
    {
        $logger = new audit_logger();
        $logger->log_security_event($event_type, $description, $context, $severity);
    }
}
