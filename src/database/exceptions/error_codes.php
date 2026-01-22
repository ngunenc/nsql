<?php

namespace nsql\database\exceptions;

/**
 * Error Code Constants
 * 
 * Tüm hata kodları için sabitler
 */
class error_codes
{
    // Genel hatalar (1000-1999)
    public const GENERAL_ERROR = 1000;
    public const INVALID_ARGUMENT = 1001;
    public const CONFIGURATION_ERROR = 1002;

    // Connection hataları (2000-2999)
    public const CONNECTION_FAILED = 2000;
    public const CONNECTION_TIMEOUT = 2001;
    public const CONNECTION_LOST = 2002;
    public const CONNECTION_POOL_EXHAUSTED = 2003;
    public const INVALID_DSN = 2004;
    public const DRIVER_NOT_FOUND = 2005;

    // Query hataları (3000-3999)
    public const QUERY_FAILED = 3000;
    public const QUERY_SYNTAX_ERROR = 3001;
    public const QUERY_TIMEOUT = 3002;
    public const QUERY_INVALID_PARAMS = 3003;
    public const QUERY_EMPTY_RESULT = 3004;
    public const QUERY_LARGE_RESULT = 3005;

    // Migration hataları (4000-4999)
    public const MIGRATION_FAILED = 4000;
    public const MIGRATION_ROLLBACK_FAILED = 4001;
    public const MIGRATION_NOT_FOUND = 4002;
    public const MIGRATION_DEPENDENCY_ERROR = 4003;
    public const MIGRATION_CIRCULAR_DEPENDENCY = 4004;
    public const MIGRATION_ALREADY_APPLIED = 4005;

    // Cache hataları (5000-5999)
    public const CACHE_FAILED = 5000;
    public const CACHE_CONNECTION_FAILED = 5001;
    public const CACHE_KEY_NOT_FOUND = 5002;
    public const CACHE_WRITE_FAILED = 5003;
    public const CACHE_DELETE_FAILED = 5004;
    public const CACHE_ADAPTER_NOT_AVAILABLE = 5005;

    // Transaction hataları (6000-6999)
    public const TRANSACTION_FAILED = 6000;
    public const TRANSACTION_NOT_STARTED = 6001;
    public const TRANSACTION_ALREADY_STARTED = 6002;
    public const TRANSACTION_ROLLBACK_FAILED = 6003;
    public const TRANSACTION_COMMIT_FAILED = 6004;

    // Security hataları (7000-7999)
    public const SECURITY_SQL_INJECTION_DETECTED = 7000;
    public const SECURITY_XSS_DETECTED = 7001;
    public const SECURITY_CSRF_DETECTED = 7002;
    public const SECURITY_RATE_LIMIT_EXCEEDED = 7003;
    public const SECURITY_UNAUTHORIZED_ACCESS = 7004;

    // Validation hataları (8000-8999)
    public const VALIDATION_FAILED = 8000;
    public const VALIDATION_INVALID_COLUMN = 8001;
    public const VALIDATION_INVALID_TABLE = 8002;
    public const VALIDATION_INVALID_OPERATOR = 8003;
    public const VALIDATION_INVALID_VALUE = 8004;

    /**
     * Hata koduna göre mesaj döndürür
     *
     * @param int $code Hata kodu
     * @return string Hata mesajı
     */
    public static function get_message(int $code): string
    {
        return match ($code) {
            // Genel hatalar
            self::GENERAL_ERROR => 'Genel bir hata oluştu',
            self::INVALID_ARGUMENT => 'Geçersiz argüman',
            self::CONFIGURATION_ERROR => 'Yapılandırma hatası',

            // Connection hataları
            self::CONNECTION_FAILED => 'Veritabanı bağlantısı başarısız',
            self::CONNECTION_TIMEOUT => 'Veritabanı bağlantı zaman aşımı',
            self::CONNECTION_LOST => 'Veritabanı bağlantısı kesildi',
            self::CONNECTION_POOL_EXHAUSTED => 'Bağlantı havuzu tükendi',
            self::INVALID_DSN => 'Geçersiz DSN formatı',
            self::DRIVER_NOT_FOUND => 'Database driver bulunamadı',

            // Query hataları
            self::QUERY_FAILED => 'SQL sorgusu başarısız',
            self::QUERY_SYNTAX_ERROR => 'SQL sözdizimi hatası',
            self::QUERY_TIMEOUT => 'SQL sorgu zaman aşımı',
            self::QUERY_INVALID_PARAMS => 'Geçersiz sorgu parametreleri',
            self::QUERY_EMPTY_RESULT => 'Sorgu sonucu boş',
            self::QUERY_LARGE_RESULT => 'Sorgu sonucu çok büyük',

            // Migration hataları
            self::MIGRATION_FAILED => 'Migration başarısız',
            self::MIGRATION_ROLLBACK_FAILED => 'Migration rollback başarısız',
            self::MIGRATION_NOT_FOUND => 'Migration bulunamadı',
            self::MIGRATION_DEPENDENCY_ERROR => 'Migration bağımlılık hatası',
            self::MIGRATION_CIRCULAR_DEPENDENCY => 'Migration döngüsel bağımlılık',
            self::MIGRATION_ALREADY_APPLIED => 'Migration zaten uygulanmış',

            // Cache hataları
            self::CACHE_FAILED => 'Cache işlemi başarısız',
            self::CACHE_CONNECTION_FAILED => 'Cache bağlantısı başarısız',
            self::CACHE_KEY_NOT_FOUND => 'Cache key bulunamadı',
            self::CACHE_WRITE_FAILED => 'Cache yazma başarısız',
            self::CACHE_DELETE_FAILED => 'Cache silme başarısız',
            self::CACHE_ADAPTER_NOT_AVAILABLE => 'Cache adapter kullanılamıyor',

            // Transaction hataları
            self::TRANSACTION_FAILED => 'Transaction başarısız',
            self::TRANSACTION_NOT_STARTED => 'Transaction başlatılmamış',
            self::TRANSACTION_ALREADY_STARTED => 'Transaction zaten başlatılmış',
            self::TRANSACTION_ROLLBACK_FAILED => 'Transaction rollback başarısız',
            self::TRANSACTION_COMMIT_FAILED => 'Transaction commit başarısız',

            // Security hataları
            self::SECURITY_SQL_INJECTION_DETECTED => 'SQL injection tespit edildi',
            self::SECURITY_XSS_DETECTED => 'XSS tespit edildi',
            self::SECURITY_CSRF_DETECTED => 'CSRF tespit edildi',
            self::SECURITY_RATE_LIMIT_EXCEEDED => 'Rate limit aşıldı',
            self::SECURITY_UNAUTHORIZED_ACCESS => 'Yetkisiz erişim',

            // Validation hataları
            self::VALIDATION_FAILED => 'Doğrulama başarısız',
            self::VALIDATION_INVALID_COLUMN => 'Geçersiz sütun adı',
            self::VALIDATION_INVALID_TABLE => 'Geçersiz tablo adı',
            self::VALIDATION_INVALID_OPERATOR => 'Geçersiz operatör',
            self::VALIDATION_INVALID_VALUE => 'Geçersiz değer',

            default => 'Bilinmeyen hata kodu: ' . $code,
        };
    }

    /**
     * Hata koduna göre kategori döndürür
     *
     * @param int $code Hata kodu
     * @return string Kategori
     */
    public static function get_category(int $code): string
    {
        return match (true) {
            $code >= 1000 && $code < 2000 => 'general',
            $code >= 2000 && $code < 3000 => 'connection',
            $code >= 3000 && $code < 4000 => 'query',
            $code >= 4000 && $code < 5000 => 'migration',
            $code >= 5000 && $code < 6000 => 'cache',
            $code >= 6000 && $code < 7000 => 'transaction',
            $code >= 7000 && $code < 8000 => 'security',
            $code >= 8000 && $code < 9000 => 'validation',
            default => 'unknown',
        };
    }

    /**
     * Tüm hata kodlarını döndürür
     *
     * @return array Hata kodları ve mesajları
     */
    public static function get_all_codes(): array
    {
        $reflection = new \ReflectionClass(self::class);
        $constants = $reflection->getConstants();
        
        $codes = [];
        foreach ($constants as $name => $value) {
            $codes[$name] = [
                'code' => $value,
                'message' => self::get_message($value),
                'category' => self::get_category($value),
            ];
        }
        
        return $codes;
    }
}
