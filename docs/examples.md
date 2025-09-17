# 📝 nsql Kütüphanesi Örnekleri

## 📑 İçindekiler

- [Temel Kullanım](#-temel-kullanım)
- [CRUD İşlemleri](#-crud-işlemleri)
- [Transaction Yönetimi](#-transaction-yönetimi)
- [Query Builder](#-query-builder)
- [Güvenlik](#-güvenlik)
- [Migration ve Seed](#-migration-ve-seed)
- [Performans Optimizasyonu](#-performans-optimizasyonu)
- [Hata Yönetimi](#-hata-yönetimi)

## 🚀 Temel Kullanım

### Basit Bağlantı

```php
<?php
require_once 'vendor/autoload.php';

use nsql\database\nsql;
use nsql\database\config;

// Yapılandırma
config::set_environment('development');

// Veritabanı bağlantısı
$db = new nsql();

// Basit sorgu
$result = $db->query("SELECT NOW() as current_time");
$time = $result->fetch();

echo "Mevcut zaman: " . $time['current_time'];
?>
```

### Yapılandırma ile Bağlantı

```php
<?php
use nsql\database\nsql;
use nsql\database\config;

// Özel yapılandırma
$db = new nsql(
    host: 'localhost',
    db: 'my_database',
    user: 'my_user',
    pass: 'my_password',
    charset: 'utf8mb4',
    debug: true
);

// Bağlantı testi
try {
    $db->query("SELECT 1");
    echo "Veritabanı bağlantısı başarılı!";
} catch (Exception $e) {
    echo "Bağlantı hatası: " . $e->getMessage();
}
?>
```

## 📊 CRUD İşlemleri

### Create (Oluşturma)

```php
<?php
use nsql\database\nsql;

$db = new nsql();

// Tek kayıt ekleme
$id = $db->insert(
    "INSERT INTO users (name, email, created_at) VALUES (:name, :email, NOW())",
    [
        'name' => 'Ahmet Yılmaz',
        'email' => 'ahmet@example.com'
    ]
);

echo "Yeni kullanıcı ID: " . $id;

// Toplu ekleme
$users = [
    ['name' => 'Ali Veli', 'email' => 'ali@example.com'],
    ['name' => 'Ayşe Kaya', 'email' => 'ayse@example.com'],
    ['name' => 'Mehmet Demir', 'email' => 'mehmet@example.com']
];

foreach ($users as $user) {
    $db->insert(
        "INSERT INTO users (name, email, created_at) VALUES (:name, :email, NOW())",
        $user
    );
}
?>
```

### Read (Okuma)

```php
<?php
use nsql\database\nsql;

$db = new nsql();

// Tek kayıt
$user = $db->get_row(
    "SELECT * FROM users WHERE id = :id",
    ['id' => 1]
);

if ($user) {
    echo "Kullanıcı: " . $user->name . " (" . $user->email . ")";
}

// Tüm kayıtlar
$users = $db->get_results("SELECT * FROM users ORDER BY created_at DESC");

foreach ($users as $user) {
    echo $user->name . " - " . $user->email . "\n";
}

// Sayfalama
$page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$users = $db->get_results(
    "SELECT * FROM users ORDER BY id LIMIT :limit OFFSET :offset",
    ['limit' => $limit, 'offset' => $offset]
);
?>
```

### Update (Güncelleme)

```php
<?php
use nsql\database\nsql;

$db = new nsql();

// Tek kayıt güncelleme
$updated = $db->update(
    "UPDATE users SET name = :name, email = :email WHERE id = :id",
    [
        'name' => 'Ahmet Yılmaz (Güncellendi)',
        'email' => 'ahmet.yilmaz@example.com',
        'id' => 1
    ]
);

if ($updated) {
    echo "Kullanıcı güncellendi!";
}

// Toplu güncelleme
$db->update(
    "UPDATE users SET last_login = NOW() WHERE last_login IS NULL"
);
?>
```

### Delete (Silme)

```php
<?php
use nsql\database\nsql;

$db = new nsql();

// Tek kayıt silme
$deleted = $db->delete(
    "DELETE FROM users WHERE id = :id",
    ['id' => 1]
);

if ($deleted) {
    echo "Kullanıcı silindi!";
}

// Toplu silme
$db->delete(
    "DELETE FROM users WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)"
);
?>
```

## 🔄 Transaction Yönetimi

### Basit Transaction

```php
<?php
use nsql\database\nsql;

$db = new nsql();

$db->begin_transaction();

try {
    // Kullanıcı ekle
    $userId = $db->insert(
        "INSERT INTO users (name, email) VALUES (:name, :email)",
        ['name' => 'Test User', 'email' => 'test@example.com']
    );
    
    // Profil ekle
    $db->insert(
        "INSERT INTO user_profiles (user_id, bio) VALUES (:user_id, :bio)",
        ['user_id' => $userId, 'bio' => 'Test kullanıcısı profili']
    );
    
    $db->commit_transaction();
    echo "Transaction başarılı!";
    
} catch (Exception $e) {
    $db->rollback_transaction();
    echo "Transaction geri alındı: " . $e->getMessage();
}
?>
```

### Nested Transaction

```php
<?php
use nsql\database\nsql;

$db = new nsql();

$db->begin_transaction();

try {
    // Ana işlem
    $orderId = $db->insert(
        "INSERT INTO orders (user_id, total) VALUES (:user_id, :total)",
        ['user_id' => 1, 'total' => 100.00]
    );
    
    // Alt transaction
    $db->begin_transaction();
    
    try {
        // Sipariş detayları
        $db->insert(
            "INSERT INTO order_items (order_id, product_id, quantity) VALUES (:order_id, :product_id, :quantity)",
            ['order_id' => $orderId, 'product_id' => 1, 'quantity' => 2]
        );
        
        $db->commit_transaction();
        
    } catch (Exception $e) {
        $db->rollback_transaction();
        throw $e;
    }
    
    $db->commit_transaction();
    echo "Sipariş oluşturuldu!";
    
} catch (Exception $e) {
    $db->rollback_transaction();
    echo "Hata: " . $e->getMessage();
}
?>
```

## 🔧 Query Builder

### Temel Kullanım

```php
<?php
use nsql\database\nsql;
use nsql\database\query_builder;

$db = new nsql();
$builder = new query_builder($db);

// Basit sorgu
$users = $builder
    ->select('id', 'name', 'email')
    ->from('users')
    ->where('active', '=', 1)
    ->get();

// Karmaşık sorgu
$results = $builder
    ->select('u.id', 'u.name', 'u.email', 'p.bio')
    ->from('users', 'u')
    ->join('user_profiles', 'p', '=', 'u.id', 'LEFT')
    ->where('u.created_at', '>', '2023-01-01')
    ->where('u.active', '=', 1)
    ->order_by('u.name', 'ASC')
    ->limit(50)
    ->get();

foreach ($results as $result) {
    echo $result->name . " - " . $result->bio . "\n";
}
?>
```

### Dinamik Sorgu Oluşturma

```php
<?php
use nsql\database\query_builder;

function searchUsers($db, $filters = []) {
    $builder = new query_builder($db);
    
    $builder->select('*')->from('users');
    
    if (!empty($filters['name'])) {
        $builder->where('name', 'LIKE', '%' . $filters['name'] . '%');
    }
    
    if (!empty($filters['email'])) {
        $builder->where('email', 'LIKE', '%' . $filters['email'] . '%');
    }
    
    if (!empty($filters['active'])) {
        $builder->where('active', '=', $filters['active']);
    }
    
    if (!empty($filters['sort'])) {
        $direction = $filters['direction'] ?? 'ASC';
        $builder->order_by($filters['sort'], $direction);
    }
    
    if (!empty($filters['limit'])) {
        $builder->limit($filters['limit']);
    }
    
    return $builder->get();
}

// Kullanım
$filters = [
    'name' => 'Ahmet',
    'active' => 1,
    'sort' => 'created_at',
    'direction' => 'DESC',
    'limit' => 20
];

$users = searchUsers($db, $filters);
?>
```

## 🔒 Güvenlik

### XSS Koruması

```php
<?php
use nsql\database\nsql;
use nsql\database\security\SecurityManager;

$db = new nsql();

// Kullanıcıdan gelen veri
$userInput = '<script>alert("XSS")</script>';

// Güvenli hale getirme
$safeInput = SecurityManager::escape_html($userInput);

// Veritabanına kaydetme
$db->insert(
    "INSERT INTO posts (title, content) VALUES (:title, :content)",
    [
        'title' => $safeInput,
        'content' => $safeInput
    ]
);

// Çıktıda güvenli gösterim
echo $safeInput; // &lt;script&gt;alert("XSS")&lt;/script&gt;
?>
```

### CSRF Koruması

```php
<?php
use nsql\database\security\SecurityManager;

// Form oluşturma
$csrfToken = SecurityManager::generate_csrf_token();
?>

<form method="POST" action="update_user.php">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="text" name="name" placeholder="İsim">
    <button type="submit">Güncelle</button>
</form>

<?php
// Form işleme
if ($_POST) {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!SecurityManager::validate_csrf_token($token)) {
        die('CSRF token geçersiz!');
    }
    
    // Güvenli işlem
    $name = SecurityManager::escape_html($_POST['name']);
    // ... veritabanı işlemleri
}
?>
```

### Rate Limiting

```php
<?php
use nsql\database\security\rate_limiter;

$limiter = new rate_limiter($db);

// Kullanıcı IP'si
$userIP = $_SERVER['REMOTE_ADDR'];

// Rate limit kontrolü
if (!$limiter->check_rate_limit($userIP, 'api')) {
    http_response_code(429);
    die('Çok fazla istek! Lütfen daha sonra tekrar deneyin.');
}

// API işlemi devam edebilir
echo "API yanıtı";
?>
```

### Veri Şifreleme

```php
<?php
use nsql\database\security\encryption;

$encryption = new encryption();

// Hassas veriyi şifrele
$sensitiveData = "Kredi kartı numarası: 1234-5678-9012-3456";
$encrypted = $encryption->encrypt($sensitiveData);

// Veritabanına kaydet
$db->insert(
    "INSERT INTO sensitive_data (encrypted_data) VALUES (:data)",
    ['data' => $encrypted]
);

// Veriyi çöz
$decrypted = $encryption->decrypt($encrypted);
echo $decrypted; // Orijinal veri
?>
```

## 📦 Migration ve Seed

### Migration Oluşturma

```php
<?php
// src/database/migrations/2023_12_01_000001_create_users_table.php

namespace nsql\database\migrations;

use nsql\database\migration;
use nsql\database\nsql;

class create_users_table implements migration
{
    private nsql $db;

    public function __construct()
    {
        $this->db = new nsql();
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->query($sql);
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS users");
    }

    public function get_description(): string
    {
        return 'Create users table';
    }
}
?>
```

### Migration Çalıştırma

```php
<?php
use nsql\database\migration_manager;

$manager = new migration_manager($db);

// Tüm migration'ları çalıştır
$executed = $manager->migrate();
echo "Çalıştırılan migration'lar: " . implode(', ', $executed);

// Belirli versiyona migration
$manager->migrate_to('2023_12_01_000001');

// Migration'ları geri al
$manager->rollback(2); // Son 2 migration'ı geri al
?>
```

### Seed Oluşturma

```php
<?php
// src/database/seeds/user_seeder.php

namespace nsql\database\seeds;

use nsql\database\nsql;

class user_seeder
{
    public function run(nsql $db): void
    {
        $users = [
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'active' => true
            ],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => password_hash('test123', PASSWORD_DEFAULT),
                'active' => true
            ]
        ];

        foreach ($users as $user) {
            $db->insert(
                "INSERT INTO users (name, email, password, active) VALUES (:name, :email, :password, :active)",
                $user
            );
        }
    }
}
?>
```

### Seed Çalıştırma

```php
<?php
use nsql\database\migration_manager;

$manager = new migration_manager($db);

// Tüm seed'leri çalıştır
$manager->seed();

// Belirli seed'i çalıştır
$manager->seed('user_seeder');
?>
```

## ⚡ Performans Optimizasyonu

### Chunked Fetch (Büyük Veri Setleri)

```php
<?php
use nsql\database\nsql;

$db = new nsql();

// Büyük veri setini parça parça işle
foreach ($db->get_chunk("SELECT * FROM large_table", [], 1000) as $chunk) {
    foreach ($chunk as $row) {
        // Her satırı işle
        processRow($row);
    }
    
    // Bellek temizliği
    unset($chunk);
}

function processRow($row) {
    // Satır işleme mantığı
    echo "İşlenen ID: " . $row->id . "\n";
}
?>
```

### Connection Pool Kullanımı

```php
<?php
use nsql\database\nsql;
use nsql\database\connection_pool;

// Connection pool istatistikleri
$stats = nsql::get_pool_stats();

echo "Aktif bağlantılar: " . $stats['active_connections'] . "\n";
echo "Boşta bağlantılar: " . $stats['idle_connections'] . "\n";
echo "Toplam bağlantılar: " . $stats['total_connections'] . "\n";
?>
```

### Query Cache

```php
<?php
use nsql\database\nsql;

$db = new nsql();

// Aynı sorguyu birden fazla çalıştır (cache'den gelecek)
$users1 = $db->get_results("SELECT * FROM users WHERE active = 1");
$users2 = $db->get_results("SELECT * FROM users WHERE active = 1"); // Cache'den

// Cache'i temizle
$db->clear_query_cache();
?>
```

## 🚨 Hata Yönetimi

### Try-Catch ile Hata Yakalama

```php
<?php
use nsql\database\nsql;

$db = new nsql();

try {
    $result = $db->query("SELECT * FROM non_existent_table");
} catch (PDOException $e) {
    error_log("Veritabanı hatası: " . $e->getMessage());
    echo "Veritabanı hatası oluştu!";
} catch (Exception $e) {
    error_log("Genel hata: " . $e->getMessage());
    echo "Beklenmeyen bir hata oluştu!";
}
?>
```

### Custom Error Handler

```php
<?php
use nsql\database\nsql;

class DatabaseErrorHandler
{
    private nsql $db;
    
    public function __construct(nsql $db)
    {
        $this->db = $db;
    }
    
    public function handleError(Exception $e): void
    {
        // Hata logla
        error_log("Database Error: " . $e->getMessage());
        
        // Kullanıcıya uygun mesaj göster
        if ($e instanceof PDOException) {
            echo "Veritabanı bağlantı hatası!";
        } else {
            echo "Bir hata oluştu, lütfen daha sonra tekrar deneyin.";
        }
    }
}

$db = new nsql();
$errorHandler = new DatabaseErrorHandler($db);

try {
    $db->query("INVALID SQL");
} catch (Exception $e) {
    $errorHandler->handleError($e);
}
?>
```

### Debug Modu

```php
<?php
use nsql\database\nsql;

// Debug modu ile bağlantı
$db = new nsql(debug: true);

// Debug bilgileri otomatik loglanacak
$db->query("SELECT * FROM users WHERE id = :id", ['id' => 1]);

// Manuel debug loglama
$db->log_debug_info("Custom debug message", ['data' => 'value']);
?>
```

## 🎯 İleri Seviye Örnekler

### RESTful API

```php
<?php
use nsql\database\nsql;
use nsql\database\query_builder;

class UserAPI
{
    private nsql $db;
    
    public function __construct()
    {
        $this->db = new nsql();
    }
    
    public function getUsers(): array
    {
        $builder = new query_builder($this->db);
        
        return $builder
            ->select('id', 'name', 'email', 'created_at')
            ->from('users')
            ->where('active', '=', 1)
            ->order_by('created_at', 'DESC')
            ->get();
    }
    
    public function createUser(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO users (name, email, password) VALUES (:name, :email, :password)",
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => password_hash($data['password'], PASSWORD_DEFAULT)
            ]
        );
    }
    
    public function updateUser(int $id, array $data): bool
    {
        return $this->db->update(
            "UPDATE users SET name = :name, email = :email WHERE id = :id",
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'id' => $id
            ]
        );
    }
    
    public function deleteUser(int $id): bool
    {
        return $this->db->delete(
            "DELETE FROM users WHERE id = :id",
            ['id' => $id]
        );
    }
}

// API kullanımı
$api = new UserAPI();

// GET /users
$users = $api->getUsers();
header('Content-Type: application/json');
echo json_encode($users);

// POST /users
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $api->createUser($data);
    echo json_encode(['id' => $userId]);
}
?>
```

### Event-Driven Architecture

```php
<?php
use nsql\database\nsql;

class EventManager
{
    private array $listeners = [];
    
    public function on(string $event, callable $callback): void
    {
        $this->listeners[$event][] = $callback;
    }
    
    public function emit(string $event, array $data = []): void
    {
        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $callback) {
                $callback($data);
            }
        }
    }
}

class UserService
{
    private nsql $db;
    private EventManager $events;
    
    public function __construct(nsql $db, EventManager $events)
    {
        $this->db = $db;
        $this->events = $events;
    }
    
    public function createUser(array $data): int
    {
        $this->db->begin_transaction();
        
        try {
            $userId = $this->db->insert(
                "INSERT INTO users (name, email) VALUES (:name, :email)",
                $data
            );
            
            // Event emit
            $this->events->emit('user.created', ['user_id' => $userId, 'data' => $data]);
            
            $this->db->commit_transaction();
            return $userId;
            
        } catch (Exception $e) {
            $this->db->rollback_transaction();
            throw $e;
        }
    }
}

// Event listener'ları tanımla
$events = new EventManager();

$events->on('user.created', function($data) {
    // Email gönder
    sendWelcomeEmail($data['data']['email']);
});

$events->on('user.created', function($data) {
    // Log kaydet
    error_log("Yeni kullanıcı oluşturuldu: " . $data['user_id']);
});

// Kullanım
$db = new nsql();
$userService = new UserService($db, $events);

$userId = $userService->createUser([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
?>
```

---

Bu örnekler nsql kütüphanesinin tüm özelliklerini kapsamlı bir şekilde göstermektedir. Daha fazla bilgi için [API Referansı](api-reference.md) ve [Kullanım Klavuzu](kullanim-klavuzu.md) dokümantasyonlarına bakın.
