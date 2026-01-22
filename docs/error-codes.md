# Error Codes Documentation

Bu dokümantasyon, nsql kütüphanesinde kullanılan tüm hata kodlarını ve anlamlarını içerir.

## Hata Kodu Kategorileri

### Genel Hatalar (1000-1999)
- `1000` - GENERAL_ERROR: Genel bir hata oluştu
- `1001` - INVALID_ARGUMENT: Geçersiz argüman
- `1002` - CONFIGURATION_ERROR: Yapılandırma hatası

### Connection Hataları (2000-2999)
- `2000` - CONNECTION_FAILED: Veritabanı bağlantısı başarısız
- `2001` - CONNECTION_TIMEOUT: Veritabanı bağlantı zaman aşımı
- `2002` - CONNECTION_LOST: Veritabanı bağlantısı kesildi
- `2003` - CONNECTION_POOL_EXHAUSTED: Bağlantı havuzu tükendi
- `2004` - INVALID_DSN: Geçersiz DSN formatı
- `2005` - DRIVER_NOT_FOUND: Database driver bulunamadı

### Query Hataları (3000-3999)
- `3000` - QUERY_FAILED: SQL sorgusu başarısız
- `3001` - QUERY_SYNTAX_ERROR: SQL sözdizimi hatası
- `3002` - QUERY_TIMEOUT: SQL sorgu zaman aşımı
- `3003` - QUERY_INVALID_PARAMS: Geçersiz sorgu parametreleri
- `3004` - QUERY_EMPTY_RESULT: Sorgu sonucu boş
- `3005` - QUERY_LARGE_RESULT: Sorgu sonucu çok büyük

### Migration Hataları (4000-4999)
- `4000` - MIGRATION_FAILED: Migration başarısız
- `4001` - MIGRATION_ROLLBACK_FAILED: Migration rollback başarısız
- `4002` - MIGRATION_NOT_FOUND: Migration bulunamadı
- `4003` - MIGRATION_DEPENDENCY_ERROR: Migration bağımlılık hatası
- `4004` - MIGRATION_CIRCULAR_DEPENDENCY: Migration döngüsel bağımlılık
- `4005` - MIGRATION_ALREADY_APPLIED: Migration zaten uygulanmış

### Cache Hataları (5000-5999)
- `5000` - CACHE_FAILED: Cache işlemi başarısız
- `5001` - CACHE_CONNECTION_FAILED: Cache bağlantısı başarısız
- `5002` - CACHE_KEY_NOT_FOUND: Cache key bulunamadı
- `5003` - CACHE_WRITE_FAILED: Cache yazma başarısız
- `5004` - CACHE_DELETE_FAILED: Cache silme başarısız
- `5005` - CACHE_ADAPTER_NOT_AVAILABLE: Cache adapter kullanılamıyor

### Transaction Hataları (6000-6999)
- `6000` - TRANSACTION_FAILED: Transaction başarısız
- `6001` - TRANSACTION_NOT_STARTED: Transaction başlatılmamış
- `6002` - TRANSACTION_ALREADY_STARTED: Transaction zaten başlatılmış
- `6003` - TRANSACTION_ROLLBACK_FAILED: Transaction rollback başarısız
- `6004` - TRANSACTION_COMMIT_FAILED: Transaction commit başarısız

### Security Hataları (7000-7999)
- `7000` - SECURITY_SQL_INJECTION_DETECTED: SQL injection tespit edildi
- `7001` - SECURITY_XSS_DETECTED: XSS tespit edildi
- `7002` - SECURITY_CSRF_DETECTED: CSRF tespit edildi
- `7003` - SECURITY_RATE_LIMIT_EXCEEDED: Rate limit aşıldı
- `7004` - SECURITY_UNAUTHORIZED_ACCESS: Yetkisiz erişim

### Validation Hataları (8000-8999)
- `8000` - VALIDATION_FAILED: Doğrulama başarısız
- `8001` - VALIDATION_INVALID_COLUMN: Geçersiz sütun adı
- `8002` - VALIDATION_INVALID_TABLE: Geçersiz tablo adı
- `8003` - VALIDATION_INVALID_OPERATOR: Geçersiz operatör
- `8004` - VALIDATION_INVALID_VALUE: Geçersiz değer

## Kullanım Örnekleri

```php
use nsql\database\exceptions\{QueryException, error_codes};

// Hata kodu ile exception oluşturma
throw new QueryException(
    '',
    'SELECT * FROM users',
    [],
    error_codes::QUERY_SYNTAX_ERROR
);

// Hata kodundan mesaj alma
$message = error_codes::get_message(error_codes::CONNECTION_FAILED);

// Hata kodunun kategorisini alma
$category = error_codes::get_category(error_codes::MIGRATION_FAILED);

// Tüm hata kodlarını listeleme
$all_codes = error_codes::get_all_codes();
```
