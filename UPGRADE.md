# Yükseltme Rehberi

## 1.x → 2.0.0

2.0.0, 1.x boyunca bayrak ve takma adlarla duyurulan kırıcı değişiklikleri varsayılan yapar. Metot adları ve
imzaları değişmedi.

### Özet

| Değişiklik | 1.x | 2.0 | 1.x davranışına dönmek |
|------------|-----|-----|------------------------|
| Sorgu hataları ([ayrıntı](#hata-modeli-throw_on_error)) | `null` / `[]` / `false` döner | `QueryException` fırlatılır; `update()`/`delete()` `int` döner | `THROW_ON_ERROR=false` |
| `get_yield()` ([ayrıntı](#get_yield-unbuffered-varsayılan-yield_unbuffered)) | LIMIT/OFFSET parçaları | Tek sorgu, unbuffered akış | `YIELD_UNBUFFERED=false` |
| ORM tablo adı ([ayrıntı](#orm-tablo-adları-orm_table_naming)) | `BlogPost` → `blogposts` | `BlogPost` → `blog_posts` | `ORM_TABLE_NAMING=legacy` |
| Sınıf adları ([ayrıntı](#sınıf-adları-pascalcase)) | `query_builder` | `QueryBuilder` | Gerekmez: eski adlar 2.x boyunca çalışır |
| 1.x takma adları ([ayrıntı](#web-güvenlik-katmanı-namespacei)) | `nsql\database\security\session_manager` | Kaldırıldı | `nsql\security\SessionManager` kullanın |
| `model_not_found_exception` | takma ad | Kaldırıldı | `ModelNotFoundException` kullanın |

### Hızlı geçiş

1. `composer require ngunenc/nsql:^2.0`
2. Önce `.env`'e üç bayrağı `false` / `legacy` olarak ekleyip uygulamanın çalıştığını doğrulayın:
   ```env
   THROW_ON_ERROR=false
   YIELD_UNBUFFERED=false
   ORM_TABLE_NAMING=legacy
   ```
3. Bayrakları tek tek kaldırıp aşağıdaki bölümlere göre kodu uyarlayın.
4. `use nsql\database\security\{security_manager, session_manager, rate_limiter, ip_resolver, encryption, key_manager, audit_logger}`
   satırlarını `nsql\security\...` olarak değiştirin (bu takma adlar artık yok).
5. İsteğe bağlı: `use` satırlarında sınıf adlarını `PascalCase`'e çevirin (3.0'a kadar zorunlu değil).

## Hata modeli (`THROW_ON_ERROR`)

2.0.0'da sorgu hataları varsayılan olarak exception'dır (`THROW_ON_ERROR=true`). Bayrak v1.7.0'dan beri vardır;
`THROW_ON_ERROR=false` 2.x'te 1.x davranışını geri getirir.

### Ne değişiyor?

| Metot | `THROW_ON_ERROR=false` (1.x davranışı) | `THROW_ON_ERROR=true` (2.0 varsayılanı) |
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

### Kapatma (1.x davranışı)

```env
THROW_ON_ERROR=false
```

veya örnek bazında:

```php
$db->set_throw_on_error(false);  // null = .env ayarına dön
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

## Web güvenlik katmanı namespace'i

Veritabanı dışı yardımcılar v1.13.0'da opsiyonel `nsql\security` namespace'ine taşındı. 1.x'teki
`nsql\database\security\*` takma adları **2.0.0'da kaldırıldı**.

| Kaldırıldı (1.x) | 2.0 |
|------------------|------|
| `nsql\database\security\security_manager` | `nsql\security\SecurityManager` |
| `nsql\database\security\session_manager` | `nsql\security\SessionManager` |
| `nsql\database\security\rate_limiter` | `nsql\security\RateLimiter` |
| `nsql\database\security\ip_resolver` | `nsql\security\IpResolver` |
| `nsql\database\security\encryption` | `nsql\security\Encryption` |
| `nsql\database\security\key_manager` | `nsql\security\KeyManager` |
| `nsql\database\security\audit_logger` | `nsql\security\AuditLogger` |

`QueryAnalyzer` ve `SensitiveDataFilter` çekirdeğin parçası olarak `nsql\database\security` altında kalır.
Geçiş: `use` satırlarında `nsql\database\security\` → `nsql\security\` (yalnızca yukarıdaki sınıflar için).

## Sınıf adları (PascalCase)

İsimlendirme politikası [CONTRIBUTING.md](CONTRIBUTING.md)'de. 2.0'da tüm sınıf/interface/trait adları ve dosya
adları `PascalCase`'e taşındı; metot ve özellik adları `snake_case` kaldı.

| 1.x | 2.0 |
|-----|-----|
| `nsql\database\nsql` | `nsql\database\Nsql` |
| `nsql\database\config` | `nsql\database\Config` |
| `nsql\database\query_builder` | `nsql\database\QueryBuilder` |
| `nsql\database\migration_manager` / `base_migration` | `MigrationManager` / `BaseMigration` |
| `nsql\database\orm\model` | `nsql\database\orm\Model` |
| `nsql\security\session_manager` | `nsql\security\SessionManager` |

Tam liste: [`src/legacy_class_map.php`](src/legacy_class_map.php).

- Eski adlar 2.x boyunca çalışır ve 3.0'da kaldırılacak: `src/legacy_autoload.php` eski ad istendiğinde yeni
  sınıfa `class_alias` tanımlar (`instanceof`, statik çağrı ve `extends` çalışır). PHP sınıf adları büyük/küçük harf
  duyarsız olduğu için `new nsql()` / `config::get()` gibi tek kelimelik adlar doğrudan yeni sınıfa çözülür.
- `ReflectionClass::getName()` ve `get_class()` artık yeni adı döndürür; sınıf adını string olarak karşılaştıran
  kodu (`get_class($x) === 'nsql\database\query_builder'`) `instanceof` ile değiştirin.
- `nsql\database\orm\model_not_found_exception` takma adı kaldırıldı → `ModelNotFoundException`
  (`DatabaseException` alt sınıfı).

## get_yield() unbuffered varsayılan (`YIELD_UNBUFFERED`)

2.0'da `get_yield()` sorguyu tek seferde çalıştırır ve satırları sunucudan okundukça döndürür (MySQL'de unbuffered;
PostgreSQL/SQLite'ta sürücü buffered okur). Bellek sabittir ve LIMIT/OFFSET kaynaklı tekrar/atlama olmaz.

- Akış sürerken **aynı `Nsql` örneğinde başka sorgu çalıştırılamaz** (MySQL kısıtı; açık bir `RuntimeException`
  alırsınız). Döngü içinde yazma/okuma yapıyorsanız `chunk_by_id()` kullanın veya o çağrıda
  `get_yield($sql, $params, false)` ile buffered moda geçin.
- Sorgunun kendi `LIMIT`/`OFFSET`'i artık kabul edilir (buffered modda hâlâ reddedilir).
- 1.x davranışı: `YIELD_UNBUFFERED=false`.

## ORM tablo adları (`ORM_TABLE_NAMING`)

`$table` belirtilmeyen modellerde tablo adı 1.x'te `strtolower(Sınıf) . 's'` idi, 2.0'da `Inflector` ile türetilir
(bayrak v1.12.0'dan beri vardır):

| Sınıf | 1.x (`legacy`) | 2.0 (`inflector`) |
|-------|----------------|-------------------|
| `User` | `users` | `users` |
| `BlogPost` | `blogposts` | `blog_posts` |
| `Category` | `categorys` | `categories` |
| `Person` | `persons` | `people` |

1.x adlarını korumak için `ORM_TABLE_NAMING=legacy`; kalıcı çözüm olarak modelde tablo adını sabitleyin:
`protected string $table = '...';`.

### v1.12.0 ORM davranış değişiklikleri (1.x içinde)

- `has_many()` / `has_one()` / `belongs_to()` model örnekleri döndürür (`has_many()` önceden satır nesneleri döndürüyordu).
  `$item->kolon` erişimi aynı çalışır; `(array) $item` yerine `$item->to_array()` kullanın.
- `belongs_to()` artık `$owner_key` parametresine uyar (önceden her zaman ilişkili modelin birincil anahtarıyla arıyordu).
- `$db` verilmeyen modeller her seferinde yeni `nsql` açmak yerine `nsql::connection()` örneğini paylaşır.
