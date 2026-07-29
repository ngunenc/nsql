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

## 🔧 Temel Bileşenler

### 1. config Yönetimi (config.php)

```php
// Örnek kullanım
config::set_project_root(__DIR__); // uygulama kökü — .env burada aranır (önerilir)
config::set_environment('development');
$db_host = config::get('db_host'); // .env içindeki DB_HOST

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
   ->order_by('created_at', 'DESC')
   ->limit(10)
   ->get();
```

**Güvenlik ve Performans:**
- Her zaman prepared statements kullanın
- Gereksiz kolon seçiminden kaçının
- İndeks kullanımına dikkat edin
- Karmaşık sorguları optimize edin

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
// Cache yapılandırması config üzerinden yönetilir
$result = $db->get_results($query);
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

**Test Stratejileri:**
- Her özellik için unit test yazın
- Edge case'leri test edin
- Performance testleri ekleyin
- Coverage hedeflerini belirleyin

## 📊 Monitoring ve Debug

### Debug Trait (debug_trait.php)

```php
// Debug modu etkinleştirme
$db->enableDebug();

// Sorgu analizi
$db->debug(); // Sorgu, parametreler ve timing bilgisi

// Memory kullanımı
$stats = $db->get_memory_stats();
```

**Monitoring Tavsiyeleri:**
- Query execution time thresholds belirleyin
- Slow query log tutun
- Resource usage alerts tanımlayın
- Regular performance audits yapın

## 🔧 Maintenance

### Migration Manager (migration_manager.php)

```php
// Migration oluşturma
$manager->create('create_users_table');

// Migration çalıştırma stratejileri
$manager->migrate(['--pretend' => true]); // Dry run
$manager->migrate(['--force' => true]); // Tehlikeli operasyonları onayla
```

**Bakım İpuçları:**
- Regular schema backups alın
- Migration dependency'leri yönetin
- Rollback stratejileri belirleyin
- Zero-downtime migration planları yapın

## 🔍 Debugging ve Troubleshooting

### Yaygın Sorunlar ve Çözümleri

1. **Bağlantı Sorunları**
```php
try {
    $db->ensure_connection();
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
$db->clear_query_cache();
```

3. **Deadlock Yönetimi**
```php
$db->setDeadlockRetries(3)
   ->setDeadlockWait(200); // ms
```

### Performance Tuning Checklist

1. **Query Optimizasyonu**
   - EXPLAIN kullanımı
   - İndeks stratejisi
   - Query refactoring

2. **Resource Yönetimi**
   - Connection pool monitoring
   - Memory usage tracking
   - Cache hit/miss analysis

3. **Error Handling**
   - Structured logging
   - Error aggregation
   - Alert thresholds

## 📈 Ölçeklendirme

### Horizontal Scaling

```php
// Read/Write splitting
$db->setReadWriteSplit(true);
$db->addReadServer('slave1.example.com');
$db->addReadServer('slave2.example.com');
```

### Sharding Strategy

```php
// Shard key belirleme
$db->setShardKey('user_id');
$db->addShard('shard1', ['range' => [1, 1000]]);
$db->addShard('shard2', ['range' => [1001, 2000]]);
```

## 🔐 Security Best Practices

### 1. Input Validation

```php
// Anti-pattern:
$query = "SELECT * FROM users WHERE id = " . $_GET['id'];

// Secure pattern:
$id = $filter->sanitize($_GET['id'], 'int');
$user = $db->get_row("SELECT * FROM users WHERE id = :id", ['id' => $id]);
```

### 2. Access Control

```php
// Role-based query filtering
$db->addQueryFilter(function($query) use ($userRole) {
    if ($userRole !== 'admin') {
        return $query->where('is_public', true);
    }
    return $query;
});
```

## 📊 Monitoring ve Metrics

### Performance Metrics

```php
// Query timing
$db->enableQueryTiming();
$result = $db->get_results($query);
$timing = $db->getLastQueryTiming();

// Connection pool stats
$pool_stats = $db->get_pool_stats();
$active_connections = $pool_stats['active_connections'];
```

### Health Checks

```php
// Basic health check
$health = $db->getHealthStatus();

// Detailed diagnostics
$diagnostics = $db->getDiagnostics([
    'connection_pool',
    'query_cache',
    'statement_cache',
    'memory_usage'
]);
```

## 🔄 Recovery ve Backup

### Otomatik Recovery

```php
// Retry mekanizması
$db->setRetryPolicy([
    'max_attempts' => 3,
    'initial_wait' => 100,
    'multiplier' => 2
]);

// Circuit breaker
$db->enableCircuitBreaker([
    'failure_threshold' => 5,
    'reset_timeout' => 30
]);
```

### Backup Stratejileri

```php
// Point-in-time recovery
$backup = new DatabaseBackup($db);
$backup->createSnapshot();
$backup->restoreToPoint('2025-05-27 12:00:00');
```

## 🎯 Best Practices Özeti

1. **Güvenlik**
   - Her zaman prepared statements kullanın
   - Input validation uygulayın
   - Rate limiting implementasyonu yapın
   - Düzenli security audit yapın

2. **Performans**
   - Connection pooling kullanın
   - Query/Statement cache optimize edin
   - İndeks stratejisi belirleyin
   - Regular performance monitoring yapın

3. **Maintainability**
   - Clean code prensiplerini uygulayın
   - Düzenli refactoring yapın
   - Comprehensive testing uygulayın
   - Documentation güncel tutun

4. **Scalability**
   - Horizontal scaling planı yapın
   - Sharding stratejisi belirleyin
   - Load balancing implementasyonu yapın
   - Monitoring ve alerting kurun

## 📦 Versiyon Detayları

### v1.0.0 (Güncel)
- İlk kararlı sürüm
- Temel veritabanı işlemleri
- Connection pool implementasyonu
- Query ve statement cache
- Temel güvenlik özellikleri

### v1.1.0 (Planlanan)
- Read/Write splitting
- Gelişmiş monitoring
- Circuit breaker pattern
- Redis cache desteği
- Migration system iyileştirmeleri

### v1.2.0 (Planlanan)
- Otomatik sharding desteği
- Distributed cache
- GraphQL desteği
- Real-time monitoring
- Async query execution

### v1.3.0 (Planlanan)
- Schema validation
- Database proxy
- Query optimization engine
- Advanced security features
- Cloud integration
