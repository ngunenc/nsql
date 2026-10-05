# 📘 nsql Kütüphanesi Kullanım Klavuzu

## 📑 İçindekiler

- [Kurulum](#-kurulum)
- [Temel Kullanım](#-temel-kullanım)  
- [Gelişmiş Özellikler](#-gelişmiş-özellikler)
- [Güvenlik](#-güvenlik)
- [Performans Optimizasyonu](#-performans-optimizasyonu)
- [Hata Yönetimi](#-hata-yönetimi)
- [İyi Uygulamalar](#-iyi-uygulamalar)

## 📥 Kurulum

### Sistem Gereksinimleri
- PHP 8.1 veya üstü
- PDO PHP Eklentisi
- JSON PHP Eklentisi 
- OpenSSL PHP Eklentisi (şifreleme için)
- MySQL 5.7.8+ veya MariaDB 10.2+

### Composer ile Kurulum

```bash
composer require ngunenc/nsql --prefer-dist
```

> Source/VCS kurulumunda `vendor/ngunenc/nsql has uncommitted changes` hatası alırsanız: `git checkout -- .` ile vendor paketini temizleyip `composer update ngunenc/nsql --prefer-dist` çalıştırın (ayrıntı: README).

### Yapılandırma

1. Proje kökünde `.env` oluşturun (`.env.example` dosyasından kopyalayın). Anahtarlar **büyük harf** olmalıdır; `config::get('db_host')` gibi çağrılar içeride `DB_HOST` ile eşleşir.

2. **Kütüphane `vendor` ile kuruluysa** `.env` genelde uygulama kökündedir. nsql şu sırayla kök dizini arar:
   - Ortam değişkeni `NSQL_PROJECT_ROOT`
   - Yukarı doğru `.env` veya `composer.json`+`vendor/autoload.php` (Composer paket dizini atlanır)
   - Son çare: paket kökü — vendor altındaysa otomatik olarak uygulama köküne yükseltilir (v1.5.10+)

3. Önerilen: giriş dosyanızda (ör. `public/index.php`) autoload sonrası:

```php
\nsql\database\Config::set_project_root(__DIR__); // veya proje kökü
```

Örnek `.env` içeriği:

```ini
# İsteğe bağlı: .env'in bulunduğu uygulama kökü
# NSQL_PROJECT_ROOT=C:\path\to\app

DB_HOST=localhost
DB_PORT=3306
DB_NAME=veritabani_adi
DB_USER=kullanici_adi
DB_PASS=sifre
DB_CHARSET=utf8mb4
DB_DRIVER=mysql

QUERY_CACHE_ENABLED=true
QUERY_CACHE_TIMEOUT=300
QUERY_CACHE_SIZE_LIMIT=1000
STATEMENT_CACHE_LIMIT=100

DB_MIN_CONNECTIONS=5
DB_MAX_CONNECTIONS=20

DEBUG_MODE=false
LOG_FILE=error_log.txt
```

`new nsql()` çağrıldığında host, veritabanı adı, kullanıcı ve şifre **önce `.env` / ortam değişkeninden**, yoksa `config` varsayılanlarından okunur.

## 🚀 Temel Kullanım

### Veritabanı Bağlantısı

```php
use nsql\database\Nsql;
use nsql\database\Config;

Config::set_project_root(__DIR__); // önerilir (özellikle vendor kurulumunda)

// .env dosyasından yapılandırma ile
$db = new Nsql();

// veya özel parametrelerle 
$db = new Nsql(
    host: 'localhost',
    db: 'veritabani_adi', 
    user: 'kullanici',
    pass: 'sifre',
    charset: 'utf8mb4',
    debug: true
);
```

### Veri Sorgulama

```php
// Tek satır getirme
$kullanici = $db->get_row(
    "SELECT * FROM kullanicilar WHERE id = :id",
    ['id' => 1]
);

// Çoklu satır getirme 
$kullanicilar = $db->get_results("SELECT * FROM kullanicilar");

// Generator ile büyük veri setleri
foreach ($db->get_yield("SELECT * FROM buyuk_tablo") as $row) {
    // Hafıza dostu işlemler...
}
```

### Veri Manipülasyonu

```php
// Ekleme
$id = $db->insert(
    "INSERT INTO kullanicilar (ad, email) VALUES (:ad, :email)",
    [
        'ad' => 'Ahmet',
        'email' => 'ahmet@ornek.com'  
    ]
);

// Güncelleme
$db->update(
    "UPDATE kullanicilar SET ad = :ad WHERE id = :id",
    [
        'ad' => 'Mehmet',
        'id' => 1
    ]
);

// Silme
$db->delete(
    "DELETE FROM kullanicilar WHERE id = :id", 
    ['id' => 1]
);
```

### Transaction İşlemleri

```php
try {
    $db->begin();

    // İşlemler...
    $db->insert(...);
    $db->update(...);

    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    // Hata yönetimi
}
```

## 🔄 Gelişmiş Özellikler

### Query Cache Kullanımı

Query Cache, sık kullanılan sorguları önbellekte tutarak performansı artırır:

```php
// Cache otomatik olarak çalışır (.env'de QUERY_CACHE_ENABLED=true ise)
$sonuc1 = $db->get_results("SELECT * FROM urunler WHERE kategori = 'elektronik'");
// İkinci çağrıda cache'den gelir
$sonuc2 = $db->get_results("SELECT * FROM urunler WHERE kategori = 'elektronik'");

// Cache'i manuel temizleme
// Cache istatistiklerini görüntüle
$cache_stats = $db->get_all_cache_stats();
```

### Connection Pool İstatistikleri

```php
// Bağlantı havuzu durumunu kontrol et
$stats = $db->get_pool_stats();
print_r($stats);
/* 
Array
(
    [active_connections] => 3
    [idle_connections] => 2
    [total_connections] => 5
)
*/
```

### Migration Yönetimi

```php
// Migration dosyası oluşturma
$manager = new MigrationManager($db);
$manager->create("create_users_table");

// Migration'ları çalıştırma
$manager->migrate();

// Son migration'ı geri alma
$manager->rollback();
```

## 🛡️ Güvenlik

### Prepared Statements 

nsql, otomatik olarak prepared statements kullanır:

```php
// Güvenli parametre bağlama
$kullanicilar = $db->get_results(
    "SELECT * FROM kullanicilar WHERE rol = :rol",
    ['rol' => 'admin']
);
```

### Güvenli Oturum Yönetimi

```php
// Güvenli oturum başlatma
Nsql::secure_session_start();

// Oturum ID'sini yenileme
$sm = Nsql::session();
$sm->regenerate_id();
```

### Input Filtreleme

```php
use nsql\database\security\SensitiveDataFilter;

$filter = new SensitiveDataFilter();
$temiz_veri = $filter->clean($_POST['user_input']);
```

## 🚄 Performans Optimizasyonu

### Büyük Veri Setleri

Büyük veri setleri için generator kullanımı:

```php 
// Memory dostu veri çekme
foreach ($db->get_yield("SELECT * FROM buyuk_tablo") as $row) {
    // Her satır tek tek işlenir
    processRow($row);
}
```

### Statement Cache

```php
// Statement cache otomatik çalışır
for ($i = 0; $i < 1000; $i++) {
    // Aynı sorgu yapısı cache'den kullanılır
    $db->get_row("SELECT * FROM tablo WHERE id = :id", ['id' => $i]);
}
```

## ⚠️ Hata Yönetimi

### Debug Modu

```php
// Debug modunu aktif et
$db = new Nsql(debug: true);

// Sorgu çalıştır
$db->get_results("SELECT * FROM tablo");

// Debug bilgilerini görüntüle
$db->debug();
```

### Güvenli Hata Yönetimi

```php
// Güvenli sorgu çalıştırma
$result = $db->safe_execute(function() use ($db) {
    return $db->get_row("SELECT * FROM users WHERE id = :id", ['id' => 1]);
}, "Kullanıcı bilgileri alınırken hata oluştu");
```

## 💡 İyi Uygulamalar

1. **Bağlantı Yönetimi**
   - Connection Pool kullanın
   - Uzun süreli bağlantılar için timeout ayarlayın
   - Bağlantı sayılarını monitör edin

2. **Performans**
   - Büyük veriler için `get_yield()` kullanın
   - Query Cache'i etkin kullanın
   - Statement Cache'den faydalanın

3. **Güvenlik**
   - Her zaman prepared statements kullanın
   - Hassas verileri filtreleyin
   - Güvenli oturum yönetimini kullanın
   - Rate limiting uygulayın

4. **Bellek Yönetimi**
   - Gereksiz result set'leri temizleyin
   - Büyük sorgularda chunk processing kullanın
   - Memory limitlerini monitör edin

5. **Hata Yönetimi**
   - try-catch bloklarını kullanın
   - Detaylı log tutun
   - Debug modunu geliştirme ortamında kullanın

## 📦 Sürüm Bilgisi ve Yol Haritası

Yayınlanan özellikler için [CHANGELOG.md](../CHANGELOG.md), sürüm geçişleri için [UPGRADE.md](../UPGRADE.md) esas alınır. Planlanan işler [GitHub issues](https://github.com/ngunenc/nsql/issues) üzerinden takip edilir.

Önceki sürümlerde "planlanan" olarak listelenen bazı özellikler artık mevcuttur:

```php
// Okuma/yazma ayrımı (v1.11.0): .env -> READ_WRITE_SPLIT=true, DB_READ_HOST=replica1,replica2
$db = new Nsql();
$db->set_read_replica(['host' => 'replica1.example.com']);

// İsimlendirilmiş bağlantılar (v1.11.0)
$reporting = Nsql::connection('reporting');

// Redis / Memcached query cache (v1.10.1): .env -> QUERY_CACHE_DRIVER=redis
$cache_stats = $db->get_all_cache_stats();
```
## 🤝 Destek ve Katkı

- GitHub Issues: [https://github.com/ngunenc/nsql/issues](https://github.com/ngunenc/nsql/issues)
- Katkıda bulunmak için [CONTRIBUTING.md](../CONTRIBUTING.md) dosyasını inceleyin.

## 📜 Lisans

Bu proje MIT lisansı altında lisanslanmıştır. Detaylar için [LICENSE](../LICENSE) dosyasına bakınız.
