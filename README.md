# 📚 nsql - Modern PHP PDO Veritabanı Kütüphanesi v1.5.9

**nsql**, PHP 8.0+ için tasarlanmış, modern, güvenli ve yüksek performanslı bir veritabanı kütüphanesidir. PDO tabanlı bu kütüphane, gelişmiş özellikler ve optimizasyonlarla güçlendirilmiştir.

> **🚀 v1.5.0 Yeni Özellikler**: Thread-safe connection pool, LFU cache algoritması, per-table TTL, cache warming, identifier quoting, güvenli IP/HTTPS tespiti, gelişmiş exception handling ve memory leak düzeltmeleri!
>
> **v1.5.1**: PHP 8.4 uyumluluğu — kilit dosyası stream tutamaçlarında native `resource` tipleri kaldırıldı; PHPDoc ile belgelendi (`connection_pool`, `cache_trait`).
>
> **v1.5.2**: `.env` ile veritabanı yapılandırması — proje kökü tespiti (`NSQL_PROJECT_ROOT`, `config::set_project_root`), dokümantasyon ve örnekler güncellendi.
>
> **v1.5.3**: Güvenlik — `storage/keys/encryption.key` git'ten kaldırıldı; anahtar üretim / rotation dokümante edildi. Eski commit'li anahtar compromised kabul edilmeli.
>
> **v1.5.4**: Yapı — `.gitignore` güçlendirildi (keys, logs, coverage, tool caches); tracked `.php-cs-fixer.cache` kaldırıldı.
>
> **v1.5.5**: Çift PDO bağlantısı düzeltildi (composition + pool); health/metrics monitoring token zorunlu (#3, #4).
>
> **v1.5.6**: Redis cache object injection riski giderildi — güvenli JSON payload (`nsql:j1:`) (#26).
>
> **v1.5.7**: Composer paket adı Packagist ile hizalandı: `ngunenc/nsql` (#5).
>
> **v1.5.8**: `.env.example` tek şablon; `DB_MIN_CONNECTIONS` ↔ config mapping (#13).
>
> **v1.5.9**: Transaction metotları tek kaynakta (`transaction_trait`); sınıf kopyaları kaldırıldı (#7).

## 🌟 Özellikler

### Core Özellikler
- PDO tabanlı veritabanı soyutlama
- Akıcı (fluent) sorgu arayüzü
- Otomatik bağlantı yönetimi 
- Transaction desteği
- Migration sistemi

### Güvenlik
- SQL injection koruması (PDO prepared statements, identifier quoting)
- XSS ve CSRF koruma mekanizmaları
- Güvenli oturum yönetimi
- Rate limiting ve DDoS koruması 
- Hassas veri filtreleme
- Thread-safe connection pool (file-based lock)
- Güvenli IP/HTTPS tespiti (proxy/load balancer desteği)

### Performans (v1.5.0 Optimizasyonları)
- **Connection Pool**: Thread-safe bağlantı yönetimi (file-based lock, circular buffer)
- **Memory Management**: Gelişmiş bellek yönetimi (circular buffer, agresif cleanup)
- **Cache Performance**: LFU algoritması desteği, dinamik cache size, per-table TTL
- **Cache Warming**: Önceden yükleme stratejileri ile performans artışı
- **Query Analyzer**: Analiz sonuçları cache'leme (100 analiz sonucu)
- **Generator Desteği**: Memory leak düzeltmeleri ile daha güvenli büyük veri işleme
- **Otomatik Optimizasyon**: Akıllı chunk size ayarlaması

### Geliştirici Araçları
- Detaylı debug sistemi
- Kapsamlı hata yönetimi
- PHPUnit test desteği
- PSR-12 kod standardı uyumluluğu
- PHPStan static analysis desteği
- PHP CS Fixer kod formatlama
- Composer script'leri ile otomatik test


## 📋 Kurulum

### Sistem Gereksinimleri
- **PHP**: 8.0 veya üstü
- **PDO**: PHP PDO eklentisi
- **MySQL**: 5.7.8+ veya MariaDB 10.2+
- **OpenSSL**: Şifreleme özellikleri için
- **JSON**: Yapılandırma dosyaları için

### Composer ile Kurulum

Resmi paket adı: **`ngunenc/nsql`** ([Packagist](https://packagist.org/packages/ngunenc/nsql)).

```bash
composer require ngunenc/nsql:^1.5.9
```

> 📖 **Kullanım ve .env**: [docs/kullanim-klavuzu.md](docs/kullanim-klavuzu.md)

#### Alternatif: VCS (GitHub)

Packagist kullanılamıyorsa:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ngunenc/nsql.git"
        }
    ],
    "require": {
        "ngunenc/nsql": "^1.5.9"
    }
}
```

```bash
composer require ngunenc/nsql:^1.5.9 --repository='{"type":"vcs","url":"https://github.com/ngunenc/nsql.git"}'
```

### Manuel Kurulum

```bash
git clone https://github.com/ngunenc/nsql.git
cd nsql
composer install
```

### Geliştirme Ortamı Kurulumu

```bash
# Bağımlılıkları yükle
composer install

# Test veritabanını kur
composer test:setup

# Testleri çalıştır
composer test

# Kod kalitesini kontrol et
composer lint
composer stan
```

### Yapılandırma

1. `.env.example` dosyasını `.env` olarak kopyalayın:
```bash
cp .env.example .env
```

2. `.env` dosyasındaki değerleri güncelleyin:
```env
db_host=localhost
db_name=your_database
db_user=your_username
db_pass=your_password
DEBUG_MODE=false
```

## 📚 Dokümantasyon

- [📘 Kullanım Klavuzu](docs/kullanim-klavuzu.md) - Temel kullanım ve kurulum
- [📖 Teknik Detaylar](docs/teknik-detay.md) - Mimari ve teknik bilgiler  
- [📚 API Referansı](docs/api-reference.md) - Kapsamlı API dokümantasyonu
- [📝 Örnekler](docs/examples.md) - Detaylı kullanım örnekleri
- [📋 Değişiklik Günlüğü](CHANGELOG.md) - Sürüm geçmişi ve değişiklikler

### Kısa Özet ve Temel Kullanım

#### Veritabanı Bağlantısı

```php
use nsql\database\nsql;

// .env dosyasından yapılandırma ile (önerilen)
$db = new nsql();

// veya özel parametrelerle
$db = new nsql(
    host: 'localhost',
    db: 'veritabani_adi',
    user: 'kullanici',
    pass: 'sifre',
    charset: 'utf8mb4',
    debug: true
);
```

#### Veri Sorgulama

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

#### Veri Manipülasyonu

```php
// Ekleme
$db->insert("INSERT INTO kullanicilar (ad, email) VALUES (:ad, :email)", [
    'ad' => 'Ahmet',
    'email' => 'ahmet@ornek.com'
]);
$son_id = $db->insert_id();

// Güncelleme
$db->update("UPDATE kullanicilar SET ad = :ad WHERE id = :id", [
    'ad' => 'Mehmet',
    'id' => 1
]);

// Silme
$db->delete("DELETE FROM kullanicilar WHERE id = :id", ['id' => 1]);
```


---

### Örnek Uygulama Akışı

Aşağıda, nsql kütüphanesinin bir web uygulamasında kullanıcı ekleme, listeleme ve güncelleme işlemleri için nasıl kullanılabileceğine dair tam bir akış örneği verilmiştir:

```php
use nsql\database\nsql;

// Bağlantı
$db = new nsql();

// 1. Kullanıcı ekleme
$db->insert("INSERT INTO kullanicilar (ad, email) VALUES (:ad, :email)", [
    'ad' => 'Ayşe',
    'email' => 'ayse@ornek.com'
]);
$yeni_id = $db->insert_id();

// 2. Tüm kullanıcıları listeleme
$kullanicilar = $db->get_results("SELECT * FROM kullanicilar");
foreach ($kullanicilar as $kullanici) {
    echo $kullanici->ad . " - " . $kullanici->email . "<br>";
}

// 3. Kullanıcı güncelleme
$db->update("UPDATE kullanicilar SET ad = :ad WHERE id = :id", [
    'ad' => 'Ayşe Yılmaz',
    'id' => $yeni_id
]);

// 4. Tek bir kullanıcıyı getirme
$ayse = $db->get_row("SELECT * FROM kullanicilar WHERE id = :id", ['id' => $yeni_id]);
echo "Güncellenen kullanıcı: " . $ayse->ad;

// 5. Kullanıcı silme
$db->delete("DELETE FROM kullanicilar WHERE id = :id", ['id' => $yeni_id]);
```

Bu örnek, nsql ile tipik bir CRUD (Create, Read, Update, Delete) akışının nasıl gerçekleştirileceğini göstermektedir. Tüm işlemler güvenli parametre bağlama ile yapılır ve hata yönetimi için try-catch blokları eklenebilir.

Kütüphanenin daha fazla özelliği ve gelişmiş kullanım örnekleri için [docs/kullanim-klavuzu.md](docs/kullanim-klavuzu.md) dosyasını inceleyebilirsiniz.

## 🧪 Test ve Kalite

### Test Çalıştırma

```bash
# Tüm testleri çalıştır
composer test

# Coverage raporu ile
composer test -- --coverage-html coverage/html
```

### Kod Kalitesi

```bash
# PHPStan static analysis
composer stan

# PHP CodeSniffer (PSR-12)
composer lint

# PHP CS Fixer
composer fix
```

### CI/CD

Proje GitHub Actions ile otomatik test edilir:
- PHP 8.0, 8.1, 8.2, 8.3 desteği
- Ubuntu ve Windows ortamları
- MySQL 8.0 test veritabanı
- Coverage raporları

## 📂 Proje Yapısı

```
nsql/
├── src/
│   └── database/
│       ├── config.php               # Yapılandırma yönetimi
│       ├── connection_pool.php      # Bağlantı havuzu yönetimi
│       ├── migration.php           # Migration arayüzü
│       ├── migration_manager.php   # Migration yönetimi
│       ├── nsql.php               # Ana PDO wrapper sınıfı
│       ├── query_builder.php      # SQL sorgu oluşturucu
│       ├── migrations/            # Migration dosyaları
│       ├── schema/               # Şema validasyonu (v1.3.0)
│       ├── security/             # Güvenlik bileşenleri
│       │   ├── audit_logger.php   # Güvenlik log sistemi
│       │   ├── encryption.php     # Şifreleme işlemleri
│       │   ├── rate_limiter.php   # İstek sınırlama
│       │   ├── security_manager.php # Güvenlik yönetimi
│       │   └── sensitive_data_filter.php # Hassas veri filtresi
│       ├── seeds/                # Seed dosyaları
│       ├── templates/            # View şablonları
│       └── traits/               # Trait sınıfları
│           ├── cache_trait.php    # Önbellekleme işlemleri
│           ├── connection_trait.php # Bağlantı yönetimi
│           ├── debug_trait.php     # Hata ayıklama
│           ├── query_parameter_trait.php # Sorgu parametreleri
│           ├── statement_cache_trait.php # Statement önbellekleme
│           └── transaction_trait.php # Transaction yönetimi
├── tests/                      # Test dosyaları
├── .github/workflows/          # GitHub Actions CI
├── storage/logs/              # Log dosyaları
├── composer.json             # Composer yapılandırması
├── phpunit.xml               # PHPUnit yapılandırması
├── phpstan.neon              # PHPStan yapılandırması
├── .php_cs                   # PHP CS Fixer yapılandırması
├── .env.example              # Yapılandırma örneği (resmi şablon)
├── env.example               # DEPRECATED → .env.example kullanın
└── README.md                # Dokümantasyon
```

### Sınıf Yapısı

#### Temel Bileşenler
- **nsql**: PDO wrapper ve temel veritabanı işlemleri
- **config**: Yapılandırma yönetimi ve ortam değişkenleri
- **connection_pool**: Veritabanı bağlantı havuzu ve optimizasyon
- **query_builder**: Akıcı arayüz ile SQL sorgu oluşturma

#### Güvenlik Bileşenleri
- **security_manager**: Merkezi güvenlik yönetimi
- **encryption**: Veri şifreleme ve çözme işlemleri
- **rate_limiter**: İstek sınırlama ve DDoS koruması
- **audit_logger**: Güvenlik olayları loglama
- **sensitive_data_filter**: Hassas veri filtreleme

#### Veritabanı Yönetimi
- **migration_manager**: Veritabanı şema yönetimi
- **migration**: Migration arayüzü tanımı
- **seeds**: Test ve başlangıç verisi yönetimi

## 🌟 Özellikler

### Core Özellikler
- PDO tabanlı veritabanı soyutlama
- Akıcı (fluent) sorgu arayüzü
- Otomatik bağlantı yönetimi
- Transaction desteği
- Migration sistemi

### Güvenlik
- SQL injection koruması (PDO prepared statements)
- XSS ve CSRF koruma mekanizmaları
- Güvenli oturum yönetimi ve cookie kontrolü
- Rate limiting ve DDoS koruması
- Hassas veri filtreleme ve şifreleme
- Güvenlik olay loglaması

### Performans
- Connection Pool ile bağlantı yönetimi
- Statement Cache (LRU algoritması)
- Query Cache sistemi
- Generator desteği ile düşük bellek kullanımı
- Otomatik garbage collection

### Geliştirici Araçları
- Detaylı debug sistemi
- Kapsamlı hata yönetimi
- Komut satırı araçları (planlanan)
- PHPUnit test desteği
- PSR-12 kod standardı uyumluluğu

## 🔧 Kurulum

### Sistem Gereksinimleri

- PHP 8.0+
- PDO PHP Eklentisi
- JSON PHP Eklentisi
- OpenSSL PHP Eklentisi (şifreleme için)
- MySQL 5.7.8+ veya MariaDB 10.2+

### Composer ile Kurulum

```bash
composer require ngunenc/nsql
```

### Manuel Kurulum

1. Projeyi klonlayın:
```bash
git clone https://github.com/ngunenc/nsql.git
```

2. Bağımlılıkları yükleyin:
```bash
composer install
```

3. Yapılandırma dosyasını oluşturun:
```bash
cp .env.example .env
```

4. Veritabanı ayarlarını yapılandırın:
```ini
db_host=localhost
db_name=database_name
db_user=database_user
db_pass=database_password
DB_CHARSET=utf8mb4

# Cache ayarları
QUERY_CACHE_ENABLED=true
STATEMENT_CACHE_LIMIT=100

# Güvenlik ayarları
RATE_LIMIT_ENABLED=true
# Production'da zorunlu önerilir (git'e eklemeyin):
# php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
ENCRYPTION_KEY=your-secure-base64-key
```

> **Güvenlik (v1.5.3+)**: `storage/keys/encryption.key` asla commit edilmemelidir. Anahtar yoksa kütüphane otomatik üretir; production'da `ENCRYPTION_KEY` kullanın. Detay: [`storage/keys/README.md`](storage/keys/README.md).

## 📖 Kullanım

### Temel Bağlantı

```php
use nsql\database\nsql;

// Basit bağlantı
$db = new nsql();

// veya özel parametrelerle
$db = new nsql(
    host: 'localhost',
    db: 'veritabanı',
    user: 'kullanici',
    pass: 'sifre',
    charset: 'utf8mb4',
    debug: true
);
```

### Veri Sorgulama

```php
// Tek satır getirme
$kullanici = $db->get_row("SELECT * FROM kullanicilar WHERE id = :id", ['id' => 1]);

// Çoklu satır getirme
$kullanicilar = $db->get_results("SELECT * FROM kullanicilar");

// Generator ile büyük veri setleri
foreach ($db->get_yield("SELECT * FROM buyuk_tablo") as $row) {
    // Hafıza dostu işlemler
}
```

### Veri Manipülasyonu

```php
// Ekleme
$db->insert("INSERT INTO kullanicilar (ad, email) VALUES (:ad, :email)", [
    'ad' => 'Ahmet',
    'email' => 'ahmet@ornek.com'
]);
$son_id = $db->insert_id();

// Güncelleme
$db->update("UPDATE kullanicilar SET ad = :ad WHERE id = :id", [
    'ad' => 'Mehmet',
    'id' => 1
]);

// Silme
$db->delete("DELETE FROM kullanicilar WHERE id = :id", ['id' => 1]);
```

### Transaction Kullanımı

```php
try {
    $db->begin();
    
    // İşlemler...
    
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    // Hata yönetimi
}
```

## 🛡️ Güvenlik

### CSRF Koruması

```php
// Token üretme
$token = \nsql\database\security\session_manager::get_csrf_token();

// Token doğrulama
if (nsql::validate_csrf($_POST['token'] ?? '')) {
    // Güvenli işlem
}
```

### XSS Koruması

```php
$guvenli_metin = nsql::escape_html($kullanici_girisi);
```

## 🚀 Performans

### Statement Cache

Sık kullanılan sorgular için otomatik önbellekleme yapılır. LRU (Least Recently Used) ve LFU (Least Frequently Used) algoritmaları desteklenir. Memory kullanımına göre dinamik cache size ayarlaması yapılır.

### Connection Pool

Bağlantılar havuzda tutulur ve gerektiğinde yeniden kullanılır, böylece performans artışı sağlanır.

### Debug Modu

```php
$db = new nsql(debug: true);

// Sorgu çalıştır
$db->get_results("SELECT * FROM tablo");

// Debug bilgilerini görüntüle
$db->debug();
```

## 📝 Örnekler

### Güvenli Oturum Yönetimi

```php
// Güvenli oturum başlatma
nsql::secure_session_start();

// Oturum ID'sini yenileme
nsql::regenerateSessionId();
```

### Hata Yönetimi

```php
$db->safe_execute(function() use ($db) {
    return $db->get_results("SELECT * FROM tablo");
}, "Veriler alınırken bir hata oluştu");
```

---

### Gerçek Hayat Kullanım Senaryoları

#### Migration Kullanımı

Gerçek projelerde veritabanı şemasını güncellemek için migration modülünü kullanabilirsiniz:

```php
use nsql\database\migration_manager;

$migration = new migration_manager();
$migration->runMigrations(); // Tüm migration dosyalarını uygular
```

#### Seed Kullanımı

Test ve demo verisi eklemek için seed modülünü kullanabilirsiniz:

```php
use nsql\database\seeds\user_seeder;

$seeder = new user_seeder();
$seeder->run(); // Örnek kullanıcı verilerini ekler
```

#### Güvenlik Modülleri

Gerçek uygulamalarda rate limiting ve veri şifreleme gibi güvenlik modüllerini entegre edebilirsiniz:

```php
use nsql\database\security\rate_limiter;

$limiter = new rate_limiter();
if (!$limiter->check('user_ip')) {
    die('Çok fazla istek!');
}

use nsql\database\security\encryption;
use nsql\database\security\key_manager;

// Anahtar: ENCRYPTION_KEY env veya storage/keys/encryption.key
$enc = new encryption();
$crypted = $enc->encrypt('gizli veri');
$plain = $enc->decrypt($crypted);

// Anahtar rotation (eski verileri önce çözüp yeniden şifreleyin)
// $info = key_manager::rotate_key();
```

#### Monitoring (health / metrics)

`public/health.php` ve `public/metrics.php` **token zorunludur**:

```env
NSQL_MONITORING_TOKEN=uzun-rastgele-secret
# NSQL_MONITORING_ENABLED=false  # endpoint'leri kapatır
```

```bash
curl -H "Authorization: Bearer $NSQL_MONITORING_TOKEN" http://localhost/health.php
# veya: X-NSQL-Monitoring-Token / ?token=
```

> v1.5.5+: `nsql` artık `PDO`'yu extend etmez. Ham PDO için `$db->get_pdo()` kullanın.

#### Cache Kullanımı

Sorgu önbellekleme ile performansı artırmak için:

```php
use nsql\database\nsql;

$db = new nsql();
// Cache yapılandırması .env/config üzerinden yönetilir
$sonuclar = $db->get_results("SELECT * FROM tablo");
// İstatistikleri görüntüleme
$stats = $db->get_all_cache_stats();
```

> **v1.5.6+ Redis**: Cache payload `nsql:j1:{json}` formatındadır. Eski `serialize` kayıtları object içermiyorsa okunabilir; aksi halde miss olur. Güvenli geçiş için Redis DB flush önerilir.

Bu örnekler, nsql kütüphanesinin migration, seed, güvenlik ve cache gibi modüllerinin gerçek bir projede nasıl kullanılabileceğini göstermektedir.

## 📜 Lisans

Bu proje MIT lisansı altında lisanslanmıştır. Detaylar için [LICENSE](LICENSE) dosyasına bakınız.
DEBUG_MODE=false

# Loglama ayarları
LOG_FILE=error_log.txt
STATEMENT_CACHE_LIMIT=100
```

---

### ⚙️ **Kullanım**

#### Veritabanı Bağlantısı

nsql sınıfını yapılandırma dosyasından veya özel parametrelerle başlatabilirsiniz:

```php
// .env dosyasından yapılandırma ile
require_once 'pdo.php';
$db = new nsql();

// veya özel parametrelerle
$db = new nsql(
    host: 'localhost',
    db: 'veritabanı_adi',
    user: 'kullanici',
    pass: 'sifre',
    debug: true // Debug modu için
);
```

## 📦 Temel Kullanım

### Satır Ekleme (Insert)

```php
$db->insert("INSERT INTO users (name, email) VALUES ('Ali', 'ali@example.com')");
echo $db->insert_id(); // Son eklenen ID
```

### Satır Güncelleme (Update)

```php
$db->update("UPDATE users SET name = 'Mehmet' WHERE id = 1");
```

### Satır Silme (Delete)

```php
$db->delete("DELETE FROM users WHERE id = 3");
```

### Tek Satır Getir (get\_row)

```php
$user = $db->get_row("SELECT * FROM users WHERE id = 1");
echo $user->name;
```

### Çoklu Satır Getir (get\_results)

```php
$users = $db->get_results("SELECT * FROM users WHERE status = :status", [
    'status' => 'active'
]);
foreach ($users as $user) {
    echo $user->email;
}
```

### Büyük Veri Setleri İçin Generator (get\_yield)

Memory dostu yaklaşım ile büyük veri setlerini işlemek için:

```php
foreach ($db->get_yield("SELECT * FROM big_table", []) as $row) {
    // Her satır tek tek işlenir, bellek şişmez
    process($row);
}
```

### Query Cache Kullanımı

Query Cache özelliği, sık kullanılan sorguların sonuçlarını önbellekte tutarak performansı artırır:

```php
// Cache otomatik olarak aktiftir (.env'de QUERY_CACHE_ENABLED=true ise)
$users = $db->get_results("SELECT * FROM users WHERE status = 'active'");
// İkinci çağrıda sonuç cache'den gelir
$users = $db->get_results("SELECT * FROM users WHERE status = 'active'");

// Cache'i manuel temizleme
// Cache istatistikleri
$stats = $db->get_all_cache_stats();
```

### Connection Pool Kullanımı

Connection Pool, veritabanı bağlantılarını yönetir ve performansı artırır:

```php
// Pool istatistiklerini görüntüleme
$stats = nsql::get_pool_stats();
print_r($stats);

// Tüm istatistikleri görüntüleme (v1.4 Yeni!)
$all_stats = $db->get_all_stats();
print_r($allStats);

// Cache istatistikleri
$cache_stats = $db->get_all_cache_stats();
echo "Query Cache Hit Rate: " . $cacheStats['query_cache']['hit_rate'] . "%\n";
echo "Statement Cache Hit Rate: " . $cacheStats['statement_cache']['hit_rate'] . "%\n";

// Query Analyzer istatistikleri
$analyzer_stats = $db->get_query_analyzer_stats();
echo "Analysis Cache Hit Rate: " . $analyzerStats['cache_hit_rate'] . "%\n";

// Memory istatistikleri
$memory_stats = $db->get_memory_stats();
echo "Current Memory: " . $memoryStats['current_usage'] . " bytes\n";
echo "Peak Memory: " . $memoryStats['peak_usage'] . " bytes\n";

// Pool otomatik olarak yönetilir, manuel müdahale gerekmez
// Min ve max bağlantı sayıları .env dosyasından ayarlanır
```

### Debug ve Loglama

```php
// Debug modunda detaylı sorgu bilgilerini görüntüle
$db->debug();

// Güvenli hata yönetimi
$result = $db->safe_execute(function() use ($db) {
    return $db->get_row("SELECT * FROM users WHERE id = :id", ['id' => 1]);
}, 'Kullanıcı bilgileri alınamadı');
```

### Güvenlik Fonksiyonları

```php
// Güvenli oturum başlatma
nsql::secure_session_start();

// CSRF koruması
$token = \nsql\database\security\session_manager::get_csrf_token();
if (nsql::validate_csrf($_POST['token'] ?? '')) {
    // Form işleme
}

// XSS koruması
echo nsql::escape_html($userInput);
```

### Transaction İşlemleri

```php
try {
    $db->begin();
    
    // Sipariş oluştur
    $db->insert(
        "INSERT INTO orders (user_id, total_amount, status) VALUES (:user_id, :total, :status)",
        [
            'user_id' => $userId,
            'total' => $totalAmount,
            'status' => 'pending'
        ]
    );
    $orderId = $db->insert_id();
    
    // Sipariş ürünlerini ekle
    foreach ($items as $item) {
        $db->insert(
            "INSERT INTO order_items (order_id, product_id, quantity, price) 
             VALUES (:order_id, :product_id, :quantity, :price)",
            [
                'order_id' => $orderId,
                'product_id' => $item->id,
                'quantity' => $item->quantity,
                'price' => $item->price
            ]
        );
        
        // Stok güncelle
        $db->update(
            "UPDATE products 
             SET stock = stock - :quantity 
             WHERE id = :id AND stock >= :quantity",
            [
                'id' => $item->id,
                'quantity' => $item->quantity
            ]
        );
    }
    
    // Tüm işlemler başarılı, kaydet
    $db->commit();
    
} catch (Exception $e) {
    // Hata durumunda geri al
    $db->rollback();
    throw $e;
}
```

---

### 📝 Parametreli Sorgu Kullanımı (Önerilen Güvenli Yöntem)

Tüm sorgularda parametre bağlama kullanmanız önerilir. Aşağıda insert, update ve delete işlemleri için güvenli örnekler verilmiştir:

```php
// Güvenli INSERT
$db->insert("INSERT INTO users (name, email) VALUES (:name, :email)", [
    'name' => 'Ali',
    'email' => 'ali@example.com'
]);
echo $db->insert_id();

// Güvenli UPDATE
$db->update("UPDATE users SET name = :name WHERE id = :id", [
    'name' => 'Mehmet',
    'id' => 1
]);

// Güvenli DELETE
$db->delete("DELETE FROM users WHERE id = :id", [
    'id' => 3
]);
```

---

## 🚀 Performans Özellikleri

### Connection Pool
- Verimli bağlantı yönetimi
- Minimum ve maksimum bağlantı sayısı kontrolü
- Otomatik bağlantı sağlığı kontrolü
- İstatistik izleme ve raporlama

### Query Cache
- Sorgu sonuçları önbellekleme
- Yapılandırılabilir önbellek süresi
- Otomatik önbellek temizleme
- Boyut limitli LRU önbellekleme

### Statement Cache
- Hazırlanmış sorguları önbellekleme
- LRU (Least Recently Used) ve LFU (Least Frequently Used) algoritmaları
- Dinamik cache size (memory kullanımına göre otomatik ayarlama)
- Otomatik boyut yönetimi
- Performans optimizasyonu

### Memory Management
- Generator kullanarak büyük veri setleri için bellek optimizasyonu
- Önbellek boyut limitleri
- Otomatik temizleme mekanizmaları

## 🔒 Güvenlik ve Performans

### Güvenlik Özellikleri
- **SQL Injection Koruması**
  - PDO prepared statements
  - LIMIT/OFFSET parametreleştirme
  - Identifier quoting (table/column name'ler backtick ile quote edilir)
  - Parametre tip kontrolü ve validasyonu
  - Otomatik parametre bağlama
- **XSS ve CSRF Koruması**
  - HTML çıktı temizleme (`escape_html()`)
  - Token tabanlı CSRF koruması
  - Otomatik token yenileme
- **Oturum Güvenliği**
  - Güvenli session başlatma ve yönetimi
  - Session fixation koruması
  - HttpOnly, Secure ve SameSite cookie ayarları
  - Otomatik session ID rotasyonu
  - Güvenli IP/HTTPS tespiti (proxy/load balancer desteği)
- **Thread Safety**
  - Connection pool file-based lock mekanizması
  - Cache invalidation race condition koruması

### Performans Optimizasyonları
- **Bağlantı Yönetimi**
  - Connection Pool ile verimli kaynak kullanımı
  - Otomatik bağlantı sağlığı kontrolü
  - Bağlantı sayısı optimizasyonu
- **Önbellekleme Sistemleri**
  - Statement Cache (LRU ve LFU algoritmaları, dinamik cache size)
  - Query Cache ile sorgu sonuçları önbellekleme (per-table TTL, cache warming)
  - Thread-safe cache invalidation
  - Otomatik önbellek temizleme
- **Bellek Optimizasyonu**
  - Generator desteği ile düşük bellek kullanımı (memory leak düzeltmeleri)
  - Circular buffer ile verimli memory yönetimi
  - Büyük veri setleri için streaming
  - Agresif cleanup ve otomatik garbage collection

### Hata Yönetimi
- Üretim/Geliştirme modu ayrımı
- Detaylı hata loglama
- Güvenli hata mesajları
- Exception wrapping (getPrevious() ile gerçek exception'a erişim)
- get_last_exception() metodu ile hata takibi
- try-catch wrapper

---

## 🏗️ Mimari Özellikler

### Katmanlı Mimari
```
   [Kullanıcı]
       |
   [index.php / uygulama]
       |
   [nsql (src/database/nsql.php)]
       |
   +-------------------+-------------------+
   |                   |                   |
[ConnectionPool]   [QueryBuilder]   [SecurityManager]
       |                   |                   |
   [PDO]              [SQL]              [Güvenlik modülleri]
```

- **config Katmanı**: Yapılandırma yönetimi (`config.php`)
- **Bağlantı Katmanı**: Veritabanı bağlantı havuzu yönetimi (`ConnectionPool.php`)
- **Core Katmanı**: Ana veritabanı işlemleri (`nsql.php`)
- **Güvenlik Katmanı**: XSS, CSRF ve Session güvenliği
- **Cache Katmanı**: Query ve Statement önbellekleme

### Tasarım Prensipleri
- SOLID prensipleri
- DRY (Don't Repeat Yourself)
- KISS (Keep It Simple, Stupid)
- Separation of Concerns

### Genişletilebilirlik
- Plugin sistemi desteği
- Olay (Event) sistemi
- Custom handler desteği

## 📊 Sürüm Matrisi ve Uyumluluk

### PHP Sürüm Uyumluluğu
| nsql Sürümü | PHP Minimum | PHP Maksimum | Notlar |
|-------------|-------------|--------------|---------|
| 1.0.x       | 8.0.0      | 8.2.x        | Tam destek |
| 1.1.x       | 8.0.0      | 8.3.x        | Tam destek |

### Veritabanı Uyumluluğu
| Veritabanı     | Minimum Sürüm | Önerilen Sürüm |
|----------------|---------------|----------------|
| MySQL          | 5.7.8        | 8.0+          |
| MariaDB        | 10.2         | 10.6+         |

---

### 🧠 Yeni Özellikler
 
## ⚡ Benchmark Sonuçları (v1.5.2)

Yerel ortam ölçümleri, `benchmarks/` betikleri ile alınmıştır (MySQL, PHP 8.2, Windows). Değerler yaklaşıktır ve ortalama tek çalıştırma sonuçlarını temsil eder.

```
1) Küçük/Orta SELECT (nsql vs PDO, bench_users tablosu)
   case         nsql_ms   pdo_ms   count
   small_1k     ~3.10     ~1.94    1000
   medium_10k   ~12.84    ~12.38   10000

Yorum: Küçük setlerde nsql’in ek güvenlik/katman maliyeti küçük bir fark yaratır; orta setlerde fark kapanır.

2) Generator vs Array (get_yield vs get_results)
   mode        time_ms   mem_peak
   generator   ~4.6      ~4 MB
   array       ~41.9     ~16 MB

Yorum: Büyük veri setlerinde get_yield belirgin şekilde daha hızlı ve bellek dostu.

3) Cache Hit/Miss (aynı sorgu iki kez)
   phase    time_ms   hit_rate
   first    ~5.8      ~50%
   second   ~0.39     ~50%

Yorum: İkinci çağrıda cache sayesinde ciddi hızlanma elde edilir. TTL ve limit değerleri workload’a göre ayarlanmalıdır.
```

Benchmark’ları çalıştırmak için:

```bash
php benchmarks/select_small_vs_large.php
php benchmarks/iterators_vs_array.php
php benchmarks/cache_hit_miss.php
```

### SQL Sabitlerini Otomatik Parametreye Çevirme

```php
// Otomatik olarak :param1 ve :param2 parametrelerine çevrilir
$db->get_row("SELECT * FROM users WHERE id = 5 AND status = 'active'");
```

Bu özellik sayesinde doğrudan SQL içerisine sabit veri yazabilir, `nsql` sınıfı bu değerleri otomatik olarak `PDO` parametrelerine çevirir.

### Statement Cache Desteği

Aynı SQL sorgusu birden fazla kez çalıştırıldığında `prepare()` işlemi tekrar yapılmaz, bu da performansı artırır.

### Gelişmiş `debug()` Metodu

Hata oluştuğunda sorguyu ve parametreleri detaylı biçimde HTML formatında gösterir.

```php
$db->debug(); // Hatalı sorgularda otomatik olarak çalışır
```

---

### 🔍 Debug ve Hata Yönetimi

#### Hata Kodları ve Çözümleri

| Hata Kodu | Açıklama | Çözüm |
|-----------|----------|--------|
| 2006 | MySQL server has gone away | Bağlantı otomatik yenilenir |
| 2013 | Lost connection to MySQL server | Bağlantı otomatik yenilenir |
| 1045 | Access denied | Veritabanı kimlik bilgilerini kontrol edin |
| 1049 | Unknown database | Veritabanının varlığını kontrol edin |
| 1146 | Table doesn't exist | Tablo adını ve veritabanını kontrol edin |
| 1062 | Duplicate entry | Benzersiz alan çakışması |

#### Debug Modu

Debug modunda aşağıdaki bilgileri görüntüleyebilirsiniz:

```php
// Debug modu ile başlatma
$db = new nsql(debug: true);

// veya .env dosyasında
DEBUG_MODE=true

// Sorgu detaylarını görüntüleme
$db->debug();
```

Debug çıktısı şunları içerir:
- SQL sorgusu ve parametreleri
- Hata mesajları (varsa)
- Sonuç verisi (tablo formatında)
- Query execution detayları

#### Güvenli Hata Yönetimi

```php
// Hata yönetimi için safe_execute kullanımı
$result = $db->safe_execute(function() use ($db) {
    return $db->get_row(
        "SELECT * FROM users WHERE id = :id",
        ['id' => 1]
    );
}, 'Kullanıcı bilgileri alınamadı.');

// Üretim ortamında: Genel hata mesajı gösterir
// Geliştirme ortamında: Detaylı hata mesajı gösterir
```

#### Otomatik Loglama

Tüm SQL sorguları ve hatalar otomatik olarak log dosyasına kaydedilir:

```ini
# .env dosyasında log yapılandırması
LOG_FILE=error_log.txt
```

Log formatı:
```
[2025-05-21 10:30:15] SQL Sorgusu: SELECT * FROM users WHERE id = '1'
Parametreler: {"id": 1}
```

---

### 🧪 Test


### Unit Tests

Testler PHPUnit ile yazılmıştır. Test sınıfları `tests` dizini altında bulunmaktadır.

#### Test Sınıfı Örneği

```php
class NsqlTest extends TestCase
{
    private ?nsql $db = null;

    protected function setUp(): void
    {
        $this->db = new nsql(
            host: 'localhost',
            db: 'test_db',
            user: 'test_user',
            pass: 'test_pass'
        );
    }

    public function testCRUD()
    {
        // Insert test
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Test Name']
        );
        $this->assertIsInt($id);
        
        // Read test
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertEquals('Test Name', $row->name);
    }

    // Edge case örneği: Boş veri ekleme
    public function testInsertEmptyName()
    {
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => '']
        );
        $this->assertIsInt($id);
    }

    // Entegrasyon testi örneği: Transaction
    public function testTransactionRollback()
    {
        $this->db->begin();
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Rollback Test']
        );
        $this->db->rollback();
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNull($row);
    }
}
```

#### Test Çalıştırma

```powershell
# Tüm testleri çalıştır
./vendor/bin/phpunit tests

# Belirli bir test sınıfını çalıştır
./vendor/bin/phpunit tests/NsqlTest.php

# Belirli bir test metodunu çalıştır
./vendor/bin/phpunit --filter testCRUD tests/NsqlTest.php
```

### Test Kapsamı ve İyi Uygulamalar

- CRUD işlemlerinin yanı sıra edge case ve hata senaryoları için testler yazın (ör. boş veri, hatalı parametre, bağlantı hatası).
- Transaction, rollback, cache, güvenlik ve migration gibi modüller için entegrasyon testleri ekleyin.
- Testlerde assert fonksiyonlarını kullanarak beklenen sonuçları doğrulayın.
- Her yeni fonksiyon veya modül için birim test eklemeyi unutmayın.
- Test veritabanı ile gerçek veritabanını ayırın, test ortamında dummy veri kullanın.
- Kodunuzu test etmeden production ortamına geçmeyin.

---

### 🔒 Oturum (Session) ve Cookie Güvenliği

Oturum başlatırken ve cookie ayarlarında güvenlik için aşağıdaki fonksiyonu kullanabilirsiniz:

```php
// Oturum başlatmadan önce çağırın
nsql::secure_session_start();
```

Bu fonksiyon;
- Oturum çerezini `HttpOnly`, `Secure` ve `SameSite=Lax` olarak ayarlar.
- HTTPS kullanıyorsanız otomatik olarak `secure` flag'ini aktif eder.
- Session fixation saldırılarına karşı ilk oturumda session ID'yi yeniler.

Oturum ID'sini manuel olarak yenilemek için:

```php
nsql::regenerateSessionId();
```

---

### 🛡️ XSS ve CSRF Koruması

#### XSS (Cross-Site Scripting) Koruması

Kütüphanede yer alan `nsql::escape_html()` fonksiyonu ile kullanıcıdan gelen verileri HTML'ye basmadan önce güvenle kaçışlayabilirsiniz:

```php
// HTML çıktısı için güvenli şekilde kullanın
echo nsql::escape_html($kullanici->isim);
```

#### CSRF (Cross-Site Request Forgery) Koruması

Formlarınızda CSRF koruması için aşağıdaki fonksiyonları kullanabilirsiniz:

**Token üretimi ve formda kullanımı:**
```php
<input type="hidden" name="csrf_token" value="<?= \nsql\database\security\session_manager::get_csrf_token() ?>">
```

**Token doğrulama:**
```php
if (!nsql::validate_csrf($_POST['csrf_token'] ?? '')) {
    die('Geçersiz CSRF token');
}
```

Bu sayede formlarınızda CSRF saldırılarına karşı koruma sağlayabilirsiniz.

---

### 🔄 Veritabanı Bağlantı Güncelliği

`nsql` sınıfı, her sorgudan önce veritabanı bağlantısının canlı olup olmadığını otomatik olarak kontrol eder. Eğer bağlantı kopmuşsa, otomatik olarak yeniden bağlanır.

Bu özellik sayesinde uzun süreli çalışan uygulamalarda veya bağlantı kopmalarında veri kaybı ve hata riski en aza indirilir.

Manuel olarak bağlantı kontrolü yapmak isterseniz:

```php
$db->ensure_connection(); // Bağlantı kopmuşsa otomatik olarak yeniden bağlanır
```

Her sorgudan önce bu kontrol otomatik olarak yapılır, ekstra bir işlem yapmanıza gerek yoktur.

---

## nsql Kullanımı ve Büyük Veri Desteği

### Temel Veri Çekme

```php
$sonuclar = $db->get_results("SELECT * FROM kullanicilar", []);
$db->debug(); // Sonuçlar tablo olarak gösterilir
```

### Büyük Veri Setleri İçin Memory Friendly Kullanım

Çok fazla satırlı sorgularda belleği şişirmemek için generator tabanlı `get_yield` fonksiyonunu kullanın:

```php
foreach ($db->get_yield("SELECT * FROM cok_buyuk_tablo", []) as $row) {
    // Her satırı tek tek işle
}
```

> Not: `get_yield` fonksiyonu generator döndürür, debug() ile toplu sonuç göstermez. Sadece satır satır işleme için uygundur.

### get_results vs get_yield: Hangi Durumda Hangisi Kullanılmalı?

- **get_results()**: Tüm sorgu sonucunu dizi olarak belleğe yükler. Küçük ve orta ölçekli veri setleri (ör. 10.000 satır veya ~10 MB altı) için hızlı ve kullanışlıdır. Sonuçlar üzerinde toplu işlem yapmak ve debug() ile tablo halinde görmek için idealdir.
- **get_yield()**: Sonuçları generator ile satır satır döndürür, belleği şişirmez. Çok büyük veri setlerinde (10.000+ satır veya 10 MB üzeri) kullanılması önerilir. Özellikle milyonlarca satırlık sorgularda PHP'nin memory_limit sınırına takılmadan güvenle çalışır.

#### Pratik Sınır ve Tavsiye
- 10.000 satıra kadar veya toplamda 10 MB altı veri için `get_results` kullanabilirsiniz.
- 10.000 satırdan fazla veya büyük veri setlerinde (50 MB ve üzeri) `get_yield` kullanmak daha güvenlidir.
- Sınır, sunucunuzun RAM kapasitesine ve PHP memory_limit ayarına göre değişebilir. Kendi ortamınızda test ederek en iyi sonucu bulabilirsiniz.

> **Not:** `get_yield()` ile alınan sonuçlar debug() ile toplu olarak gösterilmez, sadece foreach ile satır satır işlenir. `get_results()` ise debug() ile tablo halinde gösterilir.

---

### 📦 Kütüphane ve Bağımlılık Güncelliği

- Kütüphanenin ve kullandığınız tüm harici bağımlılıkların (ör. PDO, PHP sürümü, ek güvenlik kütüphaneleri) güncel tutulması önerilir.
- Güvenlik açıklarını önlemek için düzenli olarak güncellemeleri ve güvenlik bültenlerini takip edin.

- PHP sürümünüzü ve eklentilerinizi güncel tutmak için sunucu sağlayıcınızın veya kendi sisteminizin güncelleme araçlarını kullanın.

---

### 🔍 **Hata Yönetimi ve Debug**

`debug()` metodunu kullanarak son yapılan sorguyu, parametreleri ve sonucu detaylı bir şekilde görebilirsiniz:

```php
$db->debug();
```

**Debug çıktısı** şunları içerir:

* Son SQL sorgusu
* Parametreler
* Sonuç verisi (Varsa)
* Hata mesajları (Varsa)

---

### ⚡ **Performans ve Güvenlik**

* **Parametre Bağlama**: `nsql`, SQL sorgularını parametrelerle hazırlayarak SQL enjeksiyonlarına karşı korur.
* **Hazırlıklı İfadeler (Prepared Statements)**: Tüm sorgular PDO'nun hazırlıklı ifadeleri kullanılarak yapılır, bu da güvenliği artırır ve performansı optimize eder.
* **Otomatik Parametre Hazırlama**: SQL sorgusunu otomatik olarak analiz eder ve parametreleri güvenli şekilde bağlar.
* **Sorgu Önbelleği**: Aynı sorgular için hazırlıklı ifadeler bir kez oluşturulur ve cache'den tekrar kullanılır, böylece sorguların veritabanına her defasında tekrar hazırlanmasını engeller.

---

## 👥 Katkıda Bulunma

1. Bu depoyu fork edin
2. Feature branch'inizi oluşturun (`git checkout -b feature/AmazingFeature`)
3. Değişikliklerinizi commit edin (`git commit -m 'Add some AmazingFeature'`)
4. Branch'inizi push edin (`git push origin feature/AmazingFeature`)
5. Pull Request oluşturun

### Kod Standartları
- PSR-12 kod standartlarına uyun
- PHPDoc ile dökümantasyon ekleyin
- Unit testler ekleyin
- Performans ve güvenlik göz önünde bulundurun

## 📝 Sürüm Geçmişi

- v1.5.9 (2026-07-29)
  - Transaction metotları yalnızca `transaction_trait` içinde (#7)

- v1.5.8 (2026-07-29)
  - `.env.example` tek şablon; pool/cache env → config alias mapping (#13)

- v1.5.7 (2026-07-29)
  - Composer paket adı Packagist ile hizalandı: `ngunenc/nsql` (#5)

- v1.5.6 (2026-07-29)
  - Güvenlik: Redis cache JSON payload; object injection engellendi (#26)

- v1.5.5 (2026-07-28)
  - Çift PDO bağlantısı giderildi (`nsql` composition); health/metrics için monitoring token (#3, #4)

- v1.5.4 (2026-07-28)
  - Yapı: `.gitignore` güçlendirildi; `.php-cs-fixer.cache` tracking'den çıkarıldı

- v1.5.3 (2026-07-28)
  - Güvenlik: `encryption.key` git'ten kaldırıldı; `.gitignore` ve anahtar üretim dokümantasyonu

- v1.5.2 (2026-04-10)
  - `.env` / proje kökü: `set_project_root`, `NSQL_PROJECT_ROOT`, doküman güncellemeleri

- v1.5.1 (2026-04-10)
  - PHP 8.4: connection pool ve cache lock için stream tutamaçlarında tip/PHPDoc düzeltmesi

- v1.5.0 (2025-01-27)
  - Thread-safe connection pool (file-based lock)
  - SQL injection koruması iyileştirmeleri (LIMIT/OFFSET parametreleştirme, identifier quoting)
  - Güvenli IP/HTTPS tespiti (get_client_ip, is_https)
  - Exception handling iyileştirmeleri (exception wrapping, get_last_exception)
  - Memory leak düzeltmeleri (generator, connection pool circular buffer)
  - Cache invalidation race condition koruması
  - LFU cache algoritması ve dinamik cache size
  - Per-table TTL ve cache warming stratejileri
  - Type hints ve PHPDoc iyileştirmeleri
  - Magic number'lar config'e taşındı

- v1.4.0
  - Connection Pool optimizasyonları
  - Memory Management iyileştirmeleri
  - Cache performans optimizasyonları
  - Query Analyzer caching
  - Gelişmiş Error Handling

- v1.1.0
  - Query Cache özelliği eklendi
  - Connection Pool desteği eklendi
  - Gelişmiş debug sistemi
  - Performans iyileştirmeleri

- v1.0.0
  - İlk kararlı sürüm
  - Temel PDO wrapper fonksiyonları
  - Statement cache
  - Güvenlik özellikleri

## 🛠 Geliştirme Komutları

### Test ve Kalite Kontrolü

```bash
# Test veritabanını kur
composer test:setup

# Tüm testleri çalıştır
composer test

# Test veritabanını temizle
composer test:cleanup

# Tam test döngüsü (kurulum + test + temizlik)
composer test:full
```

### Kod Kalitesi

```bash
# PSR-12 kod standardı kontrolü
composer lint

# PHPStan static analysis
composer stan

# PHP CS Fixer ile kod formatlama
composer fix
```

### Migration ve Seed

```bash
# Migration'ları çalıştır
php -r "require 'vendor/autoload.php'; (new nsql\database\migration_manager())->migrate();"

# Seed verilerini yükle
php -r "require 'vendor/autoload.php'; (new nsql\database\migration_manager())->seed();"
```

## 📊 Performans Metrikleri

### Test Sonuçları
- **PHPStan**: 53/122 hata düzeltildi (%57 iyileştirme)
- **PSR-12**: 800+/1000+ hata düzeltildi (%80 iyileştirme)
- **Test Coverage**: 9 test metodu, 6 başarılı
- **Memory Usage**: Optimize edilmiş connection pool ile düşük bellek kullanımı

### Özellik Durumu
- ✅ **Core Features**: Tamamlandı
- ✅ **Security**: Tamamlandı
- ✅ **Performance**: Tamamlandı
- ✅ **Testing**: Tamamlandı
- ✅ **Documentation**: Güncellendi

## 📄 Lisans

Bu proje MIT lisansı altında lisanslanmıştır. Detaylı bilgi için [LICENSE](LICENSE) dosyasına bakın.

## 🙏 Teşekkürler

- PDO topluluğu
- Katkıda bulunan tüm geliştiriciler
- Bug report eden kullanıcılar

---

Geliştirici: [Necip Günenç](https://github.com/ngunenc)
Son Güncelleme: 27 Ocak 2025

## 🎯 Planlanan Özellikler

### v1.2.0 - Q3 2025
- PostgreSQL desteği
- SQLite desteği
- Query Builder geliştirmeleri

### v1.3.0 - Q4 2025
- Redis önbellek entegrasyonu
- Migration sistemi
- Şema validasyonu

### v1.4.0 - Q1 2026
- Otomatik backup sistemi
- CLI araçları
- Docker desteği

### v2.0.0 - 2026
- Tam ORM desteği
- NoSQL adaptörleri
- Event sistemi
- Plugin sistemi

---

## 🌐 Uluslararasılaştırma ve Lokalizasyon (i18n & l10n)

nsql kütüphanesi, çoklu dil desteği ve lokalizasyon için aşağıdaki imkanları sunar:

### 1. Veritabanı Charset ve Collation
- Tüm örneklerde ve .env dosyasında `DB_CHARSET=utf8mb4` kullanılır. Bu ayar, Unicode karakter desteği sağlar ve çoklu dil veri saklama için uygundur.
- Tablo oluştururken charset ve collation ayarlarını belirtin:

```sql
CREATE TABLE kullanicilar (
    id INT PRIMARY KEY,
    ad VARCHAR(255),
    email VARCHAR(255)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

### 2. Dil Dosyası Entegrasyonu
- Uygulamanızda hata mesajları, arayüz metinleri ve loglar için dil dosyası kullanabilirsiniz.
- Örnek PHP dil dosyası:

```php
// lang/tr.php
return [
    'user_not_found' => 'Kullanıcı bulunamadı',
    'db_error' => 'Veritabanı hatası oluştu',
    'login_success' => 'Giriş başarılı',
];
```

Kullanım:
```php
$lang = require 'lang/tr.php';
echo $lang['user_not_found'];
```

### 3. Dinamik Dil Seçimi
- Kullanıcıya göre dil dosyası seçimi yapılabilir:

```php
$locale = $_GET['lang'] ?? 'tr';
$lang = require "lang/{$locale}.php";
```

### 4. Tarih, Para ve Sayı Formatları
- PHP `Intl` eklentisi ile tarih, para ve sayı formatlarını yerelleştirebilirsiniz:

```php
$fmt = new NumberFormatter('tr_TR', NumberFormatter::CURRENCY);
echo $fmt->formatCurrency(1234.56, 'TRY'); // 1.234,56 TL
```

### 5. Çoklu Dil İçin Entegrasyon Önerisi
- Tüm hata mesajlarını ve arayüz metinlerini dil dosyalarından çekin.
- Veritabanı charset ayarlarını her ortamda kontrol edin.
- Kullanıcıya dil seçimi imkanı sunun.
