# 📚 nsql API Referansı v1.5

## 📑 İçindekiler

- [Ana Sınıflar](#-ana-sınıflar)
- [config Sınıfı](#-config-sınıfı)
- [nsql Sınıfı](#-nsql-sınıfı)
- [Query Builder](#-query-builder)
- [Security Sınıfları](#-security-sınıfları)
- [Migration Manager](#-migration-manager)
- [Traits](#-traits)
- [Loglama, Sorgu Olayları ve Paylaşılan Cache](#-loglama-sorgu-olayları-ve-paylaşılan-cache-v1100)
- [Çoklu Bağlantı ve Okuma/Yazma Ayrımı](#-çoklu-bağlantı-ve-okumayazma-ayrımı-v1110)
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
update(string $sql, array $params = []): int|bool
// THROW_ON_ERROR=true: etkilenen satır sayısı, hata → QueryException; false: true/false (UPGRADE.md)
// Örnek: $db->update("UPDATE users SET name = ? WHERE id = ?", ['John Doe', 1]);

// Toplu veri güncelleme (başarısız satırda rollback + QueryException)
batch_update(string $table, array $data, string $key_column = 'id', bool $use_transaction = true): int
// Örnek: $count = $db->batch_update('users', [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']]);

// Veri silme (dönüş: update() ile aynı)
delete(string $sql, array $params = []): int|bool
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
get_yield(string $query, array $params = [], ?bool $unbuffered = null): Generator
// unbuffered=true (veya YIELD_UNBUFFERED=true): tek sorgu, MySQL unbuffered, sabit bellek;
// akış sürerken aynı örnekte başka sorgu RuntimeException verir.
// unbuffered=false (1.x varsayılanı): LIMIT/OFFSET ile parça parça okuma.
// En dış seviyede LIMIT/OFFSET içeren sorgu kabul edilmez (subquery'deki LIMIT serbest).
// Örnek: foreach ($db->get_yield("SELECT * FROM users", [], true) as $user) { ... }

// Keyset chunk (v1.6.0+): OFFSET yok, satır atlama/tekrar yok, döngü içinde yazma serbest
chunk_by_id(string $query, array $params = [], string $column = 'id', int $size = 1000): Generator
// Sorgu türetilmiş tabloya sarılır: SELECT * FROM (<sorgu>) nsql_chunk WHERE id > ? ORDER BY id LIMIT n
// Kolon sonuç kümesinde olmalı ve benzersiz olmalı. Sorgunun kendi ORDER BY / LIMIT'i olmamalı.
// Örnek: foreach ($db->chunk_by_id("SELECT id, name FROM users", [], 'id', 500) as $rows) { ... }

// Chunked fetch (LIMIT/OFFSET; birincil anahtar varsa chunk_by_id tercih edin)
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
// commit()/rollback() sunucuda açık transaction yoksa (DDL implicit commit) exception fırlatmaz.

// Callable ile transaction (v1.8.0+): başarıda commit, exception'da rollback + yeniden fırlatma
transaction(callable $fn, ?int $attempts = null): mixed
// - İç içe çağrılar SAVEPOINT kullanır.
// - Callable içinde sorgu hataları her zaman QueryException (THROW_ON_ERROR geçici olarak açık).
// - 1213 (deadlock) / 1205 (lock wait timeout) / SQLSTATE 40001: en dış seviyede baştan tekrar
//   (attempts ?? TRANSACTION_RETRY_ATTEMPTS, varsayılan 1). Callable tekrar çalışabilir.
// Örnek:
// $order_id = $db->transaction(function (nsql $db) use ($data) {
//     $id = $db->insert('INSERT INTO orders (user_id) VALUES (?)', [$data['user_id']]);
//     $db->update('UPDATE stock SET qty = qty - 1 WHERE product_id = ?', [$data['product_id']]);
//     return $id;
// }, attempts: 3);

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
// Başarı: callable sonucu. Debug: orijinal exception. THROW_ON_ERROR=true: RuntimeException($generic_message)
// fırlatır (orijinal hata getPrevious()). THROW_ON_ERROR=false: aynı RuntimeException'ı döndürür (eski davranış).

// Hata modeli (v1.7.0+)
throw_on_error(): bool
set_throw_on_error(?bool $enabled): static   // null = THROW_ON_ERROR ayarı
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
where(string|callable $column, ?string $operator = null, mixed $value = null): self
// 'IN' / 'NOT IN' + dizi, '=' / 'IS' + null => IS NULL, '!=' / '<>' / 'IS NOT' + null => IS NOT NULL
// callable => parantezli grup: ->where(fn ($q) => $q->where('a', '=', 1)->or_where('b', '=', 2))
or_where(string|callable $column, ?string $operator = null, mixed $value = null): self
where_in(string $column, array $values): self       // boş dizi => hiçbir satır
where_not_in(string $column, array $values): self   // boş dizi => tüm satırlar
or_where_in(string $column, array $values): self
where_null(string $column): self
where_not_null(string $column): self
or_where_null(string $column): self
where_between(string $column, mixed $min, mixed $max): self
where_not_between(string $column, mixed $min, mixed $max): self
or_where_between(string $column, mixed $min, mixed $max): self
when(mixed $condition, callable $callback, ?callable $default = null): self   // callback($builder, $condition)
allow_empty_strings(bool $allow = true): self       // varsayılan: QUERY_BUILDER_ALLOW_EMPTY_STRING (true)

// Açık raw ifade (v1.9.0+): where() değeri, insert/update/upsert değeri veya select() kolonu
query_builder::raw(string $sql, array $bindings = []): raw_expression
// ->update(['qty' => query_builder::raw('qty + :inc', ['inc' => 1])])
// Raw SQL doğrulanmaz; kullanıcı girdisi yalnızca $bindings ile verilmelidir.

// ORDER BY clause
order_by(string $column, string $direction = 'ASC'): self

// LIMIT / OFFSET
limit(int $limit): self
offset(int $offset): self   // LIMIT olmadan da çalışır

// JOIN clause
join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self

// Sorguyu çalıştırma
get(): array

// İlk sonucu alma
first(): ?object

// Yardımcılar (v1.9.0+)
count(string $column = '*'): int          // GROUP BY / UNION / LIMIT varsa alt sorgu üzerinden sayar
exists(): bool
pluck(string $column, ?string $key = null): array
value(string $column): mixed              // ilk satırın değeri, yoksa null
paginate(int $per_page = 15, int $page = 1): array  // {data, total, per_page, current_page, last_page}

// Yazma işlemleri (v1.9.0+) — table() ile düz tablo gerekir; JOIN/UNION/GROUP/ORDER/LIMIT desteklenmez.
// Hata durumunda THROW_ON_ERROR'dan bağımsız olarak QueryException fırlatılır.
insert(array $data): int|string           // eklenen ID
insert_many(array $rows): int             // eklenen satır sayısı (büyük veri parçalanır, tek transaction)
update(array $data, bool $allow_without_where = false): int   // etkilenen satır
delete(bool $allow_without_where = false): int                // silinen satır
// WHERE olmadan update()/delete() LogicException verir; tüm tablo için ikinci parametre true olmalı.
upsert(array $rows, array $update_columns, array $unique_by = []): int
// MySQL: ON DUPLICATE KEY UPDATE; PostgreSQL/SQLite: ON CONFLICT ($unique_by) DO UPDATE ($unique_by zorunlu)
// $update_columns: ['name', 'qty'] (yeni değer) veya ['qty' => query_builder::raw('qty + 1')]

// SQL sorgusunu alma (test için)
get_query(): string
```

`nsql::statement(string $sql, array $params = []): int` — yazma sorgusunu çalıştırıp etkilenen satır sayısını döndürür; hata durumunda her zaman `QueryException` (v1.9.0+).

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
// options: table, max_requests, window, burst (varsayılan: RATE_LIMIT_* config)
$limiter = new rate_limiter(?nsql $db = null, ?callable $clock = null, array $options = []);

// Token bucket kontrolü (satır SELECT ... FOR UPDATE ile kilitlenir)
$allowed = $limiter->check_rate_limit(string $identifier, string $request_type = 'default'): bool

// Tablo kurulumu
$limiter->install(): void
rate_limiter::schema_sql(string $table = 'rate_limits'): string

// Saniyede eklenen token (max_requests / window)
$limiter->refill_rate(): float
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
// Yollar null ise MIGRATIONS_PATH / SEEDS_PATH veya <proje kökü>/database/{migrations,seeds}
$manager = new migration_manager(nsql $db, ?string $migrations_path = null, ?string $seeds_path = null);
$manager->set_migrations_table(string $table): void

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

Pool anahtarları: `DB_MIN_CONNECTIONS` ile `MIN_CONNECTIONS` (ve benzer `DB_*` alias'ları) birbirinin yerine kullanılabilir.

### Varsayılanlar (`config::default_values()`)

| Anahtar | Default |
|---------|---------|
| `MIN_CONNECTIONS` / `DB_MIN_CONNECTIONS` | `2` |
| `MAX_CONNECTIONS` / `DB_MAX_CONNECTIONS` | `15` |
| `HEALTH_CHECK_INTERVAL` | `60` |
| `CONNECTION_IDLE_TIMEOUT` | `600` |
| `TRANSACTION_RETRY_ATTEMPTS` | `1` (`transaction()` için deadlock/lock wait'te toplam deneme) |
| `THROW_ON_ERROR` | `false` (`true`: tüm sorgu metotları hata durumunda `QueryException`; v2.0.0'da varsayılan `true`, bkz. `UPGRADE.md`) |
| `YIELD_UNBUFFERED` | `false` (`true`: `get_yield()` streaming; v2.0.0'da varsayılan `true`) |
| `MEMORY_WARNING_RATIO` / `MEMORY_CRITICAL_RATIO` | `0.75` / `0.9` (`memory_limit` oranı) |
| `MEMORY_LIMIT_WARNING` / `MEMORY_LIMIT_CRITICAL` | ayarsız (ayarlanırsa oran yerine mutlak byte) |
| `CONNECTION_PING_IDLE_SECONDS` | `30` (bağlantı bu kadar saniye boşta kaldıysa sorgudan önce `SELECT 1`; aksi halde kopma 2006/2013 ile yakalanıp yeniden bağlanılır; negatif = hiç ping yok) |
| `CONNECTION_TIMEOUT` | `5` |
| `QUERY_CACHE_TIMEOUT` | `1800` |
| `STATEMENT_CACHE_LIMIT` | `150` |
| `MIN_CHUNK_SIZE` / `MAX_CHUNK_SIZE` | `200` / `15000` |

Kaynak: `config` class constant'ları — tek doğruluk kaynağı (#27).

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

DB_MIN_CONNECTIONS=2
DB_MAX_CONNECTIONS=15

DEBUG_MODE=false
SECURITY_STRICT_MODE=true
ENCRYPTION_KEY=your_secret_key_here
# Production: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
# storage/keys/encryption.key asla commit edilmemeli (bkz. storage/keys/README.md)

STATEMENT_CACHE_LIMIT=150
QUERY_CACHE_SIZE_LIMIT=200

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

## 🔌 Loglama, Sorgu Olayları ve Paylaşılan Cache (v1.10.0+)

### PSR-3 logger (Monolog)

```php
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$log = new Logger('nsql');
$log->pushHandler(new StreamHandler(__DIR__ . '/storage/logs/nsql.log', Logger::WARNING));

$db->set_logger($log);   // null = dahili dosya logger'ı (LOG_FILE)
```

Hatalar `error`, yavaş sorgular `warning`, debug çıktıları `debug` seviyesinde iletilir. Context içindeki parametreler maskelenmiştir (`SENSITIVE_KEYS`).

### Sorgu dinleyicileri

```php
use nsql\database\events\query_event;

$db->on_query(function (query_event $e): void {
    // $e->sql, $e->params (maskeli), $e->duration_ms, $e->row_count (unbuffered akışta null),
    // $e->success, $e->error (?Throwable), $e->driver
    $profiler->add($e->sql, $e->duration_ms);
});

$db->clear_query_listeners();
```

Olay yalnızca veritabanına giden sorgularda üretilir; cache'ten dönen sonuçlar için üretilmez. Dinleyicide fırlatılan exception sorgu çağrısına yayılır.

### Yavaş sorgu logu

```env
SLOW_QUERY_THRESHOLD_MS=250   # 0 = kapalı (varsayılan)
```

Eşiği aşan her sorgu `Yavaş sorgu` mesajıyla `warning` seviyesinde loglanır (`sql`, maskeli `params`, `duration_ms`, `threshold_ms`, `row_count`).

### PSR-16 query cache store

```php
// Herhangi bir PSR-16 uygulaması: symfony/cache Psr16Cache, Laravel Repository, vb.
$db->set_query_cache_store($psr16Cache, prefix: 'myapp_qc_');
```

`QUERY_CACHE_ENABLED=true` gerekir. Process içi LRU cache birinci seviye olarak kalır; ıskalamada paylaşılan store'a bakılır. Yazma sonrası tablo/tag/global geçersiz kılma tüm süreçlere yansır (store'da tutulan sürüm token'larıyla). Transaction içindeki yazmalar commit sonrası tekrar geçersiz kılınır. Store hataları sorguyu bozmaz (okuma = miss, yazma = yok sayılır).

## 🔀 Çoklu Bağlantı ve Okuma/Yazma Ayrımı (v1.11.0+)

### İsimlendirilmiş bağlantılar

```php
use nsql\database\connection_manager;
use nsql\database\nsql;

connection_manager::add('reporting', [
    'host' => 'report-db', 'db' => 'reports', 'user' => 'ro', 'pass' => '...',
    // 'port', 'driver', 'charset', 'debug', 'read' (replica ayarı) da verilebilir
]);

$main = nsql::connection();            // 'default' → DB_* değerleri
$reports = nsql::connection('reporting');
```

- Her isim için süreçte tek örnek tutulur (ilk kullanımda açılır); transaction, hata durumu ve statement cache bağlantılar arasında paylaşılmaz.
- `add()` yapılmamış isimler ortamdan okunur: `DB_REPORTING_HOST`, `DB_REPORTING_NAME`, `DB_REPORTING_USER`, `DB_REPORTING_PASS`, `DB_REPORTING_PORT`, `DB_REPORTING_DRIVER`, `DB_REPORTING_CHARSET`. Tanımlı olmayan alanlar `DB_*` değerlerinden gelir; hiçbiri yoksa `InvalidArgumentException`.
- `connection_manager::set($name, $nsql)` hazır örneği kaydeder, `purge($name)` bağlantıyı bırakır, `reset()` her şeyi sıfırlar.
- `new nsql(...)` artık `port:` parametresi de alır.

### Okuma/yazma ayrımı

```env
READ_WRITE_SPLIT=true
DB_READ_HOST=replica1,replica2   # birden fazlaysa rastgele seçilir
# DB_READ_PORT / DB_READ_USER / DB_READ_PASS / DB_READ_NAME (verilmezse DB_* kullanılır)
READ_WRITE_STICKY=true
```

veya örnek bazında:

```php
$db->set_read_replica(['host' => ['replica1', 'replica2'], 'user' => 'ro', 'pass' => '...']);
$db->set_read_replica(null);   // ayrımı kapat
```

Yönlendirme kuralları:

| Sorgu | Hedef |
|-------|-------|
| `SELECT`, `WITH` (DML içermeyen), `SHOW`, `DESCRIBE`, `EXPLAIN`; `get_yield()` dahil | replica |
| `INSERT` / `UPDATE` / `DELETE` / DDL | primary |
| Transaction içindeki her sorgu | primary |
| `FOR UPDATE`, `FOR SHARE`, `LOCK IN SHARE MODE` | primary |
| Bu örnekte yazma yapıldıktan sonraki okumalar (`READ_WRITE_STICKY=true`) | primary |

- `stick_to_primary(true|false)` sticky durumunu elle yönetir (ör. istek başında `false`).
- Query cache, `on_query()` dinleyicileri, PSR-3 logger ve `THROW_ON_ERROR` primary'de kalır; replica'daki hata `get_last_error()` / `QueryException` olarak primary üzerinden görünür.
- Replica'ya bağlanılamazsa `warning` loglanır ve sorgular primary'de çalışır (`uses_read_replica()` false döner).

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
