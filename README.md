## 📚 **nsql - PHP PDO Veritabanı Kütüphanesi**

**nsql**, PHP ile veritabanı bağlantısı ve SQL işlemlerini güvenli, hızlı ve kolay bir şekilde yapmanıza olanak tanır. PDO kullanarak veritabanı işlemlerinizi optimize eder ve SQL enjeksiyonlarına karşı güvenliği artırır.

---

### 🔧 **Kurulum**

#### 1. GitHub'dan Projeyi İndirin

Projeyi GitHub üzerinden indirebilir ya da kendi projelerinize `composer` kullanarak dahil edebilirsiniz.

```bash
git clone https://github.com/ngunenc/nsql.git
```

#### 2. Gereksinimler

* PHP 7.4+ (PHP 8.0 veya daha yeni sürümler de önerilir)
* PDO (PHP Data Objects) desteği
* MySQL, MariaDB ya da destekleyen herhangi bir veritabanı

#### 3. Bağlantı Ayarları

Projenin **`pdo.php`** dosyasındaki veritabanı bağlantı ayarlarını aşağıdaki gibi yapılandırın:

```php
$dsn = 'mysql:host=localhost;dbname=veritabani_adiniz';
$username = 'kullanici_adiniz';
$password = 'sifreniz';
```

---

### ⚙️ **Kullanım**

#### Veritabanı Bağlantısı

`nsql` sınıfı ile veritabanı bağlantısı kurmak oldukça basittir:

```php
require_once 'nsql.php';
$db = new nsql('localhost', 'veritabani_adi', 'kullanici', 'sifre');
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
$users = $db->get_results("SELECT * FROM users WHERE status = 'active'");
foreach ($users as $user) {
    echo $user->email;
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

### 🛡️ Hata Yönetimi: safeExecute ve handleException Kullanımı

Hataları güvenli şekilde yönetmek için `safeExecute` fonksiyonunu kullanabilirsiniz. Bu fonksiyon, hataları otomatik olarak loglar ve kullanıcıya sadece genel bir mesaj gösterir:

```php
$result = $db->safeExecute(function() use ($db) {
    return $db->get_row("SELECT * FROM users WHERE id = :id", ['id' => 1]);
}, 'Bir hata oluştu, lütfen tekrar deneyin.');

if ($result) {
    echo $result->name;
}
```

Geliştirme ortamında ayrıntılı hata görmek için debug modunu açabilirsiniz:

```php
$db = new nsql('localhost', 'veritabani_adi', 'kullanici', 'sifre', 'utf8mb4', true); // Son parametre true ise debug mod açık
```

Ortam değişkenleri ile bağlantı bilgilerini güvenli şekilde yönetmek için:

```php
// .env dosyanıza veya sunucu ortam değişkenlerine aşağıdakileri ekleyin:
// DB_DSN, DB_USER, DB_PASS
// Kodda ise:
$db = new nsql(); // Ortam değişkenleri otomatik kullanılır
```

---

### 🔒 Tüm Güvenlik Fonksiyonlarının Birlikte Kullanımı (Örnek Akış)

Aşağıda, CSRF, XSS, session güvenliği ve parametreli sorguların birlikte kullanıldığı örnek bir akış yer almaktadır:

```php
require_once 'pdo.php';

nsql::secureSessionStart(); // Oturumu güvenli başlat

// CSRF token üret ve formda kullan
$csrfToken = nsql::generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!nsql::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Geçersiz CSRF token');
    }
    $db = new nsql();
    $db->safeExecute(function() use ($db) {
        $db->insert("INSERT INTO users (name, email) VALUES (:name, :email)", [
            'name' => $_POST['name'],
            'email' => $_POST['email']
        ]);
    }, 'Kayıt sırasında bir hata oluştu.');
}
?>
<form method="post">
    <input type="text" name="name" required>
    <input type="email" name="email" required>
    <input type="hidden" name="csrf_token" value="<?= nsql::escapeHtml($csrfToken) ?>">
    <button type="submit">Kaydet</button>
</form>
```

---

### 🛡️ SQL Injection ve Parametre Güvenliği

- Tüm sorgularda parametre bağlama (bind) zorunlu tutulur, doğrudan string birleştirme ile sorgu çalıştırılamaz.
- Statement cache anahtarı, sadece SQL sorgusuna göre değil, parametrelerin yapısına ve tipine göre oluşturulur. Böylece farklı parametrelerle yapılan sorguların karışması ve güvenlik açığı oluşması engellenir.
- Sadece int, float, string ve null tipinde parametreler kabul edilir. Dizi, obje veya beklenmeyen tipte parametreler kullanılırsa hata fırlatılır.
- Parametre bağlama işlemi PDO'nun uygun tipleriyle otomatik olarak yapılır.

Bu sayede SQL Injection riskleri minimize edilir ve parametre güvenliği üst düzeye çıkarılır.

#### Kullanım Örneği

```php
// Güvenli parametreli sorgu örneği
$sql = "SELECT * FROM users WHERE email = :email AND status = :status";
$params = [
    'email' => 'ali@example.com',
    'status' => 'active'
];
$user = $db->get_row($sql, $params);
if ($user) {
    echo $user->name;
}
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

### 🛠️ **Yardım ve Katkı**

Eğer bu proje hakkında sorularınız varsa ya da katkı sağlamak isterseniz, [GitHub Repository'sine](https://github.com/ngunenc/nsql) göz atabilirsiniz.

Pull request'ler her zaman memnuniyetle karşılanır! 😊

---

### 📄 **Lisans**

Bu proje MIT Lisansı ile lisanslanmıştır. Daha fazla bilgi için `LICENSE` dosyasını inceleyebilirsiniz.

---

### 🎯 **Özellikler**

* Veritabanı bağlantısı ve sorgu işlemleri için güvenlikli ve hızlı bir yapı.
* Veritabanı hata yönetimi ve hata mesajları ile birlikte debug özellikleri.
* Parametreli sorgular için otomatik güvenlik desteği.
* SQL enjeksiyonlarına karşı koruma sağlayan PDO kullanımı.
* Sorgu önbellekleme ile performans iyileştirmesi.