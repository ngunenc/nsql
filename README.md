# 📚 nsql - Modern PHP PDO Veritabanı Kütüphanesi

**nsql**, PHP 8.0+ için tasarlanmış, modern, güvenli ve performanslı bir veritabanı kütüphanesidir. PDO kullanarak veritabanı işlemlerinizi optimize eder, SQL enjeksiyonlarına karşı koruma sağlar ve büyük veri setleri için memory-friendly çözümler sunar.

### 📑 İçindekiler

- [Öne Çıkan Özellikler](#-öne-çıkan-özellikler)
- [Kurulum](#-kurulum)
- [Yapılandırma](#-yapılandırma)
- [Temel Kullanım](#-temel-kullanım)
- [Güvenlik Özellikleri](#-güvenlik-özellikleri)
- [Performans Optimizasyonları](#-performans-özellikleri)
- [Debug ve Hata Yönetimi](#-debug-ve-hata-yönetimi)
- [Büyük Veri İşleme](#-büyük-veri-işleme)
- [Mimari Özellikler](#-mimari-özellikler)
- [Katkıda Bulunma](#-katkıda-bulunma)
- [Test](#-test)
- [Lisans](#-lisans)

### 🌟 Öne Çıkan Özellikler

- Modern PHP 8.0+ özellikleri (type hinting, named arguments, attributes, union types)
- .env tabanlı yapılandırma sistemi
- Güvenli parametre bağlama ve SQL injection koruması
- XSS ve CSRF güvenlik araçları
- Session güvenliği ve cookie koruması
- Statement cache ve LRU önbellekleme
- Query cache ile sorgu sonuçları önbellekleme
- Connection Pool ile bağlantı havuzu yönetimi
- Memory-friendly generator desteği (büyük veri setleri için)
- Gelişmiş debug ve loglama sistemi
- Transaction yönetimi
- Otomatik bağlantı yenileme ve retry mekanizması

---

### 🔧 **Kurulum**

#### 1. GitHub'dan Projeyi İndirin

Projeyi GitHub üzerinden indirebilir ya da kendi projelerinize `composer` kullanarak dahil edebilirsiniz.

```bash
git clone https://github.com/ngunenc/nsql.git
```

#### 2. Gereksinimler

* PHP 8.0 veya daha yeni
* PDO PHP eklentisi
* MySQL 5.7.8+ veya MariaDB 10.2+
* PHP Eklentileri:
  * pdo_mysql
  * mbstring
  * json
  * openssl (CSRF token üretimi için)
* Composer (önerilir)

#### Composer ile Kurulum

```bash
composer require ngunenc/nsql
```

veya `composer.json` dosyanıza ekleyin:

```json
{
    "require": {
        "ngunenc/nsql": "^1.0"
    }
}
```

#### 3. Yapılandırma

1. Örnek yapılandırma dosyasını kopyalayın:
```bash
copy .env.example .env
```

2. `.env` dosyasını düzenleyin:
```ini
# Veritabanı Ayarları
DB_HOST=localhost
DB_NAME=veritabani_adi
DB_USER=kullanici_adi
DB_PASS=sifre
DB_CHARSET=utf8mb4

# Connection Pool Ayarları
DB_MIN_CONNECTIONS=2
DB_MAX_CONNECTIONS=10
DB_HEALTH_CHECK_INTERVAL=60

# Query Cache Ayarları
QUERY_CACHE_ENABLED=true
QUERY_CACHE_TIMEOUT=300
QUERY_CACHE_SIZE_LIMIT=1000

# Debug modu (true/false)
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
    db: 'veritabani_adi',
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

## 🔒 Güvenlik Özellikleri

### SQL Injection Koruması
- PDO prepared statements
- Parametre tip kontrolü
- Güvenli parametre bağlama

### XSS Koruması
- HTML çıktı temizleme
- escapeHtml() yardımcı fonksiyonu
- Güvenli veri gösterimi

### CSRF Koruması
- Token tabanlı koruma
- Otomatik token yenileme
- Token doğrulama sistemi

### Session Güvenliği
- Güvenli session başlatma
- Session fixation koruması
- Güvenli cookie ayarları
- Session ID yenileme

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
| 1.0.x       | 8.0.0      | 8.3.x        | Tam destek |
| 1.1.x       | 8.0.0      | 8.4.x        | Tam destek |

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
```php
// tests/nsqlTest.php
class nsqlTest extends PHPUnit\Framework\TestCase
{
    private $db;

    protected function setUp(): void
    {
        $this->db = new nsql(
            host: 'localhost',
            db: 'test_db',
            user: 'test_user',
            pass: 'test_pass'
        );
    }

    public function testQueryCache()
    {
        $result1 = $this->db->get_results("SELECT * FROM test_table");
        $result2 = $this->db->get_results("SELECT * FROM test_table");
        $this->assertEquals($result1, $result2);
    }

    public function testConnectionPool()
    {
        $stats = nsql::getPoolStats();
        $this->assertArrayHasKey('active_connections', $stats);
        $this->assertArrayHasKey('idle_connections', $stats);
    }
}
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
- 10.000 satıra kadar veya toplamda 10 MB altı veri için `get_results()` kullanabilirsiniz.
- 10.000 satırdan fazla veya büyük veri setlerinde (50 MB ve üzeri) `get_yield()` kullanmak daha güvenlidir.
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
Son Güncelleme: 22 Mayıs 2025