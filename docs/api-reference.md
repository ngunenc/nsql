# 📚 nsql API Referansı v1.4

## 📑 İçindekiler

- [Ana Sınıflar](#-ana-sınıflar)
- [config Sınıfı](#-config-sınıfı)
- [nsql Sınıfı](#-nsql-sınıfı)
- [Query Builder](#-query-builder)
- [Security Sınıfları](#-security-sınıfları)
- [Migration Manager](#-migration-manager)
- [Traits](#-traits)
- [Yeni İstatistik API'leri (v1.4)](#-yeni-istatistik-apileri-v14)

## 🏗 Ana Sınıflar

### config Sınıfı

Yapılandırma yönetimi için merkezi sınıf.

#### Metodlar

```php
// Ortam ayarlama
config::set_environment(string $env): void

// Değer alma
config::get(string $key, mixed $default = null): mixed

// Değer ayarlama
config::set(string $key, mixed $value): void

// Değer kontrolü
config::has(string $key): bool

// Tüm yapılandırma
config::all(): array

// Proje kök dizini
config::get_project_root(): string
```

#### Sabitler

```php
// Veritabanı ayarları
config::db_host = 'localhost'
config::db_name = 'database_name'
config::db_user = 'username'
config::db_pass = 'password'

// Güvenlik ayarları
config::SECURITY_STRICT_MODE = false
config::ENCRYPTION_KEY = 'your_encryption_key'

// Performans ayarları
config::STATEMENT_CACHE_LIMIT = 100
config::QUERY_CACHE_LIMIT = 1000
config::default_chunk_size = 1000
```

### nsql Sınıfı

Ana veritabanı sınıfı. PDO wrapper ve tüm özelliklerin merkezi.

#### Constructor

```php
new nsql(
    ?string $host = null,
    ?string $db = null,
    ?string $user = null,
    ?string $pass = null,
    ?string $charset = null,
    ?bool $debug = null
)
```

#### Temel Metodlar

```php
// Sorgu çalıştırma
query(string $sql, array $params = []): PDOStatement|false

// Veri ekleme
insert(string $sql, array $params = []): bool

// Veri güncelleme
update(string $sql, array $params = []): bool

// Veri silme
delete(string $sql, array $params = []): bool

// Tek satır alma
get_row(string $query, array $params = []): ?object

// Tüm sonuçları alma
get_results(string $query, array $params = []): array

// Chunked fetch (büyük veri setleri için)
get_chunk(string $query, array $params = [], int $chunk_size = 1000): Generator
```

#### Transaction Metodları

```php
// Transaction başlatma
begin_transaction(): void

// Transaction commit
commit_transaction(): bool

// Transaction rollback
rollback_transaction(): bool
```

#### Utility Metodları

```php
// Son insert ID
insert_id(): int

// Son hata
get_last_error(): ?string

// Connection pool istatistikleri
get_pool_stats(): array
```

#### Static Metodlar

```php
// HTML escape
nsql::escape_html(mixed $string): string

// CSRF token oluşturma
nsql::generate_csrf_token(): string

// CSRF token doğrulama
nsql::validate_csrf_token(mixed $token): bool
```

## 🔧 Query Builder

Fluent interface ile SQL sorguları oluşturma.

### Constructor

```php
new query_builder(nsql $db)
```

### Metodlar

```php
// SELECT clause
select(string ...$columns): self

// FROM clause
from(string $table): self

// WHERE clause
where(string $column, string $operator, mixed $value): self

// ORDER BY clause
order_by(string $column, string $direction = 'ASC'): self

// LIMIT clause
limit(int $count, int $offset = 0): self

// JOIN clause
join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self

// Sorguyu çalıştırma
get(): array

// İlk sonucu alma
first(): ?object

// SQL sorgusunu alma (test için)
get_query(): string
```

### Örnek Kullanım

```php
$builder = new query_builder($db);

$results = $builder
    ->select('id', 'name', 'email')
    ->from('users')
    ->where('active', '=', 1)
    ->order_by('created_at', 'DESC')
    ->limit(10)
    ->get();
```

## 🔒 Security Sınıfları

### Security Manager

Güvenlik işlemlerinin merkezi yönetimi.

```php
// HTML escape
SecurityManager::escape_html(mixed $string): string

// CSRF token oluşturma
SecurityManager::generate_csrf_token(): string

// CSRF token doğrulama
SecurityManager::validate_csrf_token(mixed $token): bool

// SQL parametrelerini doğrulama
SecurityManager::validate_sql_params(array $params): bool

// Güvenli sorgu hazırlama
SecurityManager::prepare_safe_query(string $sql, array $params): string
```

### Encryption

Veri şifreleme ve çözme.

```php
$encryption = new encryption(?string $key = null);

// Veri şifreleme
$encrypted = $encryption->encrypt(string $data): string

// Veri çözme
$decrypted = $encryption->decrypt(string $encrypted): string
```

### Rate Limiter

Rate limiting ve DDoS koruması.

```php
$limiter = new rate_limiter(?nsql $db = null);

// Rate limit kontrolü
$allowed = $limiter->check_rate_limit(string $identifier, string $request_type = 'default'): bool

// Rate limit istatistikleri
$stats = $limiter->get_stats(): array
```

### Audit Logger

Güvenlik olaylarını loglama.

```php
$logger = new audit_logger(?string $log_file = null);

// Güvenlik olayı loglama
$logger->log_security_event(string $event_type, string $description, array $context = [], string $severity = 'info'): void

// SQL injection denemesi loglama
$logger->log_sql_injection_attempt(string $query, array $params = [], string $error = ''): void
```

## 📦 Migration Manager

Veritabanı migration'larını yönetme.

```php
$manager = new migration_manager(?nsql $db = null);

// Migration'ları çalıştırma
$manager->migrate(): array

// Belirli versiyona migration
$manager->migrate_to(string $version): array

// Migration'ları geri alma
$manager->rollback(int $steps = 1): array

// Seed verilerini yükleme
$manager->seed(?string $class = null): void

// Yeni migration oluşturma
$manager->create_migration(string $name): string

// Yeni seeder oluşturma
$manager->create_seeder(string $name): string
```

## 🧩 Traits

### Cache Trait

Query cache işlemleri.

```php
// Cache'i temizleme
clear_query_cache(): void

// Cache'den veri alma
get_from_query_cache(string $key): mixed

// Cache'e veri ekleme
add_to_query_cache(string $key, mixed $data): void
```

### Connection Trait

Bağlantı yönetimi.

```php
// Bağlantıyı başlatma
initialize_connection(): void

// Bağlantıyı kapatma
disconnect(): void

// Bağlantı kontrolü
ensure_connection(): void
```

### Transaction Trait

Transaction işlemleri.

```php
// Transaction başlatma
begin(): void

// Transaction commit
commit(): bool

// Transaction rollback
rollback(): bool

// Transaction seviyesi
getTransactionLevel(): int
```

### Debug Trait

Debug ve logging işlemleri.

```php
// Debug bilgisi loglama
log_debug_info(string $message, mixed $data = null): void

// Hata loglama
log_error(string $message): void

// Query interpolasyonu
interpolate_query(string $sql, array $params): string
```

## 📝 Örnekler

### Temel Kullanım

```php
use nsql\database\nsql;
use nsql\database\config;

// Yapılandırma
config::set_environment('production');

// Veritabanı bağlantısı
$db = new nsql();

// Veri ekleme
$id = $db->insert(
    "INSERT INTO users (name, email) VALUES (:name, :email)",
    ['name' => 'John Doe', 'email' => 'john@example.com']
);

// Veri okuma
$user = $db->get_row(
    "SELECT * FROM users WHERE id = :id",
    ['id' => $id]
);

// Veri güncelleme
$db->update(
    "UPDATE users SET name = :name WHERE id = :id",
    ['name' => 'Jane Doe', 'id' => $id]
);
```

### Transaction Kullanımı

```php
$db->begin_transaction();

try {
    $db->insert("INSERT INTO users (name) VALUES (:name)", ['name' => 'User 1']);
    $db->insert("INSERT INTO users (name) VALUES (:name)", ['name' => 'User 2']);
    
    $db->commit_transaction();
    echo "Transaction başarılı!";
} catch (Exception $e) {
    $db->rollback_transaction();
    echo "Transaction geri alındı: " . $e->getMessage();
}
```

### Query Builder Kullanımı

```php
use nsql\database\query_builder;

$builder = new query_builder($db);

$users = $builder
    ->select('id', 'name', 'email')
    ->from('users')
    ->where('active', '=', 1)
    ->where('created_at', '>', '2023-01-01')
    ->order_by('name', 'ASC')
    ->limit(50)
    ->get();
```

### Security Kullanımı

```php
use nsql\database\security\SecurityManager;

// XSS koruması
$safe_html = SecurityManager::escape_html('<script>alert("xss")</script>');

// CSRF koruması
$token = SecurityManager::generate_csrf_token();
$is_valid = SecurityManager::validate_csrf_token($token);
```

### Migration Kullanımı

```php
use nsql\database\migration_manager;

$manager = new migration_manager($db);

// Tüm migration'ları çalıştır
$executed = $manager->migrate();

// Seed verilerini yükle
$manager->seed();
```

## 🔧 Yapılandırma

### .env Dosyası

```ini
# Ortam
ENV=production

# Veritabanı
db_host=localhost
db_name=my_database
db_user=username
db_pass=password

# Debug
DEBUG_MODE=false

# Güvenlik
SECURITY_STRICT_MODE=true
ENCRYPTION_KEY=your_secret_key_here

# Performans
STATEMENT_CACHE_LIMIT=100
QUERY_CACHE_LIMIT=1000
default_chunk_size=1000

# Rate Limiting
RATE_LIMIT_ENABLED=true
RATE_LIMIT_MAX_REQUESTS=100
RATE_LIMIT_WINDOW=60

# Log
LOG_DIR=storage/logs
LOG_FILE=app.log
```

## 🚨 Hata Yönetimi

### Exception Türleri

```php
// Veritabanı bağlantı hatası
RuntimeException: "Veritabanı bağlantı hatası"

// SQL hatası
PDOException: PDO hataları

// Migration hatası
RuntimeException: "Migration failed"

// Güvenlik hatası
SecurityException: "Güvenlik ihlali tespit edildi"
```

### Hata Yakalama

```php
try {
    $result = $db->query("SELECT * FROM users");
} catch (PDOException $e) {
    error_log("Veritabanı hatası: " . $e->getMessage());
    // Hata işleme
} catch (Exception $e) {
    error_log("Genel hata: " . $e->getMessage());
    // Hata işleme
}
```

## 📊 Yeni İstatistik API'leri (v1.4)

### Tüm İstatistikleri Alma

```php
// Tüm istatistikleri tek API'de alma
$allStats = $db->get_all_stats();

// Dönen yapı:
[
    'memory' => [...],           // Bellek istatistikleri
    'cache' => [...],           // Cache istatistikleri
    'query_analyzer' => [...],  // Query analyzer istatistikleri
    'connection_pool' => [...]  // Connection pool istatistikleri
]
```

### Cache İstatistikleri

```php
// Tüm cache istatistikleri
$cacheStats = $db->get_all_cache_stats();

// Query cache istatistikleri
$queryCacheStats = $cacheStats['query_cache'];
echo "Query Cache Hit Rate: " . $queryCacheStats['hit_rate'] . "%\n";
echo "Query Cache Size: " . $queryCacheStats['size'] . "/" . $queryCacheStats['limit'] . "\n";

// Statement cache istatistikleri
$statementCacheStats = $cacheStats['statement_cache'];
echo "Statement Cache Hit Rate: " . $statementCacheStats['hit_rate'] . "%\n";
```

### Query Analyzer İstatistikleri

```php
// Query analyzer istatistikleri
$analyzerStats = $db->get_query_analyzer_stats();

echo "Analysis Enabled: " . ($analyzerStats['enabled'] ? 'Yes' : 'No') . "\n";
echo "Cache Size: " . $analyzerStats['cache_size'] . "\n";
echo "Cache Hit Rate: " . $analyzerStats['cache_hit_rate'] . "%\n";
echo "Total Analyses: " . $analyzerStats['total_analyses'] . "\n";

// Query analyzer cache'ini temizle
$db->clear_query_analyzer_cache();
```

### Memory İstatistikleri

```php
// Bellek istatistikleri
$memoryStats = $db->get_memory_stats();

echo "Current Memory: " . $memoryStats['current_usage'] . " bytes\n";
echo "Peak Memory: " . $memoryStats['peak_usage'] . " bytes\n";
echo "Current Chunk Size: " . $memoryStats['current_chunk_size'] . "\n";
echo "Warning Count: " . $memoryStats['warning_count'] . "\n";
echo "Critical Count: " . $memoryStats['critical_count'] . "\n";
```

### Connection Pool İstatistikleri

```php
// Connection pool istatistikleri
$poolStats = $db->get_pool_stats();

echo "Total Connections: " . $poolStats['total_connections'] . "\n";
echo "Active Connections: " . $poolStats['active_connections'] . "\n";
echo "Idle Connections: " . $poolStats['idle_connections'] . "\n";
echo "Peak Connections: " . $poolStats['peak_connections'] . "\n";
echo "Connection Errors: " . $poolStats['connection_errors'] . "\n";
```

---

Bu API referansı nsql kütüphanesinin tüm özelliklerini kapsamlı bir şekilde açıklamaktadır. Daha fazla bilgi için [Kullanım Klavuzu](kullanim-klavuzu.md) ve [Teknik Detaylar](teknik-detay.md) dokümantasyonlarına bakın.
