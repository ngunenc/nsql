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
````

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

## 🧠 Yeni Özellikler

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

## 📄 Lisans

MIT Lisansı.

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