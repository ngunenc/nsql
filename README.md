Aşağıda `nsql` sınıfının yeni eklenen özelliklerini içeren örnek bir `README.md` içeriği yer alıyor. Bu belge hem kullanım örneklerini hem de tüm işlevleri özetler:

---

````markdown
# nsql - Basit ve Gelişmiş PDO Veritabanı Katmanı

`nsql`, PDO tabanlı bir veritabanı sınıfıdır. Basit, okunabilir ve güvenli bir şekilde veritabanı işlemleri yapmanızı sağlar. Ayrıca SQL içindeki sabit değerleri otomatik olarak parametreye çevirme ve hata ayıklama gibi gelişmiş özellikler sunar.

## 🚀 Özellikler

- Kolay kullanım
- PDO üzerinde çalışır
- SQL içindeki sabit değerleri otomatik parametreye çevirir
- Hazır `insert`, `update`, `delete`, `get_row`, `get_results` metodları
- SQL sorgu hatalarını detaylı şekilde gösteren `debug()` metodu
- Hazırlanmış sorgu önbellekleme (statement cache)
- Otomatik `lastInsertId` kaydı

## 🔧 Kurulum

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

Bu dosyayı `README.md` olarak projenizin kök dizinine ekleyebilirsin. İstersen bu dosyayı senin için oluşturup içeriğini bir `.md` dosyası olarak da verebilirim. Hazırlamamı ister misin?
```
