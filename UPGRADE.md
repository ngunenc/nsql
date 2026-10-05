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
