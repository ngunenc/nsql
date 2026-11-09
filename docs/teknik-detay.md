# 📖 nsql Kütüphanesi Teknik Detayları

## 📑 İçindekiler

- [Mimari Yapı](#-mimari-yapı)
- [Temel Bileşenler](#-temel-bileşenler)
- [Güvenlik Mekanizmaları](#-güvenlik-mekanizmaları)
- [Performans Optimizasyonları](#-performans-optimizasyonları)
- [Test ve Kalite](#-test-ve-kalite)

## 🏗 Mimari Yapı

### Katmanlı Mimari

nsql, SOLID prensiplerini takip eden modüler ve katmanlı bir mimariye sahiptir:

```
src/database/
├── config.php           -> Yapılandırma yönetimi
├── nsql.php            -> Ana PDO wrapper
├── connection_pool.php  -> Bağlantı havuzu
├── query_builder.php    -> Sorgu oluşturucu
├── migration_manager.php -> Migration yönetimi
├── security/           -> Güvenlik bileşenleri
│   ├── audit_logger.php
│   ├── encryption.php
│   ├── rate_limiter.php
│   ├── security_manager.php
│   ├── sensitive_data_filter.php
│   └── session_manager.php
└── traits/             -> Yeniden kullanılabilir özellikler
    ├── cache_trait.php
    ├── connection_trait.php
    ├── debug_trait.php
    ├── query_analyzer_trait.php
    ├── query_parameter_trait.php
    ├── statement_cache_trait.php
    └── transaction_trait.php
```

Her bir bileşen kendi sorumluluğuna sahiptir ve birbirleriyle gevşek bağlıdır (loose coupling).

#### Alt Klasörlerin Sorumlulukları

| Klasör/Dosya         | Açıklama |
|----------------------|----------|
| config.php           | Ortam ve yapılandırma yönetimi |
| nsql.php             | Ana veritabanı erişim katmanı (PDO wrapper) |
| connection_pool.php  | Bağlantı havuzu ve yönetimi |
| query_builder.php    | Dinamik ve güvenli sorgu oluşturucu |
| traits/              | Yeniden kullanılabilir kod özellikleri |
| exceptions/          | Özel hata ve istisna sınıfları |
| migrations/          | Veritabanı migration dosyaları |
| seeds/               | Test/demo verisi üretimi |
| templates/           | Debug ve özel çıktı şablonları |
| security/            | Rate limiting, şifreleme, audit, veri filtreleme |
## 🔧 Temel Bileşenler

### 1. config Yönetimi (config.php)

```php
// Örnek kullanım
Config::set_project_root(__DIR__); // uygulama kökü — .env burada aranır (önerilir)
Config::set_environment('development');
$db_host = Config::get('db_host'); // .env içindeki DB_HOST

// Proje kökü tespiti (set_project_root yoksa):
// NSQL_PROJECT_ROOT → uygulama kökü (.env / composer+autoload) → vendor paketinden kaçınılmış fallback

// Önerilen pratikler:
// - Hassas bilgiler yalnızca .env veya ortam değişkeninde
// - Vendor ile kurulumda set_project_root veya NSQL_PROJECT_ROOT kullanın
// - composer require/update için --prefer-dist (source tree dirty hatasını önler)
```

**Optimizasyon İpuçları:**
- config değerlerini önbellekte tutun
- Environment kontrollerini minimize edin
- Varsayılan değerleri akıllıca belirleyin

### 2. Bağlantı Havuzu (ConnectionPool.php)

```php
// Örnek kullanım
ConnectionPool::initialize([
    'dsn' => 'mysql:host=localhost;dbname=test',
    'username' => 'root',
    'password' => '',
    'options' => [
        PDO::ATTR_PERSISTENT => true
    ]
], 5, 20);

// Önerilen Ayarlar:
// - Min Connections: Ortalama eşzamanlı istek sayısı
// - Max Connections: Peak yük * 1.5
// - Connection Timeout: 15-30 saniye
```

**Performans İpuçları:**
- Persistent bağlantıları etkinleştirin
- Connection timeout değerlerini optimize edin
- Health check aralıklarını workload'a göre ayarlayın
- Idle connection temizleme stratejisini belirleyin

### 3. Sorgu Oluşturucu (QueryBuilder.php)

```php
// Anti-pattern:
$db->query("SELECT * FROM users WHERE id = " . $id);

// Doğru kullanım:
$db->table('users')
   ->select(['id', 'name', 'email'])
   ->where('status', 'active')
   ->order_by('created_at', 'DESC')
   ->limit(10)
   ->get();
```

**Güvenlik ve Performans:**
- Her zaman prepared statements kullanın
- Gereksiz kolon seçiminden kaçının
- İndeks kullanımına dikkat edin
- Karmaşık sorguları optimize edin

### 4. Exception Yönetimi (exceptions/)

Özel hata ve istisna yönetimi için exceptions klasörü kullanılır. Tüm veritabanı hataları merkezi olarak burada ele alınır.

```php
use exceptions\DatabaseException;

try {
    $db->query($sql);
} catch (DatabaseException $e) {
    // Hata loglama ve özel işlem
    error_log($e->getMessage());
}
```

**Best Practice:**
- Tüm hata türleri için ayrı exception sınıfları oluşturun
- Hataları merkezi olarak loglayın

### 5. Migration Yönetimi (migrations/, migration_manager.php)

Veritabanı şema değişiklikleri migration dosyaları ile yönetilir. Migration işlemleri migration_manager.php üzerinden yapılır.

```php
$manager = new migration_manager();
$manager->create('create_users_table');
$manager->migrate(['--force' => true]);
```

**Best Practice:**
- Migration dosyalarını versiyonlayın
- Rollback stratejisi belirleyin
- Migration bağımlılıklarını yönetin

### 6. Seed Yönetimi (seeds/)

Test ve demo verisi üretimi için seed dosyaları kullanılır.

```php
require_once 'src/database/seeds/user_seeder.php';
$userSeeder = new user_seeder();
$userSeeder->run();
```

**Best Practice:**
- Seed dosyalarını test ortamında kullanın
- Gerçek veriye benzer dummy data üretin

### 7. Template Kullanımı (templates/)

Özel çıktı ve debug işlemleri için template dosyaları kullanılır.

```php
require_once 'src/database/templates/debug_template.php';
$template = new debug_template();
echo $template->render($debugData);
```

**Best Practice:**
- Debug ve hata çıktıları için ayrı template dosyaları oluşturun
- Template'leri özelleştirilebilir yapıda tutun

## 🔒 Güvenlik Mekanizmaları

### 1. Security Manager (src/security/SecurityManager.php)

Merkezi güvenlik yönetimi sağlar (opsiyonel `nsql\security` katmanı):

```php
use nsql\security\SecurityManager;

$security = new SecurityManager($db);

// Rate limiting
$security->check_rate_limit(SecurityManager::get_client_ip(), 'api');

// Hassas veri filtresi
$safe = $security->filter_sensitive_data($input);

// Şifreleme
$encrypted = $security->encrypt($data);
```

**Güvenlik Tavsiyeleri:**
- Rate limiting eşiklerini doğru belirleyin
- Şifreleme anahtarlarını düzenli değiştirin
- Audit logları düzenli kontrol edin

### 2. Rate Limiter (src/security/RateLimiter.php)

Veritabanı destekli token bucket; MySQL, PostgreSQL ve SQLite'ta çalışır.

```php
use nsql\security\RateLimiter;
use nsql\security\SecurityManager;

$limiter = new RateLimiter($db, null, ['max_requests' => 100, 'window' => 60, 'burst' => 20]);
$limiter->install(); // veya migration içinde: RateLimiter::schema_sql()

if (! $limiter->check_rate_limit(SecurityManager::get_client_ip(), 'api')) {
    http_response_code(429);
    exit;
}
```

## 🚀 Performans Optimizasyonları

### 1. Query Cache (traits/CacheTrait.php)

Sürücüler: `memory` (süreç içi, varsayılan), `redis`, `memcached`; ayrıca `set_query_cache_store()` ile herhangi bir PSR-16 store.

```env
QUERY_CACHE_ENABLED=true
QUERY_CACHE_DRIVER=redis
QUERY_CACHE_TIMEOUT=3600
```

```php
$result = $db->get_results($query);   // ilk çağrı DB, sonrakiler cache
$stats  = $db->get_all_cache_stats();
```

Yazma sorguları ilgili tabloların cache kayıtlarını geçersiz kılar; paylaşılan store'da bu süreçler arasında da geçerlidir.

Paylaşılan store varken süreç içi (L1) isabette kaydın sürüm token'ları store'dan doğrulanır (`QUERY_CACHE_LOCAL_VERIFY=true`, varsayılan; v2.3.0+). Böylece queue worker, Swoole/RoadRunner gibi uzun ömürlü süreçler başka bir sürecin yazmasından sonra eski veriyi döndürmez. Doğrulama her isabette store'a tek bir `getMultiple` çağrısı yapar; kısa ömürlü PHP-FPM isteklerinde ek maliyeti istemiyorsanız `false` yapabilirsiniz.

`get_results()` sonucu en fazla `QUERY_CACHE_MAX_ROWS` satırsa cache'lenir (tanımlı değilse `QUERY_CACHE_SIZE_LIMIT`, v2.3.0+).

#### Geçersiz kılmanın sınırları

Geçersiz kılma, SQL metninden tablo adlarını çıkararak yapılır. Aşağıdaki durumlarda kütüphane değişikliği **göremez**:

| Durum | Örnek | Çözüm |
|-------|-------|-------|
| View | `SELECT ... FROM v_users` cache'lenir, `users` yazması onu temizlemez | `$db->set_cache_dependency('v_users', ['users'])` |
| FK `ON DELETE/UPDATE CASCADE` | `orders` silinince `order_items` dolaylı değişir | `$db->set_cache_dependency('order_items', ['orders'])` |
| Trigger | `orders` INSERT'i `audit_log`'a yazar | `$db->set_cache_dependency('audit_log', ['orders'])` |
| Ham PDO ile yazma | `$db->get_pdo()->exec(...)` | Sonrasında `$db->invalidate_cache_by_table('tablo')` |
| Başka uygulama / elle SQL | Veritabanına doğrudan yazan başka bir sistem | Paylaşılan store'da `invalidate_cache_by_table()` veya kısa `set_table_ttl()` |

Bağımlılıklar geçişlidir (`orders` → `order_items` → `v_order_totals`) ve örnek bazında tanımlanır; uygulama başlangıcında `Nsql::connection()` örneğine bir kez tanımlayın.

### 2. Statement Cache (traits/StatementCacheTrait.php)

Hazırlanmış statement'lar LRU/LFU ile önbelleklenir (`STATEMENT_CACHE_LIMIT`).

```php
$db->clear_statement_cache();
```

#### Performans Trait ve Bileşenleri

- **cache_trait.php**: Sorgu sonuçlarını ve verileri önbelleğe alır.
  ```php
  $db->withCache(300)->get_results($query);
  ```
- **statement_cache_trait.php**: Hazırlanan SQL ifadelerini LRU algoritması ile önbelleğe alır.
  ```php
  $stmt = $db->prepare($query);
  ```
- **connection_trait.php**: Bağlantı yönetimi ve havuz optimizasyonu sağlar.
  ```php
  $db->ensureConnection();
  ```
- **debug_trait.php**: Sorgu ve performans analizini kolaylaştırır.
  ```php
  $db->debug();
  $stats = $db->get_memory_stats();
  ```
- **query_analyzer_trait.php**: Sorgu analizi ve optimizasyon önerileri sunar.
  ```php
  $db->analyzeQuery($query);
  ```

**Best Practice:**
- Trait dosyalarını modüler ve bağımsız tutun
- Cache ve statement boyutlarını workload'a göre ayarlayın
- Sık kullanılan sorguları ve statementları önceliklendirin
- Performans izleme ve loglama araçlarını düzenli kullanın

## 🧪 Test ve Kalite

Testler `tests/Unit`, `tests/Integration` (MySQL/MariaDB) ve `tests/Portable` (MySQL, PostgreSQL, SQLite) altındadır. Komutlar ve CI eşikleri için README'deki "Test ve Kalite" bölümüne bakın.

```php
$db->transaction(function (Nsql $db) {
    $db->insert('INSERT INTO logs (msg) VALUES (:m)', ['m' => 'test']);
});
```

## 📊 Monitoring ve Debug

```php
$db = new Nsql(debug: true);
$db->get_results('SELECT * FROM users');
$db->debug();                       // son sorgu, parametreler, süre

$stats = $db->get_memory_stats();   // streaming bellek istatistikleri
$pool  = Nsql::get_pool_stats();

// Sorgu olayları ve yavaş sorgu logu (SLOW_QUERY_THRESHOLD_MS)
$db->on_query(function (\nsql\database\events\QueryEvent $e) {
    if ($e->duration_ms > 200) {
        error_log("Yavaş sorgu ({$e->duration_ms} ms): {$e->sql}");
    }
});

// Sağlık kontrolü
$health = (new \Nsql\database\monitoring\HealthCheck($db))->check();
```

## 🔧 Maintenance

### Migration Manager (MigrationManager.php)

```php
$manager = new MigrationManager($db);
$manager->create('create_users_table');   // database/migrations/..._create_users_table.php
$manager->migrate();                      // bekleyenleri uygular
$manager->rollback();                     // son batch'i geri alır
```

CLI karşılıkları: `vendor/bin/nsql migrate:create`, `migrate`, `migrate:rollback`.

## 🔍 Debugging ve Troubleshooting

1. **Bağlantı sorunları**: `ensure_connection()` bağlantıyı doğrular; kopan bağlantılar sorgu sırasında otomatik yeniden kurulur. Elle: `$db->reconnect()`.
2. **Bellek**: Büyük sonuçlarda `get_results()` yerine `get_yield()` veya `chunk_by_id()` kullanın; `clear_statement_cache()` ile statement önbelleğini boşaltın.
3. **Deadlock**: `transaction(callable)` deadlock/serialization hatalarında yeniden dener (`TRANSACTION_RETRY_ATTEMPTS`, ya da `$db->transaction($fn, attempts: 5)`).

## 📈 Ölçeklendirme

### Okuma/Yazma Ayrımı (v1.11.0)

```env
READ_WRITE_SPLIT=true
DB_READ_HOST=replica1.example.com,replica2.example.com
```

```php
$db->set_read_replica(['host' => 'replica1.example.com']); // veya kod içinden
$db->stick_to_primary(true);                               // yazmadan sonra okumaları primary'de tut

$reporting = Nsql::connection('reporting');                // isimlendirilmiş bağlantı
```

Sharding, circuit breaker ve otomatik backup kütüphane kapsamında değildir; ihtiyaç halinde uygulama katmanında kurulmalıdır (bkz. [production-scenarios.md](production-scenarios.md)).

## 🔐 Security Best Practices

```php
// Anti-pattern:
$query = "SELECT * FROM users WHERE id = " . $_GET['id'];

// Güvenli:
$user = $db->get_row('SELECT * FROM users WHERE id = :id', ['id' => (int) $_GET['id']]);
```
## 🎯 Best Practices Özeti

#### Best Practices Özeti

- **Güvenlik**: Prepared statement, input validation, rate limiting, security audit.
- **Performans**: Connection pool, query/statement cache, indeks stratejisi, monitoring.
- **Maintainability**: Temiz kod, düzenli refactoring, kapsamlı test, güncel dokümantasyon.
- **Scalability**: Horizontal scaling, sharding, load balancing, monitoring ve alerting.
- **Backup & Recovery**: Düzenli yedekleme, otomatik recovery, rollback ve point-in-time recovery.
- **Monitoring & Debug**: Sorgu ve kaynak izleme, hata loglama, performans denetimi.

#### Versiyon Detayları

- **v1.0.0 (Güncel)**: İlk kararlı sürüm, temel veritabanı işlemleri, connection pool, query/statement cache, temel güvenlik.
- **v1.1.0 (Planlanan)**: Read/Write splitting, gelişmiş monitoring, circuit breaker, Redis cache, migration iyileştirmeleri.
- **v1.2.0 (Planlanan)**: Otomatik sharding, distributed cache, GraphQL, real-time monitoring, async query.
- **v1.3.0 (Planlanan)**: Schema validation, database proxy, query optimization engine, advanced security, cloud integration.

## 📦 Sürüm Bilgisi

Sürüm geçmişi [CHANGELOG.md](../CHANGELOG.md), geçiş notları [UPGRADE.md](../UPGRADE.md) dosyasındadır. Planlanan işler sabit sürüm listesi yerine [GitHub issues](https://github.com/ngunenc/nsql/issues) üzerinden takip edilir.
