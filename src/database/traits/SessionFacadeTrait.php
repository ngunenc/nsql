<?php

namespace nsql\database\traits;

use nsql\security\SessionManager;

/**
 * nsql üzerindeki statik session / CSRF / XSS kısayolları.
 */
trait SessionFacadeTrait
{
    private static ?SessionManager $session = null;
    /** @var array<string, mixed> */
    private static array $session_config = [];

    /**
     * Session manager'ı başlatır veya mevcut instance'ı döndürür.
     *
     * @throws \LogicException Örnek zaten farklı ayarlarla oluşturulduysa (ayarlar sessizce yok sayılmaz)
     */
    public static function session(array $config = []): SessionManager
    {
        if (self::$session === null) {
            self::$session = new SessionManager($config);
            self::$session_config = $config;
        } elseif ($config !== [] && $config !== self::$session_config) {
            throw new \LogicException(
                'Session manager zaten farklı ayarlarla oluşturuldu; ayarları ilk secure_session_start() / session() çağrısında verin.'
            );
        }

        return self::$session;
    }

    /**
     * Oturum açıldıktan sonra çağırın: oturum ID'si ve CSRF token'ı yenilenir (session fixation).
     */
    public static function session_login(): void
    {
        self::session()->login();
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
            self::$session_config = [];
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
     *
     * @param bool $consume true ise başarılı doğrulamadan sonra token yenilenir (tek kullanımlık)
     */
    public static function validate_csrf(mixed $token, bool $consume = false): bool
    {
        return self::session()->validate_csrf_token($token, $consume);
    }
}
