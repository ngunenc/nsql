# Yükseltme Rehberi

## 1.x → 2.0 hazırlığı: hata modeli (`THROW_ON_ERROR`, v1.7.0+)

2.0.0'da sorgu hataları her zaman exception olacak. 1.x'te bu davranış `THROW_ON_ERROR` ile açılır
(varsayılan `false`), böylece geçişi kendi hızınızda yapabilirsiniz.

### Ne değişiyor?

| Metot | `THROW_ON_ERROR=false` (1.x varsayılanı) | `THROW_ON_ERROR=true` (2.0 varsayılanı) |
|-------|------------------------------------------|------------------------------------------|
| `query()` | `QueryException` | `QueryException` |
| `get_row()` | `null` | `QueryException` |
| `get_results()` | `[]` | `QueryException` |
| `insert()` | `false` | `QueryException` |
| `update()` / `delete()` | `true` / `false` | etkilenen satır sayısı (`int`) / `QueryException` |
| `get_yield()` / `get_chunk()` / `chunk_by_id()` | sessizce biter | `QueryException` |
| `batch_insert()` / `batch_update()` | `QueryException` (rollback) | `QueryException` (rollback) |
| `safe_execute()` | `RuntimeException` **döndürür** | `RuntimeException` **fırlatır** |

Her iki modda da hata mesajı `get_last_error()` ile okunabilir ve log'a yazılır.
`QueryException::getPrevious()` orijinal `PDOException`'ı, `get_params()` maskelenmiş parametreleri verir.

### Açma

```env
THROW_ON_ERROR=true
```

veya örnek bazında:

```php
$db->set_throw_on_error(true);   // null = .env ayarına dön
```

### Kodunuzu uyarlama

```php
// Önce
if ($db->update($sql, $params) === false) {
    log_it($db->get_last_error());
}

// Sonra
try {
    $affected = $db->update($sql, $params); // int
} catch (\nsql\database\exceptions\QueryException $e) {
    log_it($e->getMessage());
}
```

- `update()` / `delete()` dönüşünü `=== true` ile kontrol eden kod `THROW_ON_ERROR=true` iken
  `int` alır. `!== false` veya `try/catch` kullanın. `0` = hata değil, "eşleşen satır yok".
- `safe_execute()` sonucunu `instanceof \Throwable` ile kontrol eden kod `try/catch`'e geçmeli.
- `batch_update()` artık başarısız bir satırı atlamıyor; tüm işlem geri alınıp `QueryException` fırlatılıyor
  (iki modda da).

## 1.x → 2.0 hazırlığı: ORM tablo adları (`ORM_TABLE_NAMING`, v1.12.0+)

`$table` belirtilmeyen modellerde tablo adı 1.x'te `strtolower(Sınıf) . 's'`, 2.0'da `inflector` ile türetilecek:

| Sınıf | 1.x (`legacy`) | 2.0 (`inflector`) |
|-------|----------------|-------------------|
| `User` | `users` | `users` |
| `BlogPost` | `blogposts` | `blog_posts` |
| `Category` | `categorys` | `categories` |
| `Person` | `persons` | `people` |

Şimdiden geçmek için `ORM_TABLE_NAMING=inflector`; tablo adınızı sabitlemek için modelde `protected string $table = '...';`.

### v1.12.0 ORM davranış değişiklikleri (1.x içinde)

- `has_many()` / `has_one()` / `belongs_to()` model örnekleri döndürür (`has_many()` önceden satır nesneleri döndürüyordu).
  `$item->kolon` erişimi aynı çalışır; `(array) $item` yerine `$item->to_array()` kullanın.
- `belongs_to()` artık `$owner_key` parametresine uyar (önceden her zaman ilişkili modelin birincil anahtarıyla arıyordu).
- `$db` verilmeyen modeller her seferinde yeni `nsql` açmak yerine `nsql::connection()` örneğini paylaşır.
