<?php

namespace nsql\security;

use nsql\database\security\SensitiveDataFilter;
use nsql\database\Config;
use nsql\database\Nsql;

class SecurityManager
{
    private RateLimiter $rate_limiter;
    private SensitiveDataFilter $data_filter;
    private Encryption $encryption;
    private AuditLogger $audit_logger;

    /**
     * @param Nsql|null $db check_rate_limit() için gerekli (rate_limits tablosu bu bağlantıda tutulur)
     */
    public function __construct(?Nsql $db = null)
    {
        $this->rate_limiter = new RateLimiter($db);
        $this->data_filter = new SensitiveDataFilter();
        $this->encryption = new Encryption();
        $this->audit_logger = new AuditLogger();
    }

    /**
     * Güvenli oturum başlatma.
     *
     * @deprecated 1.5.30 `Nsql::secure_session_start()` / `SessionManager` kullanın; bu metot ona delege eder.
     */
    public static function secure_session_start(array $config = []): void
    {
        Nsql::secure_session_start($config);
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
     * İstemci IP adresi. Proxy başlıkları yalnızca REMOTE_ADDR, TRUSTED_PROXIES
     * listesindeyse dikkate alınır (bkz. ip_resolver).
     *
     * @return string İstemci IP adresi veya 'unknown'
     */
    public static function get_client_ip(): string
    {
        return IpResolver::from_config()->client_ip();
    }

    /**
     * HTTPS kontrolü. X-Forwarded-Proto / X-Forwarded-Ssl yalnızca güvenilir proxy'den gelirse dikkate alınır.
     */
    public static function is_https(): bool
    {
        return IpResolver::from_config()->is_https();
    }

    /**
     * Benzersiz CSRF token üretir ve oturuma kaydeder
     */
    public static function generate_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return SessionManager::get_csrf_token();
    }

    /**
     * CSRF token doğrulaması yapar
     *
     * @param bool $consume true ise başarılı doğrulamadan sonra token yenilenir (tek kullanımlık)
     */
    public static function validate_csrf_token(mixed $token, bool $consume = false): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return SessionManager::validate_csrf_token($token, $consume);
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
     * Girdiyi Validator kurallarıyla doğrular
     */
    public static function validate_input(mixed $value, array $rules): bool
    {
        return \nsql\database\validation\Validator::validate($value, $rules);
    }

    /**
     * Birden fazla input'u validate eder
     */
    public static function validate_inputs(array $data, array $rules): array
    {
        return \nsql\database\validation\Validator::validate_many($data, $rules);
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
            if (Config::get('security_strict_mode', false)) {
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

        $path = Config::resolve_log_path($log_file);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Hassas verileri maskeler
     */
    private static function mask_sensitive_data(array $data): array
    {
        return SensitiveDataFilter::mask_array($data);
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
                if (Config::get('security_strict_mode', false)) {
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
            if (Config::get('security_strict_mode', false)) {
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
     */
    public static function validate_sql_params(array $params): array
    {
        $validated_params = [];
        foreach ($params as $key => $value) {
            // Validator kullanarak SQL parametre validation
            if (!\nsql\database\validation\Validator::validate_sql_param($value)) {
                throw new \InvalidArgumentException("Geçersiz parametre tipi: $key (array/object/resource kullanılamaz)");
            }

            if (is_string($value)) {
                // Potansiyel tehlikeli kalıplar (mutasyon yok, sadece tespit)
                $danger = (bool)preg_match('/(--|;|\/\*|\*\/|xp_|INTO\s+OUTFILE|UNION\s+SELECT)/i', $value);
                if ($danger) {
                    if (Config::get('security_strict_mode', false)) {
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
    public function check_rate_limit(string $identifier, string $request_type = 'default'): bool
    {
        return $this->rate_limiter->check_rate_limit($identifier, $request_type);
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
        $logger = new AuditLogger();
        $logger->log_security_event($event_type, $description, $context, $severity);
    }
}
