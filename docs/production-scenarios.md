# Production Kullanım Senaryoları

Bu dokümantasyon, nsql kütüphanesinin production ortamında kullanımı için best practices, performans optimizasyonları ve senaryo bazlı çözümler içerir.

## 📑 İçindekiler

- [Genel Bakış](#-genel-bakış)
- [Yapılandırma](#-yapılandırma)
- [Performans Optimizasyonu](#-performans-optimizasyonu)
- [Yüksek Trafik Senaryoları](#-yüksek-trafik-senaryoları)
- [Büyük Veri Setleri](#-büyük-veri-setleri)
- [Güvenlik](#-güvenlik)
- [Monitoring ve Logging](#-monitoring-ve-logging)
- [Disaster Recovery](#-disaster-recovery)
- [Scaling Stratejileri](#-scaling-stratejileri)

## 🎯 Genel Bakış

Production ortamında nsql kütüphanesini kullanırken dikkat edilmesi gereken temel noktalar:

- **Performans**: Query optimization, caching, connection pooling
- **Güvenlik**: SQL injection protection, input validation, audit logging
- **Güvenilirlik**: Error handling, retry mechanisms, transaction management
- **Ölçeklenebilirlik**: Horizontal scaling, read replicas, sharding

## ⚙️ Yapılandırma

### Environment Variables

```php
// .env.production
DB_HOST=production-db.example.com
DB_NAME=production_db
DB_USER=app_user
DB_PASS=secure_password
DB_CHARSET=utf8mb4

# Connection Pool
DB_POOL_MIN=5
DB_POOL_MAX=20
DB_POOL_TIMEOUT=30

# Cache
CACHE_ENABLED=true
CACHE_DRIVER=redis
REDIS_HOST=redis.example.com
REDIS_PORT=6379

# Logging
LOG_LEVEL=warning
LOG_FILE=/var/log/nsql/error.log
LOG_ROTATION=true
LOG_MAX_SIZE=100M
LOG_MAX_FILES=30

# Security
SECURITY_STRICT_MODE=true
RATE_LIMIT_ENABLED=true
RATE_LIMIT_MAX_REQUESTS=100
RATE_LIMIT_WINDOW=60
```

### Production Config

```php
<?php
use nsql\database\config;

// Environment
config::set_environment('production');

// Connection Pool
config::set('db_pool_min', 5);
config::set('db_pool_max', 20);
config::set('db_pool_timeout', 30);

// Cache
config::set('cache_enabled', true);
config::set('cache_driver', 'redis');
config::set('cache_ttl', 3600);

// Security
config::set('security_strict_mode', true);
config::set('rate_limit_enabled', true);

// Logging
config::set('log_level', 'warning');
config::set('log_file', '/var/log/nsql/error.log');
config::set('log_rotation', true);
```

## 🚀 Performans Optimizasyonu

### 1. Connection Pooling

```php
<?php
use nsql\database\connection_pool;
use nsql\database\nsql;

// Connection pool'u başlat
connection_pool::initialize([
    'min_connections' => 5,
    'max_connections' => 20,
    'connection_timeout' => 30,
    'idle_timeout' => 300,
]);

// Pool'dan bağlantı al
$db = connection_pool::get_connection();
```

### 2. Query Cache

```php
<?php
$db = new nsql();

// Cache'i etkinleştir
$db->enable_query_cache();

// Sık kullanılan sorguları preload et
$db->preload_query("SELECT * FROM users WHERE active = ?", [1]);
$db->preload_query("SELECT * FROM categories WHERE parent_id IS NULL");

// Cache warm-up (uygulama başlangıcında)
$db->warm_cache(true);
```

### 3. Statement Cache

```php
<?php
// Statement cache otomatik çalışır
// Sık kullanılan sorgular için prepared statement'lar cache'lenir
$db->query("SELECT * FROM users WHERE id = ?", [1]);
$db->query("SELECT * FROM users WHERE id = ?", [2]); // Cache'den gelecek
```

### 4. Index Optimization

```php
<?php
// Query optimizer ile index önerileri
use nsql\database\optimization\query_optimizer;

$suggestions = query_optimizer::suggest_indexes(
    "SELECT * FROM users WHERE email = ? AND active = ?"
);

// Önerilen index'leri oluştur
foreach ($suggestions as $suggestion) {
    $db->query($suggestion['sql']);
}
```

## 📈 Yüksek Trafik Senaryoları

### Senaryo 1: Yüksek Okuma Trafiği

**Problem:** Çok fazla SELECT sorgusu, veritabanı yükü yüksek.

**Çözüm:**

```php
<?php
// 1. Query Cache kullan
$db->enable_query_cache();
$db->set_query_cache_timeout(3600); // 1 saat

// 2. Read Replica kullan (eğer varsa)
$readDb = new nsql(
    host: 'read-replica.example.com',
    db: 'production_db'
);

// 3. Connection Pool ile bağlantı yönetimi
connection_pool::initialize([
    'min_connections' => 10,
    'max_connections' => 50,
]);

// 4. Pagination ile sonuçları sınırla
function getUsersPaginated(nsql $db, int $page = 1): array
{
    $perPage = 50;
    $offset = ($page - 1) * $perPage;
    
    return $db->get_results(
        "SELECT * FROM users ORDER BY id LIMIT ? OFFSET ?",
        [$perPage, $offset]
    );
}
```

### Senaryo 2: Yüksek Yazma Trafiği

**Problem:** Çok fazla INSERT/UPDATE, veritabanı lock'ları.

**Çözüm:**

```php
<?php
// 1. Batch insert kullan
$users = [/* 1000+ kayıt */];
$db->batch_insert('users', $users, true); // Transaction ile

// 2. Asenkron işleme (queue kullan)
class UserQueue
{
    public function enqueue(array $user): void
    {
        // Queue'ya ekle (Redis, RabbitMQ, vs.)
        redis()->lpush('user_queue', json_encode($user));
    }
    
    public function process(): void
    {
        $db = new nsql();
        $batch = [];
        
        while ($user = redis()->rpop('user_queue')) {
            $batch[] = json_decode($user, true);
            
            if (count($batch) >= 100) {
                $db->batch_insert('users', $batch, true);
                $batch = [];
            }
        }
        
        if (!empty($batch)) {
            $db->batch_insert('users', $batch, true);
        }
    }
}

// 3. Write-behind pattern
class WriteBehindCache
{
    private array $pendingWrites = [];
    
    public function write(string $key, mixed $value): void
    {
        // Önce cache'e yaz
        cache()->set($key, $value);
        
        // Veritabanına yazmayı queue'ya ekle
        $this->pendingWrites[] = ['key' => $key, 'value' => $value];
        
        // Batch olarak yaz
        if (count($this->pendingWrites) >= 100) {
            $this->flush();
        }
    }
    
    private function flush(): void
    {
        $db = new nsql();
        $db->batch_insert('cache_updates', $this->pendingWrites);
        $this->pendingWrites = [];
    }
}
```

### Senaryo 3: Peak Traffic Handling

**Problem:** Ani trafik artışları (flash sale, viral içerik).

**Çözüm:**

```php
<?php
// 1. Rate Limiting
use nsql\database\security\rate_limiter;

$limiter = new rate_limiter($db);

if (!$limiter->check_rate_limit($_SERVER['REMOTE_ADDR'], 'api')) {
    http_response_code(429);
    die('Too many requests');
}

// 2. Circuit Breaker Pattern
class CircuitBreaker
{
    private int $failureCount = 0;
    private int $threshold = 5;
    private ?int $lastFailureTime = null;
    private int $timeout = 60;
    
    public function call(callable $operation): mixed
    {
        if ($this->isOpen()) {
            throw new Exception('Circuit breaker is open');
        }
        
        try {
            $result = $operation();
            $this->onSuccess();
            return $result;
        } catch (Exception $e) {
            $this->onFailure();
            throw $e;
        }
    }
    
    private function isOpen(): bool
    {
        if ($this->failureCount < $this->threshold) {
            return false;
        }
        
        if ($this->lastFailureTime && time() - $this->lastFailureTime > $this->timeout) {
            $this->failureCount = 0; // Reset
            return false;
        }
        
        return true;
    }
    
    private function onSuccess(): void
    {
        $this->failureCount = 0;
    }
    
    private function onFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();
    }
}

// Kullanım
$breaker = new CircuitBreaker();
try {
    $result = $breaker->call(function() use ($db) {
        return $db->get_results("SELECT * FROM products WHERE sale = 1");
    });
} catch (Exception $e) {
    // Fallback: Cache'den oku
    $result = cache()->get('sale_products');
}
```

## 💾 Büyük Veri Setleri

### Senaryo 1: Büyük Tablolardan Veri Çekme

```php
<?php
// Generator ile bellek dostu işleme
foreach ($db->get_chunk("SELECT * FROM large_table", [], 1000) as $chunk) {
    foreach ($chunk as $row) {
        process_row($row);
    }
    
    // Memory cleanup
    unset($chunk);
    gc_collect_cycles();
}
```

### Senaryo 2: Büyük Veri İçe Aktarma

```php
<?php
function importLargeDataset(nsql $db, string $file): void
{
    $handle = fopen($file, 'r');
    $batch = [];
    $batchSize = 1000;
    
    while (($line = fgetcsv($handle)) !== false) {
        $batch[] = [
            'name' => $line[0],
            'email' => $line[1],
            // ...
        ];
        
        if (count($batch) >= $batchSize) {
            $db->batch_insert('users', $batch, true);
            $batch = [];
        }
    }
    
    if (!empty($batch)) {
        $db->batch_insert('users', $batch, true);
    }
    
    fclose($handle);
}
```

### Senaryo 3: Veri Arşivleme

```php
<?php
function archiveOldData(nsql $db, int $daysOld = 365): void
{
    $cutoffDate = date('Y-m-d', strtotime("-{$daysOld} days"));
    
    // Eski verileri arşiv tablosuna taşı
    $db->begin();
    try {
        $db->query("
            INSERT INTO users_archive 
            SELECT * FROM users 
            WHERE created_at < ?
        ", [$cutoffDate]);
        
        $db->query("
            DELETE FROM users 
            WHERE created_at < ?
        ", [$cutoffDate]);
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}
```

## 🔒 Güvenlik

### 1. SQL Injection Protection

```php
<?php
// ✅ DOĞRU: Prepared statements
$user = $db->get_row("SELECT * FROM users WHERE id = ?", [$userId]);

// ❌ YANLIŞ: String concatenation
$user = $db->get_row("SELECT * FROM users WHERE id = {$userId}");
```

### 2. Input Validation

```php
<?php
use nsql\database\validation\validator;

$rules = [
    'email' => ['required', 'email'],
    'age' => ['required', 'integer', 'min:18', 'max:100'],
];

if (!validator::validate_many($_POST, $rules)) {
    throw new ValidationException('Invalid input');
}
```

### 3. Audit Logging

```php
<?php
use nsql\database\security\audit_logger;

$audit = new audit_logger();

// Güvenlik olaylarını logla
$audit->log_security_event(
    'unauthorized_access',
    'User attempted to access restricted resource',
    ['user_id' => $userId, 'resource' => $resource],
    'warning'
);

// SQL injection denemelerini logla
try {
    $db->query($sql, $params);
} catch (QueryException $e) {
    $audit->log_sql_injection_attempt($sql, $params, $e->getMessage());
    throw $e;
}
```

### 4. Rate Limiting

```php
<?php
use nsql\database\security\rate_limiter;

$limiter = new rate_limiter($db);

// API endpoint'lerinde
if (!$limiter->check_rate_limit($userId, 'api')) {
    http_response_code(429);
    die(json_encode(['error' => 'Rate limit exceeded']));
}
```

## 📊 Monitoring ve Logging

### 1. Structured Logging

```php
<?php
use nsql\database\logging\logger;

$logger = new logger(
    log_file: '/var/log/nsql/app.log',
    log_level: logger::WARNING,
    structured_format: true
);

$logger->info('User created', [
    'user_id' => $userId,
    'email' => $email,
    'ip' => $_SERVER['REMOTE_ADDR']
]);
```

### 2. Metrics Collection

```php
<?php
// Connection pool istatistikleri
$poolStats = $db->get_pool_stats();

// Cache istatistikleri
$cacheStats = $db->get_all_cache_stats();

// Memory istatistikleri
$memoryStats = $db->get_memory_stats();

// Tüm istatistikler
$allStats = $db->get_all_stats();

// Metrics endpoint (Prometheus format)
header('Content-Type: text/plain');
echo "# HELP nsql_pool_active Active connections\n";
echo "# TYPE nsql_pool_active gauge\n";
echo "nsql_pool_active " . $poolStats['active_connections'] . "\n";
```

### 3. Health Checks

```php
<?php
// public/health.php
use nsql\database\monitoring\health_check;

$health = new health_check($db);
$status = $health->check();

header('Content-Type: application/json');
echo json_encode($status);
```

## 🛡️ Disaster Recovery

### 1. Backup Stratejisi

```php
<?php
function backupDatabase(nsql $db): string
{
    $backupFile = '/backups/db_' . date('Y-m-d_H-i-s') . '.sql';
    
    // mysqldump kullan (veya benzeri)
    exec("mysqldump -u user -p password database > {$backupFile}");
    
    return $backupFile;
}
```

### 2. Replication

```php
<?php
// Master-Slave setup
$masterDb = new nsql(
    host: 'master.example.com',
    db: 'production_db'
);

$slaveDb = new nsql(
    host: 'slave.example.com',
    db: 'production_db'
);

// Okuma işlemleri için slave kullan
function getUsers(): array
{
    global $slaveDb;
    return $slaveDb->get_results("SELECT * FROM users");
}

// Yazma işlemleri için master kullan
function createUser(array $data): int
{
    global $masterDb;
    return $masterDb->insert("INSERT INTO users ...", $data);
}
```

### 3. Failover

```php
<?php
class DatabaseFailover
{
    private array $databases;
    private int $currentIndex = 0;
    
    public function __construct(array $databases)
    {
        $this->databases = $databases;
    }
    
    public function getConnection(): nsql
    {
        $maxAttempts = count($this->databases);
        
        for ($i = 0; $i < $maxAttempts; $i++) {
            $index = ($this->currentIndex + $i) % count($this->databases);
            $config = $this->databases[$index];
            
            try {
                $db = new nsql(
                    host: $config['host'],
                    db: $config['db'],
                    user: $config['user'],
                    pass: $config['pass']
                );
                
                // Connection test
                $db->query("SELECT 1");
                
                $this->currentIndex = $index;
                return $db;
            } catch (Exception $e) {
                // Next database
                continue;
            }
        }
        
        throw new Exception('All databases unavailable');
    }
}
```

## 📈 Scaling Stratejileri

### 1. Horizontal Scaling

```php
<?php
// Sharding stratejisi
class ShardedDatabase
{
    private array $shards;
    
    public function getShard(string $key): nsql
    {
        $shardIndex = crc32($key) % count($this->shards);
        return $this->shards[$shardIndex];
    }
    
    public function getUser(int $userId): ?object
    {
        $db = $this->getShard((string)$userId);
        return $db->get_row("SELECT * FROM users WHERE id = ?", [$userId]);
    }
}
```

### 2. Read Replicas

```php
<?php
class ReadReplicaManager
{
    private nsql $master;
    private array $replicas;
    private int $currentReplica = 0;
    
    public function getReadConnection(): nsql
    {
        // Round-robin load balancing
        $replica = $this->replicas[$this->currentReplica];
        $this->currentReplica = ($this->currentReplica + 1) % count($this->replicas);
        
        return $replica;
    }
    
    public function getWriteConnection(): nsql
    {
        return $this->master;
    }
}
```

---

**Son Güncelleme**: 2026-01-22  
**Versiyon**: 1.4.0
