<?php

namespace nsql\security;

use nsql\database\Config;
use RuntimeException;

class SessionManager
{
    private bool $initialized = false;
    private array $config;

    // Session güvenlik ayarları
    private const session_expiry = 1800; // 30 dakika
    private const regenerate_interval = 300; // saniye
    private const max_requests = 5000;
    private const max_lifetime = 43200; // 12 saat
    public const csrf_token_ttl = 7200; // saniye
    /** Accept-Language dahil edilmez: tarayıcı/istek türüne göre değişir, meşru oturumları düşürür */
    private const fingerprint_fields = [
        'HTTP_USER_AGENT',
        'HTTP_SEC_CH_UA',
        'HTTP_SEC_CH_UA_PLATFORM',
    ];
    private const secure_headers = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
    ];
    private const hsts_default = 'max-age=31536000; includeSubDomains';
    /** regenerate_id() sonrası eski oturum ID'sinin geçerli kaldığı süre (eşzamanlı istekler) */
    private const regenerate_grace = 60;

    /**
     * @param array $config
     *   - secure: null (HTTPS'e göre otomatik) | bool
     *   - hsts: false (varsayılan) | true | string — yalnızca HTTPS isteklerde gönderilir
     *   - fingerprint_ip: false (varsayılan) | 'prefix' (IPv4 /24, IPv6 /64) | 'full'
     *   - send_headers: bool (varsayılan true)
     *   - auto_validate: bool (varsayılan true) — start() sonunda validate() çalışır; süresi dolmuş,
     *     limit aşmış veya parmak izi tutmayan oturum exception yerine temiz bir oturumla değiştirilir
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'secure' => null,
            'httponly' => true,
            'samesite' => 'Strict',
            'lifetime' => self::session_expiry,
            'path' => '/',
            'domain' => '',
            'regenerate_interval' => self::regenerate_interval,
            'max_requests' => self::max_requests,
            'max_lifetime' => self::max_lifetime,
            'fingerprint_fields' => self::fingerprint_fields,
            'fingerprint_ip' => false,
            'secure_headers' => self::secure_headers,
            'hsts' => false,
            'send_headers' => true,
            'auto_validate' => true,
        ], $config);
    }

    /**
     * Güvenli session başlatma.
     *
     * Uygulama oturumu zaten başlattıysa oturum yok edilmez; mevcut oturum kullanılır
     * (cookie parametreleri bu durumda değiştirilemez).
     */
    public function start(): bool
    {
        if ($this->initialized) {
            return true;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Strict mode: sunucuda olmayan (istemcinin uydurduğu) oturum ID'si kabul edilmez
            ini_set('session.use_strict_mode', '1');

            if (! headers_sent() && filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOLEAN)) {
                session_set_cookie_params([
                    'lifetime' => $this->config['lifetime'],
                    'path' => $this->config['path'],
                    'domain' => $this->config['domain'],
                    'secure' => $this->is_secure(),
                    'httponly' => $this->config['httponly'],
                    'samesite' => $this->config['samesite'],
                ]);
            }

            if (! session_start()) {
                throw new RuntimeException('Session başlatılamadı');
            }
        }

        if ($this->config['send_headers'] && ! headers_sent()) {
            foreach ($this->security_headers() as $header => $value) {
                header("$header: $value");
            }
        }

        if (! $this->validate_session()) {
            // Session fixation: istemcinin getirdiği ID ile güvenli oturum kurulmaz (veri korunur)
            $uses_cookies = filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOLEAN);
            if (! $uses_cookies || ! headers_sent()) {
                session_regenerate_id(true);
            }

            $this->mark_new_session();
        }

        $this->initialized = true;

        if ($this->config['auto_validate']) {
            try {
                $this->validate();
            } catch (RuntimeException) {
                // validate() geçersiz oturumu yok etti; kullanıcı yeni (boş) oturumla devam eder
                $this->start_fresh();
            }
        }

        return true;
    }

    /**
     * Yok edilen oturumun yerine yeni ID ile boş, işaretli bir oturum açar.
     */
    private function start_fresh(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (! headers_sent() || ! filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOLEAN)) {
            session_id((string) session_create_id());
        }
        if (! session_start()) {
            throw new RuntimeException('Session başlatılamadı');
        }

        $_SESSION = [];
        $this->mark_new_session();
        $this->initialized = true;
    }

    private function mark_new_session(): void
    {
        $now = time();
        $_SESSION['_created'] = $now;
        $_SESSION['_last_activity'] = $now;
        $_SESSION['_regenerated_at'] = $now;
        $_SESSION['_requests'] = 0;
        $_SESSION['_fingerprint'] = $this->generate_fingerprint();
    }

    /**
     * Cookie `secure` bayrağı: açıkça verilmediyse isteğin HTTPS olup olmadığına göre.
     */
    public function is_secure(): bool
    {
        $secure = $this->config['secure'];
        if ($secure === null) {
            return SecurityManager::is_https();
        }

        return (bool) $secure;
    }

    /**
     * Bu istekte gönderilecek güvenlik başlıkları.
     *
     * @return array<string, string>
     */
    public function security_headers(): array
    {
        $headers = $this->config['secure_headers'];
        unset($headers['X-XSS-Protection']);

        $hsts = $this->config['hsts'];
        if ($hsts !== false && $hsts !== null && SecurityManager::is_https()) {
            $headers['Strict-Transport-Security'] = is_string($hsts) ? $hsts : self::hsts_default;
        } else {
            unset($headers['Strict-Transport-Security']);
        }

        return $headers;
    }

    /**
     * Session geçerliliğini kontrol et ve güncelle
     */
    public function validate(): void
    {
        if (! $this->initialized) {
            throw new RuntimeException('Session başlatılmamış');
        }

        if (! $this->validate_session()) {
            throw new RuntimeException('Geçersiz session');
        }

        $now = time();

        // regenerate_id() sonrası eski ID: eşzamanlı istekler için kısa süre geçerli, sonra kullanılamaz
        if (isset($_SESSION['_destroyed']) && $now - (int) $_SESSION['_destroyed'] > self::regenerate_grace) {
            $this->destroy();

            throw new RuntimeException('Session ID yenilenmiş; eski ID artık geçersiz');
        }

        if ($now - $_SESSION['_created'] > $this->config['max_lifetime']) {
            $this->destroy();

            throw new RuntimeException('Session süresi doldu');
        }

        if ($now - $_SESSION['_last_activity'] > $this->config['lifetime']) {
            $this->destroy();

            throw new RuntimeException('Session zaman aşımına uğradı');
        }

        if ($_SESSION['_requests'] > $this->config['max_requests']) {
            $this->destroy();

            throw new RuntimeException('Maksimum istek sayısı aşıldı');
        }

        if (! hash_equals((string) $_SESSION['_fingerprint'], $this->generate_fingerprint())) {
            $this->destroy();

            throw new RuntimeException('Session hijacking tespit edildi');
        }

        $interval = (int) $this->config['regenerate_interval'];
        $regenerated_at = (int) ($_SESSION['_regenerated_at'] ?? $_SESSION['_created']);
        if ($interval > 0 && $now - $regenerated_at >= $interval && ! isset($_SESSION['_destroyed'])) {
            $this->regenerate_id();
        }

        $_SESSION['_last_activity'] = $now;
        $_SESSION['_requests']++;
    }

    /**
     * Session ID'sini yeniler. Eski oturum hemen silinmez: `_destroyed` işaretiyle saklanır ve
     * regenerate_grace (60 sn) boyunca kabul edilir; aynı anda gelen istekler (AJAX, sekmeler)
     * oturumsuz kalmaz. Süre dolunca eski ID validate()'te geçersizdir.
     *
     * @param bool $delete_old true: eski oturum hemen silinir (oturum açma / yetki değişikliği; bkz. login())
     */
    public function regenerate_id(bool $delete_old = false): bool
    {
        if (! $this->initialized) {
            throw new RuntimeException('Session başlatılmamış');
        }

        $old_session = $_SESSION;
        if (! $delete_old) {
            // session_regenerate_id(false) mevcut veriyi eski ID'ye yazar; eski kopya işaretli kalır
            $_SESSION['_destroyed'] = time();
        }

        if (! session_regenerate_id($delete_old)) {
            $_SESSION = $old_session;

            return false;
        }

        $_SESSION = $old_session;
        unset($_SESSION['_destroyed']);
        $_SESSION['_regenerated_at'] = time();

        return true;
    }

    /**
     * Oturum açıldıktan / yetki değiştikten sonra çağrılır (session fixation): oturum ID'si eski
     * oturum silinerek yenilenir ve CSRF token'ı döndürülür. Kimlik bilgisini oturuma bundan sonra yazın.
     */
    public function login(): void
    {
        if (! $this->initialized) {
            throw new RuntimeException('Session başlatılmamış');
        }

        if (! $this->regenerate_id(true)) {
            throw new RuntimeException('Session ID yenilenemedi');
        }
        self::rotate_csrf_token();
    }

    /**
     * Client fingerprint oluştur. IP varsayılan olarak dahil edilmez (mobil ağlarda ve
     * proxy arkasında IP değişir); `fingerprint_ip` ile prefix veya tam IP eklenebilir.
     */
    private function generate_fingerprint(): string
    {
        $data = '';
        foreach ($this->config['fingerprint_fields'] as $field) {
            $data .= $field === 'REMOTE_ADDR'
                ? IpResolver::from_config()->client_ip()
                : ($_SERVER[$field] ?? '');
            $data .= "\0";
        }

        $mode = $this->config['fingerprint_ip'];
        if ($mode === 'full' || $mode === true) {
            $data .= IpResolver::from_config()->client_ip();
        } elseif ($mode === 'prefix') {
            $data .= self::ip_prefix(IpResolver::from_config()->client_ip());
        }

        return hash('sha256', $data);
    }

    /**
     * IPv4 için /24, IPv6 için /64 ağ öneki.
     */
    public static function ip_prefix(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = (string) inet_pton($ip);

            return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }

        return $ip;
    }

    private function validate_session(): bool
    {
        return isset(
            $_SESSION['_created'],
            $_SESSION['_last_activity'],
            $_SESSION['_requests'],
            $_SESSION['_fingerprint']
        );
    }

    /**
     * Session'ı güvenli şekilde sonlandır
     */
    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
            if (! headers_sent()) {
                setcookie(
                    (string)session_name(),
                    '',
                    [
                        'expires' => time() - 3600,
                        'path' => $this->config['path'],
                        'domain' => $this->config['domain'],
                        'secure' => $this->is_secure(),
                        'httponly' => true,
                        'samesite' => $this->config['samesite'],
                    ]
                );
            }
        }
        $this->initialized = false;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function get_stats(): array
    {
        if (! $this->initialized) {
            throw new RuntimeException('Session başlatılmamış');
        }

        return [
            'created' => $_SESSION['_created'] ?? null,
            'last_activity' => $_SESSION['_last_activity'] ?? null,
            'requests' => $_SESSION['_requests'] ?? 0,
            'lifetime_remaining' => $this->config['max_lifetime'] - (time() - ($_SESSION['_created'] ?? time())),
            'expiry_remaining' => $this->config['lifetime'] - (time() - ($_SESSION['_last_activity'] ?? time())),
            'fingerprint' => substr($_SESSION['_fingerprint'] ?? '', 0, 8) . '...',
            'secure' => $this->is_secure(),
            'httponly' => $this->config['httponly'],
            'samesite' => $this->config['samesite'],
        ];
    }

    private static function csrf_ttl(): int
    {
        return max(0, (int) Config::get('csrf_token_ttl', self::csrf_token_ttl));
    }

    /**
     * CSRF token'ı alır; yoksa veya süresi dolduysa (CSRF_TOKEN_TTL, 0 = süresiz) yenisini üretir.
     */
    public static function get_csrf_token(): string
    {
        $ttl = self::csrf_ttl();
        $issued = (int) ($_SESSION['csrf_token_time'] ?? 0);
        $expired = $ttl > 0 && time() - $issued > $ttl;

        if (! isset($_SESSION['csrf_token']) || ! is_string($_SESSION['csrf_token']) || $expired) {
            return self::rotate_csrf_token();
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Yeni CSRF token üretir (ör. login / yetki değişikliği sonrası).
     */
    public static function rotate_csrf_token(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();

        return $_SESSION['csrf_token'];
    }

    /**
     * CSRF token'ı doğrular (süresi dolmuş token reddedilir).
     *
     * @param bool $consume true ise başarılı doğrulamadan sonra token yenilenir (tek kullanımlık;
     *                      hassas işlemler için). Aynı sayfadaki diğer formlar yeni token almalıdır.
     */
    public static function validate_csrf_token(mixed $token, bool $consume = false): bool
    {
        if (! isset($_SESSION['csrf_token']) || ! is_string($_SESSION['csrf_token'])) {
            return false;
        }

        $ttl = self::csrf_ttl();
        if ($ttl > 0 && time() - (int) ($_SESSION['csrf_token_time'] ?? 0) > $ttl) {
            return false;
        }

        if (! is_string($token) || ! hash_equals($_SESSION['csrf_token'], $token)) {
            return false;
        }

        if ($consume) {
            self::rotate_csrf_token();
        }

        return true;
    }
}
