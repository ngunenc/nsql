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

$db = new nsql();
$db->connect(); // Veritabanı bağlantısını yapar.
```

#### Veritabanı İşlemleri

**Sorgu Çalıştırma**:
Veritabanına herhangi bir sorgu göndermek için `query()` metodunu kullanabilirsiniz:

```php
$sql = "SELECT * FROM kullanicilar WHERE id = :id";
$params = ['id' => 1];
$stmt = $db->query($sql, $params);
```

**Veri Çekme (Tekli Satır)**:
Tek bir satır verisini almak için `get_row()` metodunu kullanabilirsiniz:

```php
$result = $db->get_row("SELECT * FROM kullanicilar WHERE id = :id", ['id' => 1]);
if ($result) {
    echo $result->ad;
}
```

**Veri Çekme (Çoklu Satırlar)**:
Birden fazla veri almak için `get_results()` metodunu kullanabilirsiniz:

```php
$results = $db->get_results("SELECT * FROM kullanicilar WHERE durum = :durum", ['durum' => 'aktif']);
foreach ($results as $row) {
    echo $row->ad . "<br>";
}
```

**Tek Değer Alma (get\_var)**:
Bir sorgudan tek bir değer çekmek için `get_var()` metodunu kullanabilirsiniz:

```php
$ad = $db->get_var("SELECT ad FROM kullanicilar WHERE id = :id", ['id' => 1]);
echo $ad;
```

#### Veri Ekleme (Insert)

Veri eklemek için `insert()` metodunu kullanabilirsiniz:

```php
$sql = "INSERT INTO kullanicilar (ad, soyad) VALUES (:ad, :soyad)";
$params = ['ad' => 'Ahmet', 'soyad' => 'Yılmaz'];
$db->insert($sql, $params);
```

#### Veri Güncelleme (Update)

Veri güncellemek için `update()` metodunu kullanabilirsiniz:

```php
$sql = "UPDATE kullanicilar SET ad = :ad WHERE id = :id";
$params = ['ad' => 'Mehmet', 'id' => 2];
$db->update($sql, $params);
```

#### Veri Silme (Delete)

Veri silmek için `delete()` metodunu kullanabilirsiniz:

```php
$sql = "DELETE FROM kullanicilar WHERE id = :id";
$params = ['id' => 3];
$db->delete($sql, $params);
```

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