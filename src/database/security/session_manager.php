<?php

namespace nsql\database\security;

use RuntimeException;

class session_manager {
    private bool $initialized = false;
    private array $config;
    
    // Session güvenlik ayarları
    private const SESSION_EXPIRY = 1800; // 30 dakikaya düşürüldü
    private const REGENERATE_INTERVAL = 300; // 5 dakika
    private const MAX_REQUESTS = 5000; // Makul bir üst limit
    private const MAX_LIFETIME = 43200; // 12 saat
    private const FINGERPRINT_FIELDS = [
        'HTTP_USER_AGENT',
        'REMOTE_ADDR',
        'HTTP_ACCEPT_LANGUAGE',
        'HTTP_SEC_CH_UA', // Browser bilgisi
        'HTTP_SEC_CH_UA_PLATFORM' // Platform bilgisi
    ];
    private const SECURE_HEADERS = [
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-XSS-Protection' => '1; mode=block',
        'X-Content-Type-Options' => 'nosniff'
    ];

    public function __construct(array $config = []) {
        $this->config = array_merge([
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict', // SameSite politikası sıkılaştırıldı
            'lifetime' => self::SESSION_EXPIRY,
            'path' => '/',
            'domain' => '',
            'regenerate_interval' => self::REGENERATE_INTERVAL,
            'max_requests' => self::MAX_REQUESTS,
            'max_lifetime' => self::MAX_LIFETIME,
            'fingerprint_fields' => self::FINGERPRINT_FIELDS,
            'secure_headers' => self::SECURE_HEADERS
        ], $config);
    }

    /**
     * Güvenli session başlatma
     */
    public function start(): bool {
        if ($this->initialized) {
            return true;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        // HTTPS kontrolü
        $secure = $this->config['secure'] || 
                 (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                 $_SERVER['SERVER_PORT'] == 443;

        // Cookie parametreleri ayarla
        session_set_cookie_params([
            'lifetime' => $this->config['lifetime'],
            'path' => $this->config['path'],
            'domain' => $this->config['domain'],
            'secure' => $secure,
            'httponly' => $this->config['httponly'],
            'samesite' => $this->config['samesite']
        ]);

        // Güvenlik başlıkları ayarla
        foreach ($this->config['secure_headers'] as $header => $value) {
            header("$header: $value");
        }

        if (!session_start()) {
            throw new RuntimeException('Session başlatılamadı');
        }

        // Yeni session ise başlangıç verilerini ayarla
        if (!$this->validate_session()) {
            $_SESSION['_created'] = time();
            $_SESSION['_last_activity'] = time();
            $_SESSION['_requests'] = 0;
            $_SESSION['_fingerprint'] = $this->generate_fingerprint();
            $_SESSION['_token'] = $this->generate_token();
        }

        $this->initialized = true;
        return true;
    }

    /**
     * Session geçerliliğini kontrol et ve güncelle
     */
    public function validate(): void {
        if (!$this->initialized) {
            throw new RuntimeException('Session başlatılmamış');
        }

        if (!$this->validate_session()) {
            throw new RuntimeException('Geçersiz session');
        }

        // Session yaşam süresi kontrolü
        if (time() - $_SESSION['_created'] > $this->config['max_lifetime']) {
            $this->destroy();
            throw new RuntimeException('Session süresi doldu');
        }

        // Son aktivite kontrolü
        if (time() - $_SESSION['_last_activity'] > $this->config['lifetime']) {
            $this->destroy();
            throw new RuntimeException('Session zaman aşımına uğradı');
        }

        // İstek sayısı kontrolü
        if ($_SESSION['_requests'] > $this->config['max_requests']) {
            $this->destroy();
            throw new RuntimeException('Maksimum istek sayısı aşıldı');
        }

        // Fingerprint kontrolü
        if ($_SESSION['_fingerprint'] !== $this->generate_fingerprint()) {
            $this->destroy();
            throw new RuntimeException('Session hijacking tespit edildi');
        }

        // Periyodik ID yenileme
        if ($_SESSION['_requests'] % ($this->config['regenerate_interval'] / 2) === 0) {
            $this->regenerate_id();
        }

        // Session verilerini güncelle
        $_SESSION['_last_activity'] = time();
        $_SESSION['_requests']++;
    }

    /**
     * Session ID'sini güvenli şekilde yeniler
     */
    public function regenerate_id(): bool {
        if (!$this->initialized) {
            throw new RuntimeException('Session başlatılmamış');
        }

        // Eski session verilerini sakla
        $old_session = $_SESSION;
        
        // Yeni session ID oluştur
        if (!session_regenerate_id(true)) {
            return false;
        }

        // Session verilerini geri yükle
        $_SESSION = $old_session;
        $_SESSION['_token'] = $this->generate_token(); // Yeni token
        
        return true;
    }

    /**
     * Client fingerprint oluştur
     */
    private function generate_fingerprint(): string {
        $data = '';
        foreach ($this->config['fingerprint_fields'] as $field) {
            $data .= $_SERVER[$field] ?? '';
        }
        return hash('sha256', $data);
    }

    /**
     * Benzersiz token oluştur
     */
    private function generate_token(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Session geçerliliğini kontrol et
     */
    private function validate_session(): bool {
        return isset(
            $_SESSION['_created'],
            $_SESSION['_last_activity'],
            $_SESSION['_requests'],
            $_SESSION['_fingerprint'],
            $_SESSION['_token']
        );
    }

    /**
     * Session'ı güvenli şekilde sonlandır
     */
    public function destroy(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => $this->config['domain'],
                    'secure' => $this->config['secure'],
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]
            );
        }
        $this->initialized = false;
    }

    /**
     * Session'dan değer al
     */
    public function get(string $key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Session'a değer kaydet
     */
    public function set(string $key, $value): void {
        $_SESSION[$key] = $value;
    }

    /**
     * Session'dan değer sil
     */
    public function remove(string $key): void {
        unset($_SESSION[$key]);
    }

    /**
     * Session istatistiklerini al
     */
    public function get_stats(): array {
        if (!$this->initialized) {
            throw new RuntimeException('Session başlatılmamış');
        }

        return [
            'created' => $_SESSION['_created'] ?? null,
            'last_activity' => $_SESSION['_last_activity'] ?? null,
            'requests' => $_SESSION['_requests'] ?? 0,
            'lifetime_remaining' => $this->config['max_lifetime'] - (time() - ($_SESSION['_created'] ?? time())),
            'expiry_remaining' => $this->config['lifetime'] - (time() - ($_SESSION['_last_activity'] ?? time())),
            'fingerprint' => substr($_SESSION['_fingerprint'] ?? '', 0, 8) . '...',
            'secure' => $this->config['secure'],
            'httponly' => $this->config['httponly'],
            'samesite' => $this->config['samesite']
        ];
    }
}
