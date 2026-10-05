<?php

namespace nsql\database\traits;

use nsql\security\session_manager;

/**
 * nsql üzerindeki statik session / CSRF / XSS kısayolları.
 */
trait session_facade_trait
{
    private static ?session_manager $session = null;

    /**
     * Session manager'ı başlatır veya mevcut instance'ı döndürür
     */
    public static function session(array $config = []): session_manager
    {
        if (self::$session === null) {
            self::$session = new session_manager($config);
        }

        return self::$session;
    }

    /**
     * Güvenli oturum başlatma ve cookie ayarları
     */
    public static function secure_session_start(array $config = []): void
    {
        self::session($config)->start();
    }

    /**
     * Session güvenli şekilde sonlandır
     */
    public static function end_session(): void
    {
        if (self::$session !== null) {
            self::$session->destroy();
            self::$session = null;
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
     * CSRF token al veya oluştur
     */
    public static function csrf_token(): string
    {
        return self::session()->get_csrf_token();
    }

    /**
     * CSRF token doğrulaması yap
     */
    public static function validate_csrf(mixed $token): bool
    {
        return self::session()->validate_csrf_token((string)$token);
    }
}
