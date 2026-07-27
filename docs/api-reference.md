# 📚 nsql API Referansı v1.5

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

// .env dosyasının okunacağı uygulama kökü (autoload sonrası, ilk config::get öncesi)
config::set_project_root(?string $path): void

// Değer alma (.env ve ortam değişkeni anahtarları büyük harf: DB_HOST vb.)
config::get(string $key, mixed $default = null): mixed

// Değer ayarlama
config::set(string $key, mixed $value): void

// Değer kontrolü
config::has(string $key): bool

// Tüm yapılandırma
config::all(): array

// Proje kök dizini (bootstrap sonrası)
config::get_project_root(): string

// .env önbelleğini sıfırlayıp yeniden yükler
config::refresh(): void
```

#### Not — Veritabanı ve yapılandırma anahtarları

Veritabanı bilgileri `.env` içinde `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`, `DB_PORT`, `DB_DRIVER` olarak tanımlanır. Kodda `config::get('db_host')` kullanılır (içeride `DB_HOST` okunur). Sayısal ve boolean sabitler `config` sınıfının `public const` alanlarında tanımlıdır (ör. `config::default_chunk_size`).

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
    ?bool $debug = null,
    ?string $driver = null
)
```

#### Temel Metodlar

```php
// Sorgu çalıştırma
query(string $query, ?int $fetch_mode = null, mixed ...$fetch_mode_args): PDOStatement|false
// Örnek: $stmt = $db->query("SELECT * FROM users WHERE id = ?", [1]);

// Veri ekleme (son insert ID döndürür)
insert(string $sql, array $params = []): int|false
// Örnek: $id = $db->insert("INSERT INTO users (name, email) VALUES (?, ?)", ['John', 'john@example.com']);

// Toplu veri ekleme
batch_insert(string $table, array $data, bool $use_transaction = true): int
// Örnek: $count = $db->batch_insert('users', [['name' => 'John'], ['name' => 'Jane']]);

// Veri güncelleme
update(string $sql, array $params = []): bool
// Örnek: $db->update("UPDATE users SET name = ? WHERE id = ?", ['John Doe', 1]);

// Toplu veri güncelleme
batch_update(string $table, array $data, string $key_column = 'id', bool $use_transaction = true): int
// Örnek: $count = $db->batch_update('users', [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']]);

// Veri silme
delete(string $sql, array $params = []): bool
// Örnek: $db->delete("DELETE FROM users WHERE id = ?", [1]);

// Tek satır alma
get_row(string $query, array $params = []): ?object
// Not: LIMIT 1 otomatik eklenir, last_results tek elemanlı dizi olarak set edilir
// Örnek: $user = $db->get_row("SELECT * FROM users WHERE id = ?", [1]);

// Tüm sonuçları alma
get_results(string $query, array $params = []): array
// Not: last_results tüm sonuçlar olarak set edilir, debug paneli için
// Örnek: $users = $db->get_results("SELECT * FROM users WHERE active = ?", [1]);

// Generator ile sonuçları alma (bellek dostu)
get_yield(string $query, array $params = []): Generator
// Örnek: foreach ($db->get_yield("SELECT * FROM users") as $user) { ... }

// Chunked fetch (büyük veri setleri için)
get_chunk(string $query, array $params = [], ?int $chunk_size = null): Generator
// Örnek: foreach ($db->get_chunk("SELECT * FROM users", [], 1000) as $chunk) { ... }
```

#### Transaction Metodları

```php
// Transaction başlatma (nested transaction destekler)
begin(): void
begin_transaction(): void  // Alias

// Transaction commit
commit(): bool
commit_transaction(): bool  // Alias

// Transaction rollback
rollback(): bool
rollback_transaction(): bool  // Alias

// Örnek:
$db->begin();
try {
    $db->insert("INSERT INTO users (name) VALUES (?)", ['John']);
    $db->insert("INSERT INTO posts (user_id, title) VALUES (?, ?)", [$id, 'Post']);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

#### Utility Metodları

```php
// Son insert ID
insert_id(): int|string

// Son hata
get_last_error(): ?string

// Connection pool istatistikleri
get_pool_stats(): array

// Memory istatistikleri
get_memory_stats(): array

// Cache istatistikleri
get_all_cache_stats(): array
get_cache_stats(): array
get_statement_cache_stats(): array

// Tüm istatistikler
get_all_stats(): array

// Query Builder instance oluşturma
table(?string $table = null): query_builder

// Cache işlemleri
preload_query(string $query, array $params = [], array $tags = [], array $tables = []): bool
warm_cache(bool $force = false): array

// Debug bilgileri
log_debug_info(string $message, mixed $data = null): void

// Hata yönetimi
handle_exception(Exception|Throwable $e, string $generic_message = 'Bir hata oluştu.'): string
safe_execute(callable $fn, string $generic_message = 'Bir hata oluştu.'): mixed
```

#### Static Metodlar

```php
// HTML escape
nsql::escape_html(mixed $string): string

// CSRF token oluşturma
nsql::generate_csrf_token(): string

// CSRF token doğrulama
nsql::validate_csrf(mixed $token): bool
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
security_manager::escape_html(mixed $string): string

// CSRF token oluşturma
security_manager::generate_csrf_token(): string

// CSRF token doğrulama
security_manager::validate_csrf_token(mixed $token): bool

// SQL parametrelerini doğrulama
security_manager::validate_sql_params(array $params): bool

// Güvenli sorgu hazırlama
security_manager::prepare_safe_query(string $sql, array $params): string
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
use nsql\database\security\security_manager;

// XSS koruması
$safe_html = security_manager::escape_html('<script>alert("xss")</script>');

// CSRF koruması
$token = security_manager::generate_csrf_token();
$is_valid = security_manager::validate_csrf_token($token);
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

Anahtarlar **büyük harf** yazılmalıdır. Tam liste için depodaki `.env.example` dosyasına bakın.

```ini
ENV=production

NSQL_PROJECT_ROOT=/var/www/myapp

DB_HOST=localhost
DB_PORT=3306
DB_NAME=my_database
DB_USER=username
DB_PASS=secret
DB_CHARSET=utf8mb4
DB_DRIVER=mysql

DEBUG_MODE=false
SECURITY_STRICT_MODE=true
ENCRYPTION_KEY=your_secret_key_here
# Production: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
# storage/keys/encryption.key asla commit edilmemeli (bkz. storage/keys/README.md)

STATEMENT_CACHE_LIMIT=100
QUERY_CACHE_SIZE_LIMIT=1000

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
