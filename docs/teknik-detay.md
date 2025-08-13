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
└── traits/             -> Yeniden kullanılabilir özellikler
├── exceptions/         -> Özel hata ve istisna yönetimi
├── migrations/         -> Migration dosyaları ve yönetimi
├── seeds/              -> Test ve demo verisi üretimi
├── templates/          -> Debug ve özel çıktı şablonları
├── security/           -> Güvenlik bileşenleri (rate limiter, encryption, audit vs.)
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

### 1. Config Yönetimi (config.php)

```php
// Örnek kullanım
Config::setEnvironment('development');
$dbHost = Config::get('DB_HOST');

// Önerilen Pratikler:
// - Environment bazlı config yönetimi
// - Hassas bilgilerin .env dosyasında tutulması
// - Config değerlerinin tip güvenliği
```

**Optimizasyon İpuçları:**
- Config değerlerini önbellekte tutun
- Environment kontrollerini minimize edin
- Varsayılan değerleri akıllıca belirleyin

### 2. Bağlantı Havuzu (connection_pool.php)

```php
// Örnek kullanım
connection_pool::initialize([
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

### 3. Sorgu Oluşturucu (query_builder.php)

```php
// Anti-pattern:
$db->query("SELECT * FROM users WHERE id = " . $id);

// Doğru kullanım:
$db->table('users')
   ->select(['id', 'name', 'email'])
   ->where('status', 'active')
   ->orderBy('created_at', 'DESC')
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

### 1. Security Manager (security_manager.php)

Merkezi güvenlik yönetimi sağlar:

```php
$security = new security_manager();

// Rate limiting
$security->rateLimiter->check($ip, $route);

// Hassas veri filtresi
$security->dataFilter->sanitize($input);

// Şifreleme
$encrypted = $security->encryption->encrypt($data);
```

**Güvenlik Tavsiyeleri:**
- Rate limiting eşiklerini doğru belirleyin
- Şifreleme anahtarlarını düzenli değiştirin
- Audit logları düzenli kontrol edin

### 2. Rate Limiter (rate_limiter.php)

```php
// Yapılandırma
const WINDOW_SIZE = 3600; // 1 saat
const MAX_REQUESTS = 1000;

// Kullanım
$limiter = new rate_limiter();
if ($limiter->checkLimit($ip)) {
    // İşleme devam et
}
```

**Optimizasyon:**
- Redis/Memcached ile distributed rate limiting
- Adaptive rate limiting stratejileri
- IP bazlı whitelist/blacklist

#### Güvenlik Bileşenleri (security/)

- **audit_logger.php**: Tüm veritabanı işlemlerini ve güvenlik olaylarını loglar.
  ```php
  $logger = new audit_logger();
  $logger->log('login_attempt', $userId);
  ```
- **encryption.php**: Hassas verileri şifreler ve çözer.
  ```php
  $enc = new encryption();
  $cipher = $enc->encrypt($data);
  $plain = $enc->decrypt($cipher);
  ```
- **query_analyzer.php**: Sorgu güvenliği ve performans analizi sağlar.
  ```php
  $analyzer = new query_analyzer();
  $analyzer->analyze($query);
  ```
- **sensitive_data_filter.php**: Hassas veri girişlerini filtreler ve maskeleyerek saklar.
  ```php
  $filter = new sensitive_data_filter();
  $safe = $filter->sanitize($input);
  ```
- **session_manager.php**: Oturum yönetimi ve güvenli oturum açma işlemleri sağlar.
  ```php
  $session = new session_manager();
  $session->start($userId);
  ```

**Best Practice:**
- Tüm güvenlik bileşenlerini merkezi olarak yönetin
- Log ve şifreleme anahtarlarını düzenli olarak güncelleyin
- Hassas veri girişlerinde filtreleme ve maskeleme uygulayın
- Oturum yönetiminde timeout ve güvenlik kontrolleri ekleyin

## 🚀 Performans Optimizasyonları

### 1. Query Cache (cache_trait.php)

```php
// Cache stratejileri
const CACHE_STRATEGIES = [
    'memory' => MemoryCache::class,
    'redis' => RedisCache::class,
    'file' => FileCache::class
];

// Örnek kullanım
$result = $db->withCache(300)->get_results($query);
```

**Cache Optimizasyonları:**
- TTL değerlerini veri değişim sıklığına göre ayarlayın
- Cache invalidation stratejilerini belirleyin
- Cache hit/miss oranlarını monitör edin

### 2. Statement Cache (statement_cache_trait.php)

```php
// LRU Cache yapılandırması
const STATEMENT_CACHE_SIZE = 100;

// Otomatik statement cache
$stmt = $db->prepare($query); // Önbellekte varsa kullanır
```

**Performans İpuçları:**
- Cache boyutunu workload'a göre ayarlayın
- Sık kullanılan statementları önceliklendirin
- Memory kullanımını monitör edin

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

### 1. Unit Tests (tests/nsql_test.php)

```php
class NsqlTest extends TestCase
{
    public function testTransactions()
    {
        $db->begin();
        try {
            // Test senaryosu
            $this->assertTrue($result);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            $this->fail($e->getMessage());
        }
    }
}
```

#### Test ve Kalite Bileşenleri

- **Unit Testler (tests/nsql_test.php)**: Tüm temel ve edge-case senaryoları için birim testler içerir.
  ```php
  class NsqlTest extends TestCase {
      public function testUserInsert() {
          $result = $db->table('users')->insert(['name' => 'Test']);
          $this->assertTrue($result);
      }
  }
  ```
- **Seed Testleri (seeds/)**: Test ortamı için dummy veri üretimi sağlar.
  ```php
  $userSeeder = new user_seeder();
  $userSeeder->run();
  ```
- **Exception Testleri (exceptions/)**: Hata ve istisna senaryoları için özel testler yazılır.
  ```php
  $this->expectException(DatabaseException::class);
  $db->query('INVALID SQL');
  ```

**Best Practice:**
- Her ana fonksiyon ve modül için birim test yazın
- Exception ve edge-case senaryolarını test edin
- Seed ve migration işlemlerini test ortamında doğrulayın
- Test coverage ve kalite metriklerini takip edin

## 📊 Monitoring ve Debug

### Debug Trait (debug_trait.php)

```php
// Debug modunu etkinleştirmek için
$db = new nsql(debug: true);

// Sorgu analizi ve hata ayıklama
$db->debug(); // Sorgu, parametreler ve hata bilgisi

// Memory kullanımı
$stats = $db->get_memory_stats();
```

#### Monitoring ve Debug Bileşenleri

- **debug_trait.php**: Sorgu analizi, hata ayıklama ve performans izleme sağlar.
  ```php
  $db = new nsql(debug: true);
  $db->debug();
  $stats = $db->get_memory_stats();
  ```
- **query_analyzer_trait.php**: Sorgu performansını ve güvenliğini analiz eder.
  ```php
  $db->analyzeQuery($query);
  ```
- **audit_logger.php**: Tüm kritik işlemleri ve hataları loglar.
  ```php
  $logger = new audit_logger();
  $logger->log('slow_query', $query);
  ```

**Best Practice:**
- Sorgu ve işlem sürelerini düzenli olarak izleyin
- Yavaş sorguları ve hataları loglayın
- Kaynak kullanımı ve performans metriklerini takip edin
- Düzenli performans denetimleri yapın

## 🔧 Maintenance

### Migration Manager (migration_manager.php)

```php
// Migration oluşturma
$manager->create('create_users_table');

// Migration çalıştırma stratejileri
$manager->migrate(['--pretend' => true]); // Dry run
$manager->migrate(['--force' => true]); // Tehlikeli operasyonları onayla
```

#### Bakım Bileşenleri (Maintenance)

- **migration_manager.php**: Migration işlemlerini ve şema güncellemelerini yönetir.
  ```php
  $manager = new migration_manager();
  $manager->create('add_email_column');
  $manager->migrate(['--pretend' => true]);
  ```
- **schema/README.md**: Şema değişiklikleri ve migration geçmişi için dokümantasyon sağlar.
- **Rollback ve Backup**: Migration rollback ve veritabanı yedekleme işlemleri için özel fonksiyonlar kullanılabilir.
  ```php
  $manager->rollback('2025_05_24_000001_create_users_table');
  $backup = new DatabaseBackup($db);
  $backup->createSnapshot();
  ```

**Best Practice:**
- Düzenli şema yedekleri alın
- Migration ve rollback işlemlerini test edin
- Zero-downtime migration planları oluşturun
- Migration bağımlılıklarını ve geçmişini dokümantate edin

## 🔍 Debugging ve Troubleshooting

### Yaygın Sorunlar ve Çözümleri

1. **Bağlantı Sorunları**
```php
try {
    $db->ensureConnection();
} catch (ConnectionException $e) {
    // Retry logic
    $db->reconnect(['timeout' => 5]);
}
```

2. **Memory Leaks**
```php
// Resource temizleme
$db->disconnect();
$db->clearStatementCache();
$db->clearQueryCache();
```

3. **Deadlock Yönetimi**
```php
$db->setDeadlockRetries(3)
   ->setDeadlockWait(200); // ms
```

4. **Hata Loglama ve Analiz**
```php
$logger = new audit_logger();
$logger->log('error', $errorMessage);
```

#### Debugging ve Troubleshooting Bileşenleri

- **Bağlantı Sorunları**: Bağlantı kontrolü ve otomatik yeniden bağlanma mekanizması.
  ```php
  try {
      $db->ensureConnection();
  } catch (ConnectionException $e) {
      $db->reconnect(['timeout' => 5]);
  }
  ```
- **Memory Leak Yönetimi**: Kaynak temizleme ve önbellek boşaltma işlemleri.
  ```php
  $db->disconnect();
  $db->clearStatementCache();
  $db->clearQueryCache();
  ```
- **Deadlock Yönetimi**: Deadlock durumunda otomatik retry ve bekleme stratejisi.
  ```php
  $db->setDeadlockRetries(3)
     ->setDeadlockWait(200); // ms
  ```
- **Hata Loglama ve Analiz**: Tüm hata ve istisnalar için merkezi loglama.
  ```php
  $logger = new audit_logger();
  $logger->log('error', $errorMessage);
  ```

**Best Practice:**
- Bağlantı ve kaynak yönetimini otomatikleştirin
- Deadlock ve memory leak senaryolarını test edin
- Hataları merkezi olarak loglayın ve analiz edin
- Sorun çözümü için düzenli troubleshooting dokümantasyonu oluşturun

## 📈 Ölçeklendirme

#### Ölçeklendirme (Scalability)

- **Horizontal Scaling**: Okuma/yazma ayrımı ve birden fazla sunucu ile ölçeklenebilirlik.
  ```php
  $db->setReadWriteSplit(true);
  $db->addReadServer('slave1.example.com');
  $db->addReadServer('slave2.example.com');
  ```
- **Sharding Strategy**: Veritabanı shard anahtarı ve shard sunucuları ile dağıtık yapı.
  ```php
  $db->setShardKey('user_id');
  $db->addShard('shard1', ['range' => [1, 1000]]);
  $db->addShard('shard2', ['range' => [1001, 2000]]);
  ```
- **Load Balancing**: Okuma/yazma işlemlerinde yük dengeleme için sunucu ekleme ve yönetimi.
  ```php
  $db->addReadServer('slave3.example.com');
  $db->setLoadBalancerStrategy('round_robin');
  ```

**Best Practice:**
- Okuma/yazma ayrımı ve shard anahtarı seçiminde iş yükünü analiz edin
- Sunucu ekleme ve çıkarma işlemlerini otomatikleştirin
- Load balancing ve monitoring araçlarını entegre edin
- Dağıtık yapıda veri tutarlılığını ve yedekliliği sağlayın

## 🔐 Security Best Practices

### 1. Input Validation

```php
// Anti-pattern:
$query = "SELECT * FROM users WHERE id = " . $_GET['id'];

// Secure pattern:
$id = $filter->sanitize($_GET['id'], 'int');
$user = $db->get_row("SELECT * FROM users WHERE id = :id", ['id' => $id]);
```

### 2. Prepared Statements

```php
$stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
$stmt->execute(['email' => $email]);
```

### 3. Rate Limiting

```php
// Yapılandırma
const WINDOW_SIZE = 3600; // 1 saat
const MAX_REQUESTS = 1000;

// Kullanım
$limiter = new rate_limiter();
if ($limiter->checkLimit($ip)) {
    // İşleme devam et
}
```

### 4. Access Control

```php
// Role-based query filtering
$db->addQueryFilter(function($query) use ($userRole) {
    if ($userRole !== 'admin') {
        return $query->where('is_public', true);
    }
    return $query;
});
```

### 5. Audit Logging

```php
$logger->log('security_event', $eventData);
```

**Best Practice:**
- Tüm girişleri filtreleyin ve doğrulayın
- Prepared statement ve parametreli sorgu kullanın
- Rate limiting ve erişim kontrolü uygulayın
- Güvenlik loglarını düzenli analiz edin
- Şifreleme anahtarlarını ve erişim politikalarını güncel tutun

## 📊 Monitoring ve Metrics

### Performance Metrics

```php
// Query timing ve detaylı performans ölçümü için (planlanan özellik)
// $db->enableQueryTiming();
// $timing = $db->getLastQueryTiming();

// Connection pool istatistikleri
$poolStats = nsql::get_pool_stats();
$activeConnections = $poolStats['active_connections'];
```

### Health Checks

```php
// Health check ve detaylı tanı fonksiyonları planlanmaktadır.
// $health = $db->getHealthStatus();
// $diagnostics = $db->getDiagnostics([...]);
```

#### Monitoring ve Metrics

- **Query Timing**: Sorgu sürelerini ve performans metriklerini izleyin.
  ```php
  $db->enableQueryTiming();
  $timing = $db->getLastQueryTiming();
  ```
- **Connection Pool Stats**: Aktif bağlantı ve havuz istatistiklerini takip edin.
  ```php
  $poolStats = nsql::get_pool_stats();
  $activeConnections = $poolStats['active_connections'];
  ```
- **Health Checks**: Veritabanı ve sistem sağlığını düzenli olarak kontrol edin.
  ```php
  $health = $db->getHealthStatus();
  $diagnostics = $db->getDiagnostics([...]);
  ```
- **Resource Usage**: Bellek, CPU ve cache kullanımı gibi kaynak metriklerini izleyin.
  ```php
  $stats = $db->get_memory_stats();
  $cacheStats = $db->getCacheStats();
  ```

**Best Practice:**
- Sorgu ve bağlantı metriklerini düzenli olarak analiz edin
- Health check ve resource usage için otomasyon kurun
- Performans ve hata metriklerini merkezi olarak toplayın
- Kritik eşikler için uyarı ve raporlama mekanizması oluşturun

## 🔄 Recovery ve Backup

#### Recovery ve Backup

- **Otomatik Recovery**: Bağlantı ve sorgu hatalarında otomatik retry ve circuit breaker mekanizması.
  ```php
  $db->setRetryPolicy([
      'max_attempts' => 3,
      'initial_wait' => 100,
      'multiplier' => 2
  ]);
  $db->enableCircuitBreaker([
      'failure_threshold' => 5,
      'reset_timeout' => 30
  ]);
  ```
- **Backup Stratejileri**: Anlık yedekleme ve geri yükleme işlemleri.
  ```php
  $backup = new DatabaseBackup($db);
  $backup->createSnapshot();
  $backup->restoreToPoint('2025-05-27 12:00:00');
  ```
- **Point-in-Time Recovery**: Belirli bir zamana geri dönebilme özelliği.

**Best Practice:**
- Otomatik recovery ve retry politikalarını yapılandırın
- Düzenli ve zamanlanmış yedekler alın
- Geri yükleme işlemlerini test edin
- Kritik veriler için point-in-time recovery planı oluşturun

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

#### Performance Tuning Checklist

- **Query Optimizasyonu**: Sorgu performansını artırmak için EXPLAIN, indeks ve refactoring kullanın.
  ```php
  $db->analyzeQuery('SELECT * FROM users WHERE ...');
  // EXPLAIN çıktısını inceleyin
  ```
- **Resource Yönetimi**: Bağlantı havuzu, memory ve cache kullanımı izlenmeli.
  ```php
  $poolStats = nsql::get_pool_stats();
  $activeConnections = $poolStats['active_connections'];
  ```
- **Error Handling**: Yapılandırılmış loglama, hata toplama ve uyarı eşikleri tanımlayın.
  ```php
  $logger->log('error', $errorMessage);
  // Alert mekanizması ile kritik hataları bildirin
  ```
- **Cache ve Statement Yönetimi**: Cache hit/miss oranlarını ve statement cache boyutunu izleyin.
  ```php
  $db->getCacheStats();
  $db->getStatementCacheStats();
  ```

**Best Practice:**
- Sorgu ve indeks optimizasyonunu düzenli olarak gözden geçirin
- Kaynak ve hata yönetimi için otomasyon ve izleme araçları kullanın
- Performans metriklerini ve logları düzenli analiz edin
- Kritik işlemler için alert ve raporlama mekanizması kurun
