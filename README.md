# 📚 nsql - Modern PHP PDO Veritabanı Kütüphanesi

**nsql**, PHP 8.0+ için tasarlanmış, modern, güvenli ve yüksek performanslı bir veritabanı kütüphanesidir. PDO tabanlı bu kütüphane, gelişmiş özellikler ve optimizasyonlarla güçlendirilmiştir.

## 📑 İçindekiler

- [Özellikler](#-özellikler)
- [Proje Yapısı](#-proje-yapısı)
- [Kurulum](#-kurulum)
- [Kullanım](#-kullanım)
- [Güvenlik](#-güvenlik)
- [Performans](#-performans)
- [Örnekler](#-örnekler)

## 📂 Proje Yapısı

```
nsql/
├── src/
│   └── database/
│       ├── config.php        # Yapılandırma yönetimi
│       ├── connectionpool.php # Bağlantı havuzu yönetimi
│       ├── nsql.php         # Ana PDO wrapper sınıfı
│       └── querybuilder.php  # SQL sorgu oluşturucu
├── vendor/                  # Composer bağımlılıkları
├── composer.json           # Composer yapılandırması
├── error_log.txt          # Hata logları
└── README.md              # Dokümantasyon
```

### Sınıf Yapısı
- **config**: Yapılandırma yönetimi ve ortam değişkenleri
- **connectionpool**: Veritabanı bağlantı havuzu ve optimizasyon
- **nsql**: PDO wrapper ve temel veritabanı işlemleri
- **querybuilder**: Akıcı arayüz ile SQL sorgu oluşturma

## 🌟 Özellikler

- **Güvenlik**
  - SQL injection koruması
  - XSS ve CSRF güvenlik önlemleri
  - Güvenli oturum yönetimi
  - Parametre tipi doğrulama

- **Performans**
  - Statement önbellekleme (LRU algoritması)
  - Sorgu sonuçları önbellekleme
  - Connection Pool ile bağlantı yönetimi
  - Generator desteği ile düşük hafıza kullanımı

- **Kullanım Kolaylığı**
  - Akıcı (fluent) arayüz tasarımı
  - Otomatik bağlantı yönetimi
  - Detaylı hata ayıklama araçları
  - Kapsamlı loglama sistemi

## 🔧 Kurulum

### Gereksinimler

- PHP 8.0+
- PDO PHP eklentisi
- MySQL 5.7+ veya MariaDB 10+

Projeyi GitHub üzerinden indirebilir ya da kendi projelerinize `composer` kullanarak dahil edebilirsiniz.

```bash
git clone https://github.com/ngunenc/nsql.git
```

### Composer ile Kurulum

```bash
composer require ngunenc/nsql
```

veya `composer.json` dosyanıza ekleyin:

```json
{
    "require": {
        "ngunenc/nsql": "^1.1",
        "php": ">=8.0",
        "ext-pdo": "*",
        "ext-json": "*"
    }
}
```

ve ardından:

```bash
composer install
```

## 📖 Kullanım

### Temel Bağlantı

```php
use nsql\database\nsql;

// Basit bağlantı
$db = new nsql();

// veya özel parametrelerle
$db = new nsql(
    host: 'localhost',
    db: 'veritabani',
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
$token = nsql::generateCsrfToken();

// Token doğrulama
if (nsql::validateCsrfToken($_POST['token'])) {
    // Güvenli işlem
}
```

### XSS Koruması

```php
$guvenli_metin = nsql::escapeHtml($kullanici_girisi);
```

## 🚀 Performans

### Statement Cache

Sık kullanılan sorgular için otomatik önbellekleme yapılır ve LRU (Least Recently Used) algoritması ile yönetilir.

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
nsql::secureSessionStart();

// Oturum ID'sini yenileme
nsql::regenerateSessionId();
```

### Hata Yönetimi

```php
$db->safeExecute(function() use ($db) {
    return $db->get_results("SELECT * FROM tablo");
}, "Veriler alınırken bir hata oluştu");
```

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
$db->clearQueryCache();
```

### Connection Pool Kullanımı

Connection Pool, veritabanı bağlantılarını yönetir ve performansı artırır:

```php
// Pool istatistiklerini görüntüleme
$stats = nsql::getPoolStats();
print_r($stats);

// Pool otomatik olarak yönetilir, manuel müdahale gerekmez
// Min ve max bağlantı sayıları .env dosyasından ayarlanır
```

### Debug ve Loglama

```php
// Debug modunda detaylı sorgu bilgilerini görüntüle
$db->debug();

// Güvenli hata yönetimi
$result = $db->safeExecute(function() use ($db) {
    return $db->get_row("SELECT * FROM users WHERE id = :id", ['id' => 1]);
});
```

### Güvenlik Fonksiyonları

```php
// Güvenli oturum başlatma
nsql::secureSessionStart();

// CSRF koruması
$token = nsql::generateCsrfToken();
if (nsql::validateCsrfToken($_POST['token'])) {
    // Form işleme
}

// XSS koruması
echo nsql::escapeHtml($userInput);
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
- LRU (Least Recently Used) algoritması
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
  - Parametre tip kontrolü ve validasyonu
  - Otomatik parametre bağlama
- **XSS ve CSRF Koruması**
  - HTML çıktı temizleme (`escapeHtml()`)
  - Token tabanlı CSRF koruması
  - Otomatik token yenileme
- **Oturum Güvenliği**
  - Güvenli session başlatma ve yönetimi
  - Session fixation koruması
  - HttpOnly, Secure ve SameSite cookie ayarları
  - Otomatik session ID rotasyonu

### Performans Optimizasyonları
- **Bağlantı Yönetimi**
  - Connection Pool ile verimli kaynak kullanımı
  - Otomatik bağlantı sağlığı kontrolü
  - Bağlantı sayısı optimizasyonu
- **Önbellekleme Sistemleri**
  - Statement Cache (LRU algoritması)
  - Query Cache ile sorgu sonuçları önbellekleme
  - Otomatik önbellek temizleme
- **Bellek Optimizasyonu**
  - Generator desteği ile düşük bellek kullanımı
  - Büyük veri setleri için streaming
  - Otomatik garbage collection

### Hata Yönetimi
- Üretim/Geliştirme modu ayrımı
- Detaylı hata loglama
- Güvenli hata mesajları
- try-catch wrapper

---

## 🏗️ Mimari Özellikler

### Katmanlı Mimari
- **Config Katmanı**: Yapılandırma yönetimi (`Config.php`)
- **Bağlantı Katmanı**: Veritabanı bağlantı havuzu yönetimi (`ConnectionPool.php`)
- **Core Katmanı**: Ana veritabanı işlemleri (`pdo.php`)
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
// Hata yönetimi için safeExecute kullanımı
$result = $db->safeExecute(function() use ($db) {
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

### Test Çalıştırma

```powershell
# PHPUnit ile testleri çalıştır
./vendor/bin/phpunit tests/

# Belirli bir test sınıfını çalıştır
./vendor/bin/phpunit tests/nsqlTest.php
```

---

### 🔒 Oturum (Session) ve Cookie Güvenliği

Oturum başlatırken ve cookie ayarlarında güvenlik için aşağıdaki fonksiyonu kullanabilirsiniz:

```php
// Oturum başlatmadan önce çağırın
db::secureSessionStart(); // veya nsql::secureSessionStart();
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

Kütüphanede yer alan `nsql::escapeHtml()` fonksiyonu ile kullanıcıdan gelen verileri HTML'ye basmadan önce güvenle kaçışlayabilirsiniz:

```php
// HTML çıktısı için güvenli şekilde kullanın
echo nsql::escapeHtml($kullanici->isim);
```

#### CSRF (Cross-Site Request Forgery) Koruması

Formlarınızda CSRF koruması için aşağıdaki fonksiyonları kullanabilirsiniz:

**Token üretimi ve formda kullanımı:**
```php
<input type="hidden" name="csrf_token" value="<?= nsql::generateCsrfToken() ?>">
```

**Token doğrulama:**
```php
if (!nsql::validateCsrfToken($_POST['csrf_token'] ?? '')) {
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
$db->ensureConnection(); // Bağlantı kopmuşsa otomatik olarak yeniden bağlanır
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

### Özet
- Küçük/orta veri setleri için: `get_results` (dizi döner, debug ile tablo gösterir)
- Çok büyük veri setleri için: `get_yield` (generator döner, foreach ile satır satır işlenir)

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

## 📄 Lisans

Bu proje MIT lisansı altında lisanslanmıştır. Detaylı bilgi için [LICENSE](LICENSE) dosyasına bakın.

## 🙏 Teşekkürler

- PDO topluluğu
- Katkıda bulunan tüm geliştiriciler
- Bug report eden kullanıcılar

---

Geliştirici: [Necip Günenç](https://github.com/ngunenc)
Son Güncelleme: 24 Mayıs 2025

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