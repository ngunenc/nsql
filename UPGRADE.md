# Yükseltme Rehberi

## 2.3.0 → 2.4.0

Minor sürüm; yeni özellikler isteğe bağlıdır. Kontrol edilecek davranış farkları:

| Değişiklik | Etkilenen | Yapılacak |
|------------|-----------|-----------|
| `.env`'de tırnaksız değerdeki ` #` yorum sayılır (#93) | Değerinde boşluk + `#` geçen tırnaksız satırlar (ör. `DB_PASS=abc #123`) | Değeri tırnak içine alın: `DB_PASS="abc #123"`. Boşluksuz `#` (`ab#cd`) değişmedi |
| `export ANAHTAR=...` satırları okunur (#93) | `.env`'de `export` önekli satır bulunduran ve bunların yok sayılmasına güvenen kurulumlar | İşlem gerekmez; önceden `EXPORT ANAHTAR` adıyla yükleniyordu |
| `migrate_to('X')` X'i de çalıştırır | `migrate_to()`'yu "X'ten öncekiler" anlamında kullanan betikler | Bir önceki migration adını verin |
| Log satırlarında `\r` / `\n` kaçırılır | Metin formatlı log'u (`Logger` structured=false, `AuditLogger`) satır satır ayrıştıran araçlar | Çok satırlı mesajlar artık tek satırda `\n` olarak görünür |
| ORM kanca adları (`on_saving`, `on_deleted` …) (#113) | Modelinde bu adlarla farklı imzalı metot tanımlayan sınıflar | Metotları yeniden adlandırın veya kanca imzasına uyun (`?bool` / `void`) |

Yeni ve isteğe bağlı: `Encryption` bağlam parametresi ve `ENCRYPTION_ALLOW_V1` (#112), `RedisRateLimiter` / `RATE_LIMIT_DRIVER` (#111), `READ_WRITE_STICKY_SECONDS` (#90), `ENV_OVERRIDES_DOTENV` (#93), `$lock_version_column` (#113).

## 2.2.3 → 2.3.0

Minor sürüm; yeni özellikler isteğe bağlıdır. Kontrol edilecek davranış farkları:

| Değişiklik | Etkilenen | Yapılacak |
|------------|-----------|-----------|
| ORM `save()` yüklenmiş modelde yalnızca değişen kolonları yazar; değişiklik yoksa sorgu çalışmaz (#88) | Değişiklik olmadan `save()` çağırıp `updated_at`'in güncellenmesine ("touch") güvenen kod | `updated_at`'i açıkça atayın: `$model->set_attribute('updated_at', date('Y-m-d H:i:s'))->save()` |
| `Model::exists()` metodu eklendi (#88) | Kendi modelinde `exists()` adlı metot tanımlayan sınıflar | Metodu yeniden adlandırın (imza uyuşmazlığı fatal error verir) |
| `ORM_TRACK_EXISTS` (varsayılan `false`) (#88) | UUID / doğal anahtarlı modeller | Yeni modelin INSERT edilmesi için `ORM_TRACK_EXISTS=true` veya modelde `protected ?bool $track_exists = true;`. 3.0'da varsayılan `true` olacak; anahtarı dolu yüklenmemiş modeli UPDATE etmek için önce `find()` kullanın |
| Paylaşılan cache'te süreç içi isabet doğrulanır (#103) | `QUERY_CACHE_DRIVER=redis` / `memcached` / `set_query_cache_store()` kullananlar | Her isabet store'a bir `getMultiple` yapar. Kısa ömürlü FPM isteklerinde ek maliyeti istemiyorsanız `QUERY_CACHE_LOCAL_VERIFY=false` |
| Replica koptuğunda primary'ye düşme (#105) | Replica hatasını exception olarak bekleyen kod | Hata artık `warning` loglanıp okuma primary'de çalışır; `uses_read_replica()` false olur. Yeniden açmak için `set_read_replica()` |
| Migration kilidi ve transaction (#87) | PostgreSQL/SQLite'ta `CREATE INDEX CONCURRENTLY` gibi transaction dışı işlem yapan migration'lar; MySQL'de `GET_LOCK` yetkisi | İlgili migration'da `within_transaction()` metodunu `false` döndürecek şekilde ezin. Eşzamanlı ikinci migrate `MIGRATION_LOCK_TIMEOUT` (60 sn) bekler |
| `table('ad takma_ad')` takma ad olarak yorumlanır (#106) | Boşluk içeren tablo argümanını hata olarak bekleyen kod | Takma adlı tabloda `insert/update/delete/upsert/increment` `LogicException` verir |
| MySQL 8.0.19+ upsert sözdizimi (#91) | `upsert()` sonucu SQL'i (`get_query()` / log) metin olarak karşılaştıran testler | `VALUES(kolon)` yerine `AS nsql_new(...)` ve `nsql_cN` görülür; raw ifadelerdeki çıplak kolon adı yine mevcut satırı gösterir |
| `env.example` kaldırıldı (#95) | Bu dosyayı kopyalayan kurulum betikleri | `.env.example` kullanın |
| CLI hataları STDERR'e yazılır (#95) | `nsql` çıktısını yalnızca STDOUT'tan okuyan betikler | `2>&1` ile birleştirin; çıkış kodu değişmedi (1) |

## 2.2.2 → 2.2.3

Patch sürümü; public API kırılmadı.

| Değişiklik | Etkilenen | Yapılacak |
|------------|-----------|-----------|
| `query()` statement cache'i kullanmaz (#96) | `query()` ile her çağrıda aynı `PDOStatement` nesnesinin döndüğüne güvenen kod | Her çağrı yeni statement döndürür; nesne kimliğine güvenmeyin. `get_results()` / `get_row()` cache'i kullanmaya devam eder |
| `Nsql` constructor'ına `?array $options = null` eklendi (#98) | `Nsql`'i genişletip constructor'ı override eden ve `Nsql::connect()` kullanan alt sınıflar | `connect()` örneği `options:` adlı argümanla oluşturur; constructor'ınıza `?array $options = null` ekleyip `parent::__construct()`'a iletin |
| `connect()` seçenekleri bağlantıdan önce uygulanır (#98) | `connect()`'e yalnızca bağlantı sonrası ayarlanabilen seçenek verenler | İşlem gerekmez; seçenekler artık etkili. Farklı seçenekler ayrı havuz kullanır |
| PDO öznitelikleri doğru anahtarla geçer (#98) | `CONNECTION_TIMEOUT` ayarına güvenen uygulamalar | Zaman aşımı artık gerçekten uygulanır; çok düşük değerleri kontrol edin |
| `connect('sqlite:…')` tam yolu kullanır (#98) | `connect()` ile SQLite'a tam yolla bağlanan uygulamalar | Önceden veritabanı çalışma dizininde aynı adla açılıyordu; o dosyada veri varsa doğru yola taşıyın |
| `full_join()` desteklenmeyen sürücüde `LogicException` (#101) | MySQL / eski SQLite'ta `full_join()` çağıran kod | Önceden de SQL hatası alıyordunuz; LEFT/RIGHT JOIN + `union()` kullanın |

## 2.2.1 → 2.2.2

Patch sürümü; public API değişmedi.

| Değişiklik | Etkilenen | Yapılacak |
|------------|-----------|-----------|
| Türetilmiş tablolu sorgular cache'lenir (#89) | `QUERY_CACHE_ENABLED=true` ve `FROM (SELECT ...)` / `count()` + `group_by` kullanan uygulamalar | İşlem gerekmez; bu sorgular artık cache'ten döner ve tablo yazmalarında geçersiz olur |
| Sorgu başı ayarlar örnek bazında önbellekte (#89) | Ayarı `putenv()` ile çalışma anında değiştiren kod | `Config::set()` veya `Config::refresh()` kullanın; yalnızca `putenv()` mevcut `Nsql` örneğine yansımaz |
| `CacheTrait` `get_transaction_level()` ister (#94) | Dahili trait'i kendi sınıfında kullanan kod (desteklenen API değil) | Sınıfa `get_transaction_level(): int` ekleyin |

## 2.2.0 → 2.2.1

Patch sürümü; public API kırılmadı. Aşağıdaki davranış değişikliklerini kontrol edin:

| Değişiklik | Etkilenen | Yapılacak |
|------------|-----------|-----------|
| Session fingerprint'te `Accept-Language` yok (#86) | `SessionManager` / `secure_session_start()` kullanan uygulamalar | Yükseltme sonrası mevcut oturumlar ilk `validate()` çağrısında bir kez sonlanır (kullanıcı yeniden giriş yapar). Bunu istemiyorsanız geçiş süresince `fingerprint_fields`'e `HTTP_ACCEPT_LANGUAGE`'i ekleyin |
| Tırnaklı `.env` değerleri string kalır (#77) | `DEBUG_MODE="false"`, `DB_PORT="3306"` gibi tırnaklı bool/sayı değerleri | Bool ve sayıları tırnaksız yazın; tırnaklı `"false"` artık string'tir |
| Kimlik bilgisi anahtarları dönüştürülmez (#77) | `*_PASS`, `*_USER`, `*_NAME`, `*_TOKEN`, `*_SECRET`, `*_KEY` değerini int/bool bekleyen kod | Değer her zaman string döner; gerekiyorsa açıkça dönüştürün |
| `batch_insert()` kolon uyumsuzluğunda hata verir (#84) | Satırları farklı kolon kümeleriyle gönderen kod | Eksik kolonlara açıkça `null` verin; kolon sırası farklı olabilir |
| Yazma sorguları bağlantı kopmasında tekrar denenmez (#80) | Kopan bağlantıda `INSERT`/`UPDATE`'in otomatik tekrarına güvenen kod | Hata çağırana iletilir; gerekirse idempotent yazmaları uygulamada tekrar deneyin |
| Havuz istatistik etiketi değişti (#83) | `get_stats()['pools']` anahtarlarını saklayan izleme | Etiketler DSN + kullanıcıdan türetilir; aynı DSN/kullanıcılı havuzlar `-2`, `-3` ekiyle ayrılır |

## 2.1.1 → 2.2.0

Minor sürüm; yeni özellikler isteğe bağlıdır. Kontrol edilecekler:

| Değişiklik | Etkilenen | Yapılacak |
|------------|-----------|-----------|
| `$_SESSION['_token']` artık yok (#70) | Bu anahtarı CSRF veya başka amaçla okuyan kod | `SessionManager::get_csrf_token()` kullanın. Eski oturumlardaki `_token` değeri zararsızdır, kendiliğinden temizlenmez |
| `validate_csrf_token()` dizi / sayı token'ı reddeder | Token'ı string dışında gönderen istemciler | Token'ı form/başlıktan string olarak iletin |
| Havuz doluluk uyarısı (#71) | Aynı süreçte çok sayıda `Nsql` örneği açan uygulamalar | Log'da uyarı görürseniz örnekleri yeniden kullanın (`Nsql::connection()`); README "Bağlantı yaşam döngüsü" |
| `schema:check` `integer` ↔ `tinyint(1)` (#73) | MySQL'de durum kodu için `tinyint(1)` kullanan şemalar | İşlem gerekmez; önceki yanlış alarm kalkar |

## 2.1.0 → 2.1.1

Patch sürümü; public API kırılmadı. Aşağıdaki davranış değişikliklerini kontrol edin:

| Değişiklik | Etkilenen | Yapılacak |
|------------|-----------|-----------|
| `KeyManager` production'da anahtar üretmez (#56) | `ENCRYPTION_KEY` tanımlamadan `Encryption` kullanan kurulumlar. `ENV` tanımlı değilse ortam **production** sayılır | `ENCRYPTION_KEY` tanımlayın; geliştirmede `ENV=development` veya bilerek `ENCRYPTION_KEY_AUTO_GENERATE=true`. Daha önce `storage/keys` altında üretilmiş anahtar dosyası varsa okunmaya devam eder |
| Session ID ilk güvenli kurulumda yenilenir (#55) | Session ID'yi kendisi saklayan / karşılaştıran kod | `secure_session_start()` sonrasında `session_id()` değerini yeniden okuyun |
| Memcached kayıt formatı ve anahtar öneki (#57) | `MemcachedAdapter` | Eski kayıtlar bir kez cache miss olur; işlem gerekmez. Aynı sunucuyu paylaşan uygulamalar için `prefix` parametresi verin |
| Redis `clear()` yalnızca önekli anahtarları siler (#57) | `clear()` ile tüm veritabanını boşalttığını varsayan kod | Gerekirse `FLUSHDB`'yi kendiniz çağırın |
| Query cache store öneki bağlantıya göre (#58) | `set_query_cache_store()` / `QUERY_CACHE_STORE` | Yükseltme sonrası paylaşılan store'daki eski kayıtlar bir kez miss olur |
| `QueryOptimizer::optimize()` SQL'i yeniden yazmaz (#59) | `rewrite` çıktısına güvenen kod | Sorgu artık olduğu gibi döner; index hint'lerde tablo/index adı geçersizse `InvalidArgumentException` |
| `nsql\database\seeds\UserSeeder` kaldırıldı (#67) | Demo seeder'ı doğrudan kullanan kod | `examples/database/seeds/UserSeeder.php`'yi kendi seeds dizininize kopyalayın; şifreleri değiştirin |
| `HealthCheck` yanıtında hata ayrıntısı yok (#68) | Yanıttaki `message` / `error` alanını okuyan izleme | Ayrıntı için `new HealthCheck($db, $logger)` ile PSR-3 logger verin |
| `insert()` / `insert_id()` dönüş tipi `int\|string` (#60) | Sonucu `int` tip ipucuyla karşılayan kod | PHP int aralığı dışındaki ve sayısal olmayan id'ler `string` döner; `(int)` dönüşümü gerekiyorsa açıkça yapın |
| `SELECT ... INTO` primary'de çalışır (#65) | Read/write split | İşlem gerekmez |

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
