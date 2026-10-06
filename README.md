# 📚 nsql - Modern PHP PDO Veritabanı Kütüphanesi v2.2.0

**nsql**, PHP 8.1+ için tasarlanmış, modern, güvenli ve yüksek performanslı bir veritabanı kütüphanesidir. PDO tabanlı bu kütüphane, gelişmiş özellikler ve optimizasyonlarla güçlendirilmiştir.

> **🚀 v1.5.0 Yeni Özellikler**: Thread-safe connection pool, LFU cache algoritması, per-table TTL, cache warming, identifier quoting, güvenli IP/HTTPS tespiti, gelişmiş exception handling ve memory leak düzeltmeleri!
>
> **v1.5.1**: PHP 8.4 uyumluluğu — kilit dosyası stream tutamaçlarında native `resource` tipleri kaldırıldı; PHPDoc ile belgelendi (`connection_pool`, `cache_trait`).
>
> **v1.5.2**: `.env` ile veritabanı yapılandırması — proje kökü tespiti (`NSQL_PROJECT_ROOT`, `config::set_project_root`), dokümantasyon ve örnekler güncellendi.
>
> **v1.5.3**: Güvenlik — `storage/keys/encryption.key` git'ten kaldırıldı; anahtar üretim / rotation dokümante edildi. Eski commit'li anahtar compromised kabul edilmeli.
>
> **v1.5.4**: Yapı — `.gitignore` güçlendirildi (keys, logs, coverage, tool caches); tracked `.php-cs-fixer.cache` kaldırıldı.
>
> **v1.5.5**: Çift PDO bağlantısı düzeltildi (composition + pool); health/metrics monitoring token zorunlu (#3, #4).
>
> **v1.5.6**: Redis cache object injection riski giderildi — güvenli JSON payload (`nsql:j1:`) (#26).
>
> **v1.5.7**: Composer paket adı Packagist ile hizalandı: `ngunenc/nsql` (#5).
>
> **v1.5.8**: `.env.example` tek şablon; `DB_MIN_CONNECTIONS` ↔ config mapping (#13).
>
> **v1.5.9**: Transaction metotları tek kaynakta (`transaction_trait`); sınıf kopyaları kaldırıldı (#7).
>
> **v1.5.10**: Vendor paket dizinine yazım engellendi; Composer `--prefer-dist` / dirty tree kurtarma dokümante (#28).
>
> **v1.5.11**: Test suite Unit/Integration olarak bölündü; ORM/migration/cache smoke testleri (#10).
>
> **v1.5.12**: Pool config default/constant/env tek kaynak; Dockerfile `composer install` hataları yutulmuyor (#27, #19).
>
> **v1.5.13**: Sync script path CLI/env; CI Ubuntu+MySQL ana gate; gerçek coverage clover + Codecov (#6, #20, #11).
>
> **v1.5.14**: Query cache yazma/rollback sonrası eski veri döndürmüyor; cache artık varsayılan **kapalı** (opt-in). Konumsal `?` parametreleri ve `batch_insert` / `batch_update` düzeltildi (#29).
>
> **v1.5.15**: Connection pool DSN başına ve süreç içi (kilit dosyası yok); kullanımdaki bağlantılar artık silinmiyor. Kopan bağlantıda statement yeniden hazırlanarak otomatik yeniden bağlanma, transaction içinde ise `ConnectionException` (#30, #31).
>
> **v1.5.16**: ORM güvenliği — constructor dahil mass assignment yalnızca `$fillable` alanları kabul eder (boşsa hiçbiri); tablo/kolon adları doğrulanıp quote edilir; `hidden` alanlar artık kaydediliyor (#32).
>
> **v1.5.17**: `query_builder::first()` ve `Model::find()` düzeltildi; `get_row()` `LIMIT ?`/`:param`, `FOR UPDATE`, `LOCK IN SHARE MODE` ve sondaki `;` ile çalışıyor (#34).
>
> **v1.5.18**: Query builder yalnızca `kolon`, `tablo.kolon`, `tablo.*` ve izinli aggregate ifadelerini kabul ediyor; hepsi driver'a göre quote ediliyor. `order_by('SLEEP(5)')` gibi ifadeler reddediliyor. Serbest SQL için `select_raw()`, `where_raw()`, `order_by_raw()`, `group_by_raw()`, `having_raw()` (#33).
>
> **v1.5.19**: Query builder subquery / UNION / having sorguları gerçek veritabanında çalışıyor; placeholder'lar derleme anında tek sayaçla üretiliyor, `compile()` yan etkisiz; `offset()` eklendi (#35).
>
> **v1.5.20**: Migration yükleme/yol düzeltmeleri, base_migration ile bağlantı enjeksiyonu, tembel migrations tablosu, vendor/bin/nsql (#36).
>
> **v1.5.21**: Proxy başlıkları yalnızca TRUSTED_PROXIES listesindeki adreslerden kabul ediliyor; yeni ip_resolver (#37).
>
> **v1.5.22**: Rate limiter: doğru token bucket, SELECT ... FOR UPDATE ile atomik güncelleme, config::get, security_manager bağlantısı (#38).
>
> **v1.5.23**: Şifreleme v2: ham 32 byte anahtar, 12 byte IV, key_id ile rotation sonrası çözme; v1 verisi okunmaya devam ediyor (#39).
>
> **v1.5.24**: PHPStan seviyesi tek kaynakta: `phpstan.neon` level 8 + baseline; `composer stan` ve CI aynı config'i kullanıyor (#21). `bin/nsql` çalıştırılabilir (#12).
>
> **v1.5.25**: Boş `src/database/schema/` stub'ı ve şema validasyonu vaatleri kaldırıldı; MVP ayrı roadmap issue'sunda (#8, #54).
>
> **v1.5.26**: Kökteki demo `examples/basic.php`'ye, monitoring örnekleri `examples/monitoring/`'e taşındı; tek storage kökü; `debug()` log'u artık CWD'ye değil `storage/logs`'a yazıyor; `bin/` dist pakete dahil (#22).
>
> **v1.5.27**: Test altyapısı: entegrasyon testleri cache açık modda da koşuyor (`composer test:cache`, CI), query builder testleri gerçek sorgu çalıştırıyor; `config::set()` bootstrap öncesi çağrıda `.env` tarafından ezilmiyor (#40).
>
> **v1.5.28**: Monitoring token'ı `?token=` URL parametresiyle varsayılan olarak kabul edilmiyor; yalnızca başlık. Gerekirse `NSQL_MONITORING_ALLOW_QUERY_TOKEN=true` (#41).
>
> **v1.5.29**: Hassas veri maskeleme tek kaynakta (`sensitive_data_filter`, `SENSITIVE_KEYS`); debug log/çıktı, logger context ve audit log maskeli; `interpolate_query` `:id`/`:id2` çakışması yok; bağlantı hatası mesajında kullanıcı adı/host yok (#42).
>
> **v1.5.30**: session_manager aktif oturumu yok etmiyor; `secure` HTTPS'e göre otomatik, HSTS yalnızca HTTPS + opt-in, `X-XSS-Protection` kaldırıldı, fingerprint'te IP yok, CSRF token süreli; tek session API'si (#43).
>
> **v1.5.31**: Query builder: `where_in` / `where_not_in` ve `where(..., 'IN', [...])` (boş dizi güvenli), `where_null` / `where_not_null`, `IS` / `= null` doğru SQL, LIMIT'siz `offset()`, boş string yapılandırılabilir (#48).
>
> **v1.5.32**: Query cache iç yapısı: O(1) LRU, eviction/expiry sonrası tablo-tag eşlemeleri temizleniyor (sınırsız büyüme giderildi), gereksiz dosya kilidi ve cache_version kaldırıldı (#46).
>
> **v1.5.33**: Her sorgudan önce atılan `SELECT 1` ping kaldırıldı (yalnızca 30+ sn boşta kalma sonrası), `debug_backtrace` yalnızca debug modunda (#44).
>
> **v1.6.0**: Gerçek streaming `get_yield()` (MySQL unbuffered, `YIELD_UNBUFFERED`), keyset tabanlı `chunk_by_id()`, `memory_limit`'e oranlanan ve `.env` ile ayarlanabilen bellek eşikleri (#45).
>
> **v1.7.0**: Tek hata modeli: `THROW_ON_ERROR=true` ile tüm sorgu metotları `QueryException` fırlatır, `update()`/`delete()` etkilenen satır sayısı döndürür; `safe_execute` sözleşmesi netleşti, geçiş rehberi `UPGRADE.md` (#47).
>
> **v1.8.0**: `transaction(callable, attempts)`: otomatik commit/rollback, iç içe çağrıda savepoint, deadlock/lock wait'te yeniden deneme; DDL implicit commit sonrası `commit()` artık hata vermiyor (#50).
>
> **v1.9.0**: Query builder yazma işlemleri (`insert`, `insert_many`, `update`, `delete`, `upsert`), yardımcılar (`count`, `exists`, `pluck`, `value`, `paginate`), `or_where` / gruplama / `where_between` / `when` ve açık `query_builder::raw()` (#49).
>
> **v1.9.1**: İç mimari: `nsql` god object'i sorumluluklara göre parçalandı (1742 → ~490 satır); public API değişmedi (#16).
>
> **v1.9.2**: CI onarımı ve platform düzeltmesi: minimum PHP 8.1 olarak doğru bildirildi, entegrasyon testlerinin CI'da bağlanamamasına yol açan test sızıntısı giderildi, PSR-12 lint ruleset'i eklendi.
>
> **v1.9.3**: CI'daki son iki hata giderildi: memcached_adapter sunucu yokken kullanılabilir görünüyordu; test fixture tablosunda eksik kolon.
>
> **v1.10.0**: PSR-3 logger (`set_logger`), PSR-16 paylaşılan query cache store (`set_query_cache_store`), `on_query()` sorgu dinleyicileri ve `SLOW_QUERY_THRESHOLD_MS` yavaş sorgu logu (#52).
>
> **v1.10.1**: Redis/Memcached adaptörleri query cache'e bağlandı: `QUERY_CACHE_DRIVER=redis|memcached` ile süreçler arası paylaşılan cache; production için Redis önerisi ve process içi cache sınırları dokümante edildi (#18).
>
> **v1.11.0**: İsimlendirilmiş çoklu bağlantı ve okuma/yazma ayrımı (#51)
>
> **v1.11.1**: CI'da PostgreSQL ve SQLite testleri; sürücüden bağımsız migration ve rate limiter (#53)
>
> **v1.12.0**: ORM ilişkileri, casting, guarded, soft delete ve inflector (#9)
>
> **v1.13.0**: Web güvenlik yardımcıları opsiyonel nsql\security namespace'ine taşındı (#25)
>
> **v1.13.1**: İsimlendirme politikası (CONTRIBUTING.md) ve PascalCase exception zorunluluğu (#24)
>
> **v1.13.2**: Coverage %65 ve CI'da %50 eşiği; DSN ayrıştırma düzeltmesi (#40)
>
> **v1.13.3**: Doküman ve kırık link temizliği; LICENSE dosyası, gerçekçi yol haritası, monitoring-only OpenAPI (#14)
>
> **v2.0.0**: Major sürüm: sınıf adları PascalCase (eski adlar 2.x boyunca çalışır), THROW_ON_ERROR / YIELD_UNBUFFERED / inflector varsayılan, 1.x takma adları kaldırıldı. Geçiş: [UPGRADE.md](UPGRADE.md)
>
> **v2.1.0**: Şema doğrulama MVP: PHP ile tanımlanan tablo/kolon yapısı canlı veritabanıyla karşılaştırılır; `nsql schema:check` CLI komutu (#54).
>
> **v2.1.1**: Güvenlik ve kararlılık düzeltmeleri: session fixation, production'da anahtar üretimi kapalı, Redis/Memcached `clear()` yalnızca kendi önekini siler, PostgreSQL `INSERT ... RETURNING`, statement cache LRU. Davranış değişiklikleri: [UPGRADE.md](UPGRADE.md#210--211)
>
> **v2.2.0**: ORM eager loading (`with: ['author']`, `eager_load()`), tek kullanımlık CSRF doğrulama (`consume: true`), bağlantı havuzu %80 doluluk uyarısı, `schema:check` `tinyint(1)` ↔ `integer` uyumu. Geçiş: [UPGRADE.md](UPGRADE.md#211--220)
>
> **v1.11.0**: İsimlendirilmiş çoklu bağlantı (`nsql::connection('reporting')`, `connection_manager`) ve okuma/yazma ayrımı (`READ_WRITE_SPLIT`, `DB_READ_HOST`, `set_read_replica()`); okumalar replica'ya, yazma ve transaction primary'ye gider (#51).
>
> **v1.11.1**: CI'da PostgreSQL ve SQLite job'ları (`tests/Portable`); migration manager ve rate limiter sürücüden bağımsız hale getirildi; veritabanı başına özellik tablosu eklendi (#53).
>
> **v1.12.0**: ORM: `has_one` / `has_many` / `belongs_to` lazy load (model örnekleri), `$casts` (int, float, bool, array/json, datetime, date), `$guarded`, opsiyonel soft delete, `inflector` ile tablo adı çözümü (`ORM_TABLE_NAMING=inflector`) (#9).
>
> **v1.13.0**: Web güvenlik yardımcıları (`security_manager`, `session_manager`, `rate_limiter`, `ip_resolver`, `encryption`, `key_manager`, `audit_logger`) opsiyonel `nsql\security` namespace'ine taşındı; eski adlar 2.0'a kadar takma ad olarak çalışır (#25).
>
> **v1.13.1**: Yazılı isimlendirme politikası ([CONTRIBUTING.md](CONTRIBUTING.md)); exception'lar `PascalCase` (lint + test ile zorunlu), `model_not_found_exception` → `ModelNotFoundException` (#24).
>
> **v1.13.2**: Satır coverage %65.4 ve CI'da %50 eşiği (#40); MySQL/PostgreSQL `parse_dsn` port/dbname ayrıştırma hatası düzeltildi (`nsql::connect()` etkileniyordu).

## 🌟 Özellikler

### Core Özellikler
- PDO tabanlı veritabanı soyutlama
- Akıcı (fluent) sorgu arayüzü
- Otomatik bağlantı yönetimi 
- Transaction desteği
- İsimlendirilmiş çoklu bağlantı (`nsql::connection('reporting')`) ve okuma/yazma ayrımı (replica)
- Migration sistemi

### Güvenlik
- SQL injection koruması (PDO prepared statements, identifier quoting)
- XSS ve CSRF koruma mekanizmaları
- Güvenli oturum yönetimi
- Rate limiting ve DDoS koruması 
- Hassas veri filtreleme
- Süreç içi, DSN başına connection pool
- Güvenli IP/HTTPS tespiti (proxy/load balancer desteği)

### Performans (v1.5.0 Optimizasyonları)
- **Connection Pool**: DSN + kullanıcı başına süreç içi havuz, ihtiyaç anında bağlantı açma
- **Memory Management**: Gelişmiş bellek yönetimi (circular buffer, agresif cleanup)
- **Cache Performance**: LFU algoritması desteği, dinamik cache size, per-table TTL
- **Cache Warming**: Önceden yükleme stratejileri ile performans artışı
- **Query Analyzer**: Analiz sonuçları cache'leme (100 analiz sonucu)
- **Generator Desteği**: Memory leak düzeltmeleri ile daha güvenli büyük veri işleme
- **Otomatik Optimizasyon**: Akıllı chunk size ayarlaması

### Geliştirici Araçları
- Detaylı debug sistemi
- Kapsamlı hata yönetimi
- PHPUnit test desteği
- PSR-12 kod standardı uyumluluğu
- PHPStan static analysis desteği
- PHP CS Fixer kod formatlama
- Composer script'leri ile otomatik test
- Şema doğrulama: PHP ile tanımlanan şemayı canlı DB ile karşılaştırma (`nsql schema:check`, v2.1.0+)


## 📋 Kurulum

### Sistem Gereksinimleri
- **PHP**: 8.1 veya üstü
- **PDO**: PHP PDO eklentisi
- **MySQL**: 5.7.8+ veya MariaDB 10.2+
- **OpenSSL**: Şifreleme özellikleri için
- **JSON**: Yapılandırma dosyaları için

### Composer ile Kurulum

Resmi paket adı: **`ngunenc/nsql`** ([Packagist](https://packagist.org/packages/ngunenc/nsql)).

```bash
composer require ngunenc/nsql:^2.2.0 --prefer-dist
```

> **Öneri**: Her zaman `--prefer-dist` kullanın (zip kurulumu). Source/VCS kurulumunda `vendor/ngunenc/nsql` bir git kopyası olur; paket içine yazılan dosyalar Composer update’i bozar.

> 📖 **Kullanım ve .env**: [docs/kullanim-klavuzu.md](docs/kullanim-klavuzu.md) — vendor kurulumunda `config::set_project_root(__DIR__)` veya `NSQL_PROJECT_ROOT` kullanın.

#### Alternatif: VCS (GitHub)

Packagist kullanılamıyorsa:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ngunenc/nsql.git"
        }
    ],
    "require": {
        "ngunenc/nsql": "^2.2.0"
    }
}
```

```bash
composer require ngunenc/nsql:^2.2.0 --prefer-dist --repository='{"type":"vcs","url":"https://github.com/ngunenc/nsql.git"}'
```

### Composer: `has uncommitted changes` hatası

Source kurulumda şu hata görülebilir:

```text
Source directory .../vendor/ngunenc/nsql has uncommitted changes.
```

**Kurtarma:**

```bash
cd vendor/ngunenc/nsql
git status
git checkout -- .
cd ../../..
composer clear-cache
composer update ngunenc/nsql --prefer-dist
```

Temiz kurulum gerekirse:

```bash
rm -rf vendor/ngunenc/nsql
composer install --prefer-dist
```

v1.5.10+ runtime dosyaları (key/log) uygulama köküne yazılır; yine de dist kurulumu tercih edin.

### Manuel Kurulum

```bash
git clone https://github.com/ngunenc/nsql.git
cd nsql
composer install
```

### Geliştirme Ortamı Kurulumu

```bash
# Bağımlılıkları yükle
composer install

# Test veritabanını kur
composer test:setup

# Testleri çalıştır
composer test

# Kod kalitesini kontrol et
composer lint
composer stan
```

### Yapılandırma

1. `.env.example` dosyasını `.env` olarak kopyalayın:
```bash
cp .env.example .env
```

2. `.env` dosyasındaki değerleri güncelleyin:
```env
db_host=localhost
db_name=your_database
db_user=your_username
db_pass=your_password
DEBUG_MODE=false
```

## 📚 Dokümantasyon

- [📘 Kullanım Klavuzu](docs/kullanim-klavuzu.md) - Temel kullanım ve kurulum
- [📖 Teknik Detaylar](docs/teknik-detay.md) - Mimari ve teknik bilgiler  
- [📚 API Referansı](docs/api-reference.md) - Kapsamlı API dokümantasyonu
- [📝 Örnekler](docs/examples.md) - Detaylı kullanım örnekleri
- [📋 Değişiklik Günlüğü](CHANGELOG.md) - Sürüm geçmişi ve değişiklikler

### Kısa Özet ve Temel Kullanım

#### Veritabanı Bağlantısı

```php
use nsql\database\Nsql;

// .env dosyasından yapılandırma ile (önerilen)
$db = new Nsql();

// veya özel parametrelerle
$db = new Nsql(
    host: 'localhost',
    db: 'veritabani_adi',
    user: 'kullanici',
    pass: 'sifre',
    charset: 'utf8mb4',
    debug: true
);
```

#### Veri Sorgulama

```php
// Tek satır getirme
$kullanici = $db->get_row(
    "SELECT * FROM kullanicilar WHERE id = :id",
    ['id' => 1]
);

// Çoklu satır getirme
$kullanicilar = $db->get_results("SELECT * FROM kullanicilar");

// Generator ile büyük veri setleri
foreach ($db->get_yield("SELECT * FROM buyuk_tablo") as $row) {
    // Hafıza dostu işlemler...
}
```

#### Veri Manipülasyonu

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


---

### Örnek Uygulama Akışı

Aşağıda, nsql kütüphanesinin bir web uygulamasında kullanıcı ekleme, listeleme ve güncelleme işlemleri için nasıl kullanılabileceğine dair tam bir akış örneği verilmiştir:

```php
use nsql\database\Nsql;

// Bağlantı
$db = new Nsql();

// 1. Kullanıcı ekleme
$db->insert("INSERT INTO kullanicilar (ad, email) VALUES (:ad, :email)", [
    'ad' => 'Ayşe',
    'email' => 'ayse@ornek.com'
]);
$yeni_id = $db->insert_id();

// 2. Tüm kullanıcıları listeleme
$kullanicilar = $db->get_results("SELECT * FROM kullanicilar");
foreach ($kullanicilar as $kullanici) {
    echo $kullanici->ad . " - " . $kullanici->email . "<br>";
}

// 3. Kullanıcı güncelleme
$db->update("UPDATE kullanicilar SET ad = :ad WHERE id = :id", [
    'ad' => 'Ayşe Yılmaz',
    'id' => $yeni_id
]);

// 4. Tek bir kullanıcıyı getirme
$ayse = $db->get_row("SELECT * FROM kullanicilar WHERE id = :id", ['id' => $yeni_id]);
echo "Güncellenen kullanıcı: " . $ayse->ad;

// 5. Kullanıcı silme
$db->delete("DELETE FROM kullanicilar WHERE id = :id", ['id' => $yeni_id]);
```

Bu örnek, nsql ile tipik bir CRUD (Create, Read, Update, Delete) akışının nasıl gerçekleştirileceğini göstermektedir. Tüm işlemler güvenli parametre bağlama ile yapılır ve hata yönetimi için try-catch blokları eklenebilir.

Kütüphanenin daha fazla özelliği ve gelişmiş kullanım örnekleri için [docs/kullanim-klavuzu.md](docs/kullanim-klavuzu.md) dosyasını inceleyebilirsiniz.

## 🧪 Test ve Kalite

### Test Çalıştırma

```bash
# Tüm testleri çalıştır
composer test

# Query cache açıkken integration + portable
composer test:cache

# Sürücüden bağımsız testler (DB_DRIVER=mysql|pgsql|sqlite)
composer test:portable

# Coverage (clover + text; Xdebug / XDEBUG_MODE=coverage) ve %50 eşik kontrolü
composer test:coverage
composer test:coverage-check
```

Ölçülen satır coverage (v1.13.2, MySQL üzerinde tüm suite): **%65.4** (`src/`; v1.5.13'te ~%30). CI, PHP 8.3 job'ında %50'nin altına düşerse başarısız olur ve en düşük kapsamalı 10 dosyayı listeler.

### Kod Kalitesi

```bash
# PHPStan static analysis
composer stan

# PHP CodeSniffer (PSR-12)
composer lint

# PHP CS Fixer
composer fix
```

### CI/CD

Proje GitHub Actions ile otomatik test edilir:
- **Ana gate**: Ubuntu + MySQL 8 — PHP 8.1–8.4 (unit, integration, portable; query cache açık/kapalı)
- **Portable**: PostgreSQL 16 ve SQLite üzerinde `tests/Portable`
- PHP 8.3 job’da `coverage/clover.xml` üretilir, %50 satır coverage eşiği uygulanır ve Codecov’a yüklenir
- Windows unit smoke isteğe bağlıdır (`continue-on-error`; MySQL service yok)

## 📂 Proje Yapısı

```
nsql/
├── src/
│   └── database/
│       ├── config.php               # Yapılandırma yönetimi
│       ├── connection_pool.php      # Bağlantı havuzu yönetimi
│       ├── migration.php           # Migration arayüzü
│       ├── base_migration.php      # Bağlantısı enjekte edilen migration temeli
│       ├── migration_manager.php   # Migration yönetimi
│       ├── nsql.php               # Ana PDO wrapper sınıfı
│       ├── query_builder.php      # SQL sorgu oluşturucu
│       ├── security/             # Çekirdek: SQL analizi ve log maskeleme
│       │   ├── query_analyzer.php
│       │   └── sensitive_data_filter.php # Hassas veri filtresi
│       ├── templates/            # View şablonları
│       └── traits/               # Trait sınıfları
│           ├── cache_trait.php    # Önbellekleme işlemleri
│           ├── connection_trait.php # Bağlantı yönetimi
│           ├── debug_trait.php     # Hata ayıklama
│           ├── query_parameter_trait.php # Sorgu parametreleri
│           ├── statement_cache_trait.php # Statement önbellekleme
│           └── transaction_trait.php # Transaction yönetimi
│   └── security/                # Opsiyonel web güvenlik katmanı (nsql\security, v1.13.0+)
│       ├── security_manager.php # CSRF, XSS escape, input doğrulama
│       ├── session_manager.php  # Güvenli oturum
│       ├── rate_limiter.php     # Token bucket (veritabanı destekli)
│       ├── ip_resolver.php      # Trusted proxy ile istemci IP'si
│       ├── encryption.php / key_manager.php # Şifreleme ve anahtar rotasyonu
│       └── audit_logger.php     # Güvenlik olay logu
├── bin/nsql                    # CLI (vendor/bin/nsql)
├── examples/                   # Örnekler (pakete dahil değil)
│   ├── basic.php               # Temel kullanım demosu
│   └── monitoring/             # health.php / metrics.php endpoint örnekleri
├── tests/                      # Test dosyaları
├── .github/workflows/          # GitHub Actions CI
├── storage/                   # Tek storage kökü: logs/ (LOG_DIR), keys/
├── composer.json             # Composer yapılandırması
├── phpunit.xml               # PHPUnit yapılandırması
├── phpstan.neon              # PHPStan yapılandırması
├── .php_cs                   # PHP CS Fixer yapılandırması
├── .env.example              # Yapılandırma örneği (resmi şablon)
├── env.example               # DEPRECATED → .env.example kullanın
└── README.md                # Dokümantasyon
```

### Sınıf Yapısı

#### Temel Bileşenler
- **nsql**: PDO wrapper ve temel veritabanı işlemleri
- **config**: Yapılandırma yönetimi ve ortam değişkenleri
- **connection_pool**: Veritabanı bağlantı havuzu ve optimizasyon
- **query_builder**: Akıcı arayüz ile SQL sorgu oluşturma

#### Güvenlik Bileşenleri

**Çekirdek** (`nsql\database\security`) — veritabanı katmanının parçası, her zaman kullanılır:
- **query_analyzer**: Regex tabanlı SQL denetim / tanı aracı (`$db->analyze_sql($sql)`). SQL injection koruması **değildir**; koruma parametre bağlama ve tanımlayıcı doğrulamasıdır (`QueryBuilder`, `quote_identifier()`)
- **sensitive_data_filter**: Log, exception ve sorgu olaylarında hassas veri maskeleme

**Opsiyonel web katmanı** (`nsql\security`, v1.13.0+) — veritabanı API'sinden bağımsızdır; kullanmıyorsanız yüklenmez:
- **security_manager**: CSRF, XSS escape, input doğrulama
- **session_manager**: Güvenli oturum (`nsql::secure_session_start()` buna delege eder)
- **rate_limiter**: Veritabanı destekli token bucket
- **ip_resolver**: `TRUSTED_PROXIES` ile istemci IP'si / HTTPS tespiti
- **encryption** / **key_manager**: Şifreleme ve anahtar rotasyonu
- **audit_logger**: Güvenlik olay logu

> 1.x'teki `nsql\database\security\*` takma adları 2.0.0'da kaldırıldı (bkz. [UPGRADE.md](UPGRADE.md)). İleride bu katmanın ayrı bir pakete (`nsql/security`) ayrılması planlanıyor.

#### Veritabanı Yönetimi
- **migration_manager**: Veritabanı şema yönetimi
- **migration**: Migration arayüzü tanımı
- **seeds**: Test ve başlangıç verisi yönetimi

## 🔧 Örnek `.env` Yapılandırması

Kurulum adımları için yukarıdaki [Kurulum](#-kurulum) bölümüne bakın. Kapsamlı bir örnek:
```ini
db_host=localhost
db_name=database_name
db_user=database_user
db_pass=database_password
DB_CHARSET=utf8mb4

# Cache ayarları
QUERY_CACHE_ENABLED=true
QUERY_CACHE_DRIVER=redis   # production: redis; varsayılan memory (tek süreç)
STATEMENT_CACHE_LIMIT=100

# Güvenlik ayarları
RATE_LIMIT_ENABLED=true
# Production'da zorunlu önerilir (git'e eklemeyin):
# php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
ENCRYPTION_KEY=your-secure-base64-key
```

> **Güvenlik (v1.5.3+)**: `storage/keys/encryption.key` asla commit edilmemelidir. Anahtar yoksa kütüphane otomatik üretir; production'da `ENCRYPTION_KEY` kullanın. Detay: [`storage/keys/README.md`](storage/keys/README.md).

## 📖 Kullanım

### Temel Bağlantı

```php
use nsql\database\Nsql;

// Basit bağlantı
$db = new Nsql();

// veya özel parametrelerle
$db = new Nsql(
    host: 'localhost',
    db: 'veritabanı',
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

> Bu bölümdeki CSRF, oturum, rate limit ve şifreleme yardımcıları **opsiyonel** `nsql\security` katmanındadır (v1.13.0+). Veritabanı güvenliği (prepared statement, identifier doğrulama, log maskeleme) çekirdekte ve her zaman açıktır.

### CSRF Koruması

```php
// Token üretme
$token = \nsql\security\SessionManager::get_csrf_token();

// Token doğrulama
if (Nsql::validate_csrf($_POST['token'] ?? '')) {
    // Güvenli işlem
}
```

### XSS Koruması

```php
$guvenli_metin = Nsql::escape_html($kullanici_girisi);
```

### Proxy / Load Balancer Arkasında İstemci IP'si

`SecurityManager::get_client_ip()` (audit log, logger, rate limit anahtarı, oturum parmak izi) ve `SecurityManager::is_https()` varsayılan olarak yalnızca `REMOTE_ADDR` ve `HTTPS` / `SERVER_PORT` değerlerini kullanır. İstemcinin gönderdiği `X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`, `X-Forwarded-Proto` ve `X-Forwarded-Ssl` başlıkları, isteği doğrudan gönderen adres `TRUSTED_PROXIES` listesinde değilse **yok sayılır**.

```env
# nginx / HAProxy / AWS ALB özel ağda
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16

# Cloudflare (güncel liste: https://www.cloudflare.com/ips/)
TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,2400:cb00::/32

# Sabit IP'si olmayan tek bir load balancer: yalnızca isteği gönderen eşe güven
TRUSTED_PROXIES=*
```

`X-Forwarded-For` zinciri sağdan sola okunur; güvenilir proxy olmayan ilk adres istemci IP'sidir, bu yüzden istemcinin zincirin başına eklediği sahte adresler sonucu etkilemez.

```php
use nsql\security\IpResolver;

$resolver = new IpResolver(['10.0.0.0/8'], $_SERVER);
$ip = $resolver->client_ip();
$https = $resolver->is_https();
```

## 🚀 Performans

### Statement Cache

Sık kullanılan sorgular için otomatik önbellekleme yapılır. LRU (Least Recently Used) ve LFU (Least Frequently Used) algoritmaları desteklenir. Memory kullanımına göre dinamik cache size ayarlaması yapılır.

### Connection Pool

Bağlantılar havuzda tutulur ve gerektiğinde yeniden kullanılır, böylece performans artışı sağlanır.

### Debug Modu

```php
$db = new Nsql(debug: true);

// Sorgu çalıştır
$db->get_results("SELECT * FROM tablo");

// Debug bilgilerini görüntüle
$db->debug();
```

## 📝 Örnekler

### Güvenli Oturum Yönetimi

```php
// Güvenli oturum başlatma
Nsql::secure_session_start();

// Oturum ID'sini yenileme (ör. login sonrası)
\nsql\security\SecurityManager::regenerate_session_id();
```

### Hata Yönetimi

```php
$db->safe_execute(function() use ($db) {
    return $db->get_results("SELECT * FROM tablo");
}, "Veriler alınırken bir hata oluştu");
```

---

### Gerçek Hayat Kullanım Senaryoları

#### Migration Kullanımı

Gerçek projelerde veritabanı şemasını güncellemek için migration modülünü kullanabilirsiniz:

Migration dosyaları uygulamanızda durur; varsayılan dizin `<proje kökü>/database/migrations` (seed: `database/seeds`). `.env` içinde `MIGRATIONS_PATH` / `SEEDS_PATH` ile veya constructor parametreleriyle değiştirilebilir.

Migration sonrası tabloların beklenen yapıda olduğunu doğrulamak için `vendor/bin/nsql schema:check [--schema=yol] [--strict] [--json]` kullanılabilir; şema tanımı ve sürücü kapsamı için [API referansı](docs/api-reference.md#-şema-doğrulama-v210).

```bash
vendor/bin/nsql migrate:create create_posts_table   # database/migrations/2026_10_04_120000_create_posts_table.php
vendor/bin/nsql migrate                             # bekleyenleri uygular
vendor/bin/nsql migrate:rollback                    # son batch'i geri alır
vendor/bin/nsql migrate --path=db/schema            # farklı dizin
```

Oluşturulan dosya bir migration nesnesi döndürür; bağlantı `MigrationManager` tarafından enjekte edilir (`$this->db()`):

```php
<?php

use nsql\database\BaseMigration;

return new class extends BaseMigration {
    public function up(): void
    {
        $this->db()->query('CREATE TABLE posts (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL)');
    }

    public function down(): void
    {
        $this->db()->query('DROP TABLE IF EXISTS posts');
    }
};
```

Sınıf tanımlayan dosyalar da desteklenir: sınıf adı, tarih öneki çıkarılmış dosya adıdır (`2026_10_04_120000_create_posts_table.php` → `create_posts_table`, dosyadaki namespace ile).

```php
use nsql\database\MigrationManager;

$manager = new MigrationManager($db);              // veya new MigrationManager($db, __DIR__ . '/database/migrations')
$executed = $manager->migrate();
```

#### Seed Kullanımı

Seed dosyaları uygulamanızın seeds dizininde durur (`SEEDS_PATH`, varsayılan `database/seeds`). Yeni seeder iskeleti için `vendor/bin/nsql seed:create UserSeeder` veya `$manager->create_seeder('UserSeeder')`; örnek için repodaki `examples/database/seeds/UserSeeder.php`'ye bakın (paketle dağıtılmaz).

```php
use nsql\database\MigrationManager;

$manager = new MigrationManager($db);
$manager->seed('UserSeeder'); // database/seeds/UserSeeder.php
$manager->seed();             // dizindeki tüm seeder'lar
```

> v2.1.1: `nsql\database\seeds\UserSeeder` (sabit şifreli demo seeder) paketten kaldırıldı.

#### Güvenlik Modülleri

Gerçek uygulamalarda rate limiting ve veri şifreleme gibi güvenlik modüllerini entegre edebilirsiniz:

```php
use nsql\security\RateLimiter;
use nsql\security\SecurityManager;

// RATE_LIMIT_MAX_REQUESTS=100, RATE_LIMIT_WINDOW=60 → kova 100 token, dakikada tamamen dolar
// RATE_LIMIT_BURST=10 → aynı saniyede en fazla 10 istek
$limiter = new RateLimiter($db);
if (! $limiter->check_rate_limit(SecurityManager::get_client_ip(), 'api')) {
    http_response_code(429);
    exit('Çok fazla istek!');
}

// Ayarları kod içinde ezmek: new RateLimiter($db, null, ['max_requests' => 5, 'window' => 300, 'burst' => 5])
// Tablo ilk çağrıda oluşturulur. DDL açık transaction'ı commit edeceğinden deploy sırasında kurmak için:
$limiter->install();                    // veya migration içinde: RateLimiter::schema_sql()

use nsql\security\Encryption;
use nsql\security\KeyManager;

// Anahtar: ENCRYPTION_KEY env (base64, 32 byte) veya storage/keys/encryption.key
// Üretmek için: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
$enc = new Encryption();
$crypted = $enc->encrypt('gizli veri');   // "v2:..." (AES-256-GCM, ham 32 byte anahtar, 12 byte IV, key_id)
$plain = $enc->decrypt($crypted);         // v1 (<= 1.5.22) verileri de çözülür

// Anahtar rotation: eski anahtar storage/keys/archive/ altına (0600) taşınır,
// v2 verisi key_id ile doğru (arşiv) anahtarla çözülür.
$info = $enc->rotate_key();

// Eski verileri mevcut anahtara taşımak
if ($enc->needs_reencrypt($crypted)) {
    $crypted = $enc->reencrypt($crypted);
}

// Anahtarı açıkça verip arşivi eklemek
$enc = new Encryption($key, KeyManager::get_archived_keys());
```

> `ENCRYPTION_KEY` env ile verilen anahtar storage'dan önce gelir; rotation sonrası yeni anahtarı env'e de yazmanız gerekir. Eski anahtarlar `storage/keys/archive/` altında kaldığı sürece eski veriler çözülebilir.

#### Monitoring (health / metrics)

Örnek endpoint'ler `examples/monitoring/health.php` ve `examples/monitoring/metrics.php` içindedir (pakete dahil değildir; kendi `public/` dizininize kopyalayın). **Token zorunludur**:

```env
NSQL_MONITORING_TOKEN=uzun-rastgele-secret
# NSQL_MONITORING_ENABLED=false  # endpoint'leri kapatır
```

```bash
curl -H "Authorization: Bearer $NSQL_MONITORING_TOKEN" http://localhost/health.php
# veya: -H "X-NSQL-Monitoring-Token: $NSQL_MONITORING_TOKEN"
```

> v1.5.28+: `?token=` URL parametresi varsayılan olarak **kabul edilmez** (erişim/proxy loglarına, tarayıcı geçmişine ve `Referer`'a sızar). Başlık gönderemeyen bir izleme aracı için `NSQL_MONITORING_ALLOW_QUERY_TOKEN=true` ile açıkça açılabilir.

```bash
# yalnızca NSQL_MONITORING_ALLOW_QUERY_TOKEN=true iken:
# curl "http://localhost/health.php?token=$NSQL_MONITORING_TOKEN"
```

> v1.5.5+: `nsql` artık `PDO`'yu extend etmez. Ham PDO için `$db->get_pdo()` kullanın.

#### Cache Kullanımı

Sorgu önbellekleme ile performansı artırmak için:

```php
use nsql\database\Nsql;

$db = new Nsql();
// Cache yapılandırması .env/config üzerinden yönetilir
$sonuclar = $db->get_results("SELECT * FROM tablo");
// İstatistikleri görüntüleme
$stats = $db->get_all_cache_stats();
```

> **v1.5.6+ Redis**: Cache payload `nsql:j1:{json}` formatındadır. Eski `serialize` kayıtları object içermiyorsa okunabilir; aksi halde miss olur. Güvenli geçiş için Redis DB flush önerilir.

Bu örnekler, nsql kütüphanesinin migration, seed, güvenlik ve cache gibi modüllerinin gerçek bir projede nasıl kullanılabileceğini göstermektedir.

---

### ⚙️ **Kullanım**

#### Veritabanı Bağlantısı

nsql sınıfını yapılandırma dosyasından veya özel parametrelerle başlatabilirsiniz:

```php
// .env dosyasından yapılandırma ile
require_once __DIR__ . '/vendor/autoload.php';
$db = new \Nsql\database\Nsql();

// veya özel parametrelerle
$db = new Nsql(
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

> **v1.5.14+**: Query cache varsayılan olarak kapalıdır; `.env` içinde `QUERY_CACHE_ENABLED=true` ile açılır. Cache yalnızca `nsql` instance'ının belleğinde tutulur (worker'lar arası paylaşılmaz). Yazma işlemleri (`insert`, `update`, `delete`, `batch_*`, yazma yapan `query()`) ilgili tabloların cache'ini temizler; transaction içinde cache kullanılmaz. Tablosu tespit edilemeyen sorgular cache'lenmez.

> **v1.10.1+ — Production için Redis önerilir.** Varsayılan `QUERY_CACHE_DRIVER=memory` cache'i yalnızca o PHP sürecinin belleğinde tutar: PHP-FPM worker'ları arasında paylaşılmaz, istek bitince silinir ve bir worker'daki yazma diğer worker'ların cache'ini temizlemez. Birden fazla worker/sunucu varsa paylaşılan store kullanın:
>
> | Sürücü | Kapsam | Ne zaman |
> |---|---|---|
> | `memory` (varsayılan) | Tek süreç | CLI, worker'lar, testler, tek istekte tekrarlanan sorgular |
> | `redis` | Süreçler ve sunucular arası | **Production önerisi** (`ext-redis`) |
> | `memcached` | Süreçler ve sunucular arası | Mevcut Memcached altyapısı varsa (`ext-memcached`) |
>
> Paylaşılan store'da process içi LRU birinci seviye olarak kalır; tablo/tag geçersiz kılma tüm süreçlere yansır. Sunucuya ulaşılamazsa veya sürücü adı geçersizse WARNING loglanır ve process içi cache ile devam edilir. Kendi PSR-16 cache'inizi `$db->set_query_cache_store($psr16)` ile bağlayabilirsiniz.

```ini
QUERY_CACHE_ENABLED=true
QUERY_CACHE_DRIVER=redis        # memory | redis | memcached (CACHE_DRIVER da kabul edilir)
QUERY_CACHE_PREFIX=nsql_qc_
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
REDIS_TIMEOUT=2
# MEMCACHED_HOST=127.0.0.1
# MEMCACHED_PORT=11211
```

```php
// .env'de QUERY_CACHE_ENABLED=true ise aktiftir
$users = $db->get_results("SELECT * FROM users WHERE status = 'active'");
// İkinci çağrıda sonuç cache'den gelir
$users = $db->get_results("SELECT * FROM users WHERE status = 'active'");

// Cache'i manuel temizleme
// Cache istatistikleri
$stats = $db->get_all_cache_stats();
```

### Connection Pool Kullanımı

Connection Pool, veritabanı bağlantılarını yönetir ve performansı artırır:

> **v1.5.15+**: Havuz her DSN + kullanıcı için ayrıdır ve yalnızca PHP sürecinin belleğinde tutulur (PHP-FPM worker'ları arasında paylaşılmaz, kilit dosyası kullanılmaz). Her `nsql` örneği tek bir fiziksel bağlantı kullanır; örnek yok edildiğinde bağlantı havuza döner ve aynı süreçteki sonraki örnekler tarafından yeniden kullanılır.

#### Bağlantı yaşam döngüsü

- Her `Nsql` örneği ömrü boyunca **bir** bağlantı tutar. Aynı süreçte aynı anda yaşayan örnek sayısı `MAX_CONNECTIONS`'ı (varsayılan 15) aşarsa `Kullanılabilir bağlantı yok (havuz dolu)` hatası alınır.
- Önerilen kullanım, süreç başına tek örnektir: `Nsql::connection()` (veya `Nsql::connection('reporting')`) ilk çağrıda bağlantıyı açar, sonraki çağrılarda aynı örneği döndürür. Döngü içinde `new Nsql()` yapmayın; servis/DI container'da tek örnek paylaşın.
- Uzun ömürlü süreçlerde (worker, daemon, Swoole/RoadRunner) örnekleri saklamak yerine `Nsql::connection()` kullanın; iş bitince örneğe referans tutmayın.
- Havuz doluluğu %80'e ulaştığında havuz başına bir kez uyarı loglanır (v2.2.0+). Uyarı varsayılan olarak `error_log`'a gider; kendi logger'ınızı verebilirsiniz: `ConnectionPool::set_logger($psr3_logger)`.
- Veritabanı tarafında gereken bağlantı sayısı ≈ **FPM worker sayısı × worker başına aynı anda açık örnek sayısı** (genelde 1; replica ve isimlendirilmiş bağlantılar her biri +1). Örneğin `pm.max_children = 50` ve tek bağlantı → en fazla 50 bağlantı; MySQL `max_connections` değerini buna göre ayarlayın.

```php
use nsql\database\Nsql;

// Uygulama genelinde tek örnek
$db = Nsql::connection();
$users = $db->get_results('SELECT id, name FROM users WHERE active = ?', [1]);

// Raporlama veritabanı (DB_REPORTING_HOST / DB_REPORTING_NAME ... veya ConnectionManager::add())
$report = Nsql::connection('reporting');
```

```php
// Süreçteki tüm havuzların toplam istatistikleri
$stats = Nsql::get_pool_stats();
print_r($stats);

// Yalnızca bu örneğin havuzu
print_r($db->get_instance_pool_stats());

// Tüm istatistikleri görüntüleme (v1.4 Yeni!)
$all_stats = $db->get_all_stats();
print_r($allStats);

// Cache istatistikleri
$cache_stats = $db->get_all_cache_stats();
echo "Query Cache Hit Rate: " . $cacheStats['query_cache']['hit_rate'] . "%\n";
echo "Statement Cache Hit Rate: " . $cacheStats['statement_cache']['hit_rate'] . "%\n";

// Query Analyzer istatistikleri
$analyzer_stats = $db->get_query_analyzer_stats();
echo "Analysis Cache Hit Rate: " . $analyzerStats['cache_hit_rate'] . "%\n";

// Memory istatistikleri
$memory_stats = $db->get_memory_stats();
echo "Current Memory: " . $memoryStats['current_usage'] . " bytes\n";
echo "Peak Memory: " . $memoryStats['peak_usage'] . " bytes\n";

// Pool otomatik olarak yönetilir, manuel müdahale gerekmez
// Min ve max bağlantı sayıları .env dosyasından ayarlanır
```

### Debug ve Loglama

```php
// Debug modunda detaylı sorgu bilgilerini görüntüle
$db->debug();

// Güvenli hata yönetimi
$result = $db->safe_execute(function() use ($db) {
    return $db->get_row("SELECT * FROM users WHERE id = :id", ['id' => 1]);
}, 'Kullanıcı bilgileri alınamadı');
```

### Güvenlik Fonksiyonları

```php
// Güvenli oturum başlatma
Nsql::secure_session_start();

// CSRF koruması
$token = \nsql\security\SessionManager::get_csrf_token();
if (Nsql::validate_csrf($_POST['token'] ?? '')) {
    // Form işleme
}
// Hassas işlemlerde tek kullanımlık token (v2.2.0+): doğrulamadan sonra yenilenir
if (Nsql::validate_csrf($_POST['token'] ?? '', consume: true)) {
    // Şifre değişikliği vb.
}

// XSS koruması
echo Nsql::escape_html($userInput);
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
- Boyut limitli LRU önbellekleme (process içi)
- Redis / Memcached / PSR-16 paylaşılan store, süreçler arası tablo bazlı geçersiz kılma

### Statement Cache
- Hazırlanmış sorguları önbellekleme
- LRU (Least Recently Used) ve LFU (Least Frequently Used) algoritmaları
- Dinamik cache size (memory kullanımına göre otomatik ayarlama)
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
  - LIMIT/OFFSET parametreleştirme
  - Identifier quoting (table/column name'ler backtick ile quote edilir)
  - Parametre tip kontrolü ve validasyonu
  - Otomatik parametre bağlama
- **XSS ve CSRF Koruması**
  - HTML çıktı temizleme (`escape_html()`)
  - Token tabanlı CSRF koruması
  - Otomatik token yenileme
- **Oturum Güvenliği**
  - Güvenli session başlatma ve yönetimi
  - Session fixation koruması
  - HttpOnly, Secure ve SameSite cookie ayarları
  - Otomatik session ID rotasyonu
  - Güvenli IP/HTTPS tespiti (proxy/load balancer desteği)
- **Süreç Modeli**
  - Connection pool süreç içidir (DSN + kullanıcı başına); süreçler arası paylaşılmaz
  - Varsayılan query cache süreç içidir; süreçler arası paylaşım için `QUERY_CACHE_DRIVER=redis|memcached` veya PSR-16 store kullanın

### Performans Optimizasyonları
- **Bağlantı Yönetimi**
  - Connection Pool ile verimli kaynak kullanımı
  - Otomatik bağlantı sağlığı kontrolü
  - Bağlantı sayısı optimizasyonu
- **Önbellekleme Sistemleri**
  - Statement Cache (LRU ve LFU algoritmaları, dinamik cache size)
  - Query Cache ile sorgu sonuçları önbellekleme (per-table TTL, cache warming)
  - Tablo bazlı cache invalidation (paylaşılan store'da süreçler arası)
  - Otomatik önbellek temizleme
- **Bellek Optimizasyonu**
  - Generator desteği ile düşük bellek kullanımı (memory leak düzeltmeleri)
  - Circular buffer ile verimli memory yönetimi
  - Büyük veri setleri için streaming
  - Agresif cleanup ve otomatik garbage collection

### Hata Yönetimi
- Üretim/Geliştirme modu ayrımı
- Detaylı hata loglama
- Güvenli hata mesajları
- Exception wrapping (getPrevious() ile gerçek exception'a erişim)
- get_last_exception() metodu ile hata takibi
- try-catch wrapper

---

## 🏗️ Mimari Özellikler

### Katmanlı Mimari
```
   [Kullanıcı]
       |
   [index.php / uygulama]
       |
   [nsql (src/database/nsql.php)]
       |
   +-------------------+-------------------+
   |                   |                   |
[ConnectionPool]   [QueryBuilder]   [SecurityManager]
       |                   |                   |
   [PDO]              [SQL]              [Güvenlik modülleri]
```

- **config Katmanı**: Yapılandırma yönetimi (`config.php`)
- **Bağlantı Katmanı**: Veritabanı bağlantı havuzu yönetimi (`ConnectionPool.php`)
- **Core Katmanı**: Ana veritabanı işlemleri (`nsql.php`)
- **Güvenlik Katmanı**: XSS, CSRF ve Session güvenliği
- **Cache Katmanı**: Query ve Statement önbellekleme

### Tasarım Prensipleri
- SOLID prensipleri
- DRY (Don't Repeat Yourself)
- KISS (Keep It Simple, Stupid)
- Separation of Concerns

### Genişletilebilirlik
- PSR-3 logger (`set_logger()`, ör. Monolog)
- PSR-16 paylaşılan query cache store (`set_query_cache_store()`)
- Sorgu olay dinleyicileri (`on_query()`) ve yavaş sorgu logu (`SLOW_QUERY_THRESHOLD_MS`)

## 📊 Sürüm Matrisi ve Uyumluluk

### PHP Sürüm Uyumluluğu
| nsql Sürümü | PHP Minimum | PHP Maksimum | Notlar |
|-------------|-------------|--------------|---------|
| 1.0.x–1.9.1 | 8.0.0      | 8.3.x        | Eski sürümler, destek yok |
| 1.9.2+      | 8.1.0      | 8.4.x        | CI: 8.1–8.4 (güncel: 1.13.x) |

### Veritabanı Uyumluluğu
| Veritabanı     | Minimum Sürüm | Önerilen Sürüm | CI |
|----------------|---------------|----------------|----|
| MySQL          | 5.7.8        | 8.0+          | Tüm testler (8.0) |
| MariaDB        | 10.2         | 10.6+         | Yerel geliştirme |
| PostgreSQL     | 12           | 16+           | Portable testler (16) |
| SQLite         | 3.24         | 3.35+         | Portable testler |

### Veritabanı Başına Özellik Desteği (v1.11.1)

`DB_DRIVER=mysql|pgsql|sqlite`. "Portable" testler (`tests/Portable`, `composer test:portable`) CI'da üç veritabanında koşar.

| Özellik | MySQL / MariaDB | PostgreSQL | SQLite |
|---------|-----------------|------------|--------|
| CRUD, `insert_id()`, `batch_insert` / `batch_update` | ✅ | ✅ | ✅ |
| Query Builder (where/join/group/having/subquery/paginate, insert/update/delete) | ✅ | ✅ | ✅ |
| `upsert()` | ✅ `ON DUPLICATE KEY` | ✅ `ON CONFLICT` (`$unique_by` zorunlu) | ✅ `ON CONFLICT` (`$unique_by` zorunlu) |
| Transaction, savepoint, `transaction(callable)` | ✅ | ✅ | ✅ |
| Deadlock retry (`TRANSACTION_RETRY_ATTEMPTS`) | ✅ 1213/1205 | ✅ 40P01/40001 | — (tek yazıcı) |
| `get_yield()` unbuffered akış | ✅ | Buffered (sürücü sınırı) | Buffered |
| `chunk_by_id()` | ✅ | ✅ | ✅ |
| Query cache (process içi / Redis / Memcached) | ✅ | ✅ | ✅ |
| Migration manager | ✅ | ✅ | ✅ |
| `RateLimiter` | ✅ `FOR UPDATE` | ✅ `FOR UPDATE` | ✅ (veritabanı kilidi) |
| Okuma/yazma ayrımı (replica) | ✅ | ✅ | — |
| `get_row()` otomatik `LIMIT 1` | ✅ | ✅ | ✅ |

Notlar:
- Testlerde MySQL'e özel DDL (`ENGINE=InnoDB`, `AUTO_INCREMENT`, `ENUM`, `SHOW TABLES`) yalnızca `tests/Integration` altında kullanılır; bu testler yalnızca MySQL/MariaDB'de koşar.
- SQLite için `:memory:` yerine dosya yolu kullanın: bağlantı havuzundaki her bağlantı ayrı bir bellek veritabanı açar.
- PostgreSQL'de hatalı bir sorgudan sonra açık transaction iptal durumuna geçer; `rollback()` gerekir.

---

## ⚡ Benchmark Sonuçları (v1.5.2)

Yerel ortam ölçümleri, `benchmarks/` betikleri ile alınmıştır (MySQL, PHP 8.2, Windows). Değerler yaklaşıktır ve ortalama tek çalıştırma sonuçlarını temsil eder.

```
1) Küçük/Orta SELECT (nsql vs PDO, bench_users tablosu)
   case         nsql_ms   pdo_ms   count
   small_1k     ~3.10     ~1.94    1000
   medium_10k   ~12.84    ~12.38   10000

Yorum: Küçük setlerde nsql’in ek güvenlik/katman maliyeti küçük bir fark yaratır; orta setlerde fark kapanır.

2) Generator vs Array (get_yield vs get_results)
   mode        time_ms   mem_peak
   generator   ~4.6      ~4 MB
   array       ~41.9     ~16 MB

Yorum: Büyük veri setlerinde get_yield belirgin şekilde daha hızlı ve bellek dostu.

3) Cache Hit/Miss (aynı sorgu iki kez)
   phase    time_ms   hit_rate
   first    ~5.8      ~50%
   second   ~0.39     ~50%

Yorum: İkinci çağrıda cache sayesinde ciddi hızlanma elde edilir. TTL ve limit değerleri workload’a göre ayarlanmalıdır.
```

Benchmark’ları çalıştırmak için:

```bash
php benchmarks/select_small_vs_large.php
php benchmarks/iterators_vs_array.php
php benchmarks/cache_hit_miss.php
```

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
$db = new Nsql(debug: true);

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

> v1.5.29+: Debug çıktısı, debug log'u, structured logger context'i ve audit log aynı filtreden (`SensitiveDataFilter`) geçer. Adı `password`, `token`, `secret`, `api_key`, `auth_`, `credit_card` vb. içeren parametre ve kolonlar `********` olarak yazılır. Listeyi genişletmek için: `SENSITIVE_KEYS=phone,national_id`. Bağlantı hatalarında uygulamaya dönen exception mesajı kullanıcı adı/host içermez (`Veritabanı bağlantısı kurulamadı (SQLSTATE HY000, kod 1045).`); sürücü mesajı yalnızca log'a yazılır.

#### Güvenli Hata Yönetimi

```php
// Hata yönetimi için safe_execute kullanımı
$result = $db->safe_execute(function() use ($db) {
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
        $this->db = new Nsql(
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

    // Edge case örneği: Boş veri ekleme
    public function testInsertEmptyName()
    {
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => '']
        );
        $this->assertIsInt($id);
    }

    // Entegrasyon testi örneği: Transaction
    public function testTransactionRollback()
    {
        $this->db->begin();
        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => 'Rollback Test']
        );
        $this->db->rollback();
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNull($row);
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

### Test Kapsamı ve İyi Uygulamalar

- CRUD işlemlerinin yanı sıra edge case ve hata senaryoları için testler yazın (ör. boş veri, hatalı parametre, bağlantı hatası).
- Transaction, rollback, cache, güvenlik ve migration gibi modüller için entegrasyon testleri ekleyin.
- Testlerde assert fonksiyonlarını kullanarak beklenen sonuçları doğrulayın.
- Her yeni fonksiyon veya modül için birim test eklemeyi unutmayın.
- Test veritabanı ile gerçek veritabanını ayırın, test ortamında dummy veri kullanın.
- Kodunuzu test etmeden production ortamına geçmeyin.

---

### 🔒 Oturum (Session) ve Cookie Güvenliği

Oturum başlatırken ve cookie ayarlarında güvenlik için aşağıdaki fonksiyonu kullanabilirsiniz:

```php
// Oturum başlatmadan önce çağırın
Nsql::secure_session_start();
```

Bu fonksiyon (`SessionManager`);
- Oturum çerezini `HttpOnly` ve `SameSite=Strict` olarak ayarlar; `secure` verilmezse isteğin HTTPS olup olmadığına göre belirlenir (yerel HTTP geliştirmede çerez çalışır).
- Uygulama oturumu zaten başlattıysa oturumu **yok etmez**; mevcut oturumu kullanır.
- Güvenli oturum ilk kez kurulurken session ID yenilenir (session fixation koruması, v2.1.1); oturum verisi korunur. Yetki değişikliğinden (login) sonra `regenerate_id()` çağırmaya devam edin.
- `X-Frame-Options` ve `X-Content-Type-Options` gönderir. HSTS yalnızca HTTPS'te ve `hsts` açıkça verilirse gönderilir; artık önerilmeyen `X-XSS-Protection` gönderilmez.
- Parmak izi varsayılan olarak IP içermez (mobil kullanıcılar atılmaz). İsteğe bağlı: `'fingerprint_ip' => 'prefix'` (IPv4 /24, IPv6 /64) veya `'full'`.
- `validate()` session ID'yi `regenerate_interval` saniyede bir yeniler.

```php
Nsql::secure_session_start([
    'hsts' => true,               // veya 'max-age=31536000; includeSubDomains; preload'
    'fingerprint_ip' => 'prefix',
]);
Nsql::session()->validate();      // her istekte
Nsql::session()->regenerate_id(); // login sonrası
```

CSRF token'ın süresi `CSRF_TOKEN_TTL` (varsayılan 7200 sn, `0` = süresiz) sonunda dolar ve yenilenir; login gibi yetki değişikliklerinden sonra `SessionManager::rotate_csrf_token()` çağırın.

> v1.5.30+: `SecurityManager::secure_session_start()` kullanımdan kaldırıldı (deprecated) ve `nsql::secure_session_start()`'a delege ediyor; tek session API'si `SessionManager`.

---

### 🛡️ XSS ve CSRF Koruması

#### XSS (Cross-Site Scripting) Koruması

Kütüphanede yer alan `nsql::escape_html()` fonksiyonu ile kullanıcıdan gelen verileri HTML'ye basmadan önce güvenle kaçışlayabilirsiniz:

```php
// HTML çıktısı için güvenli şekilde kullanın
echo Nsql::escape_html($kullanici->isim);
```

#### CSRF (Cross-Site Request Forgery) Koruması

Formlarınızda CSRF koruması için aşağıdaki fonksiyonları kullanabilirsiniz:

**Token üretimi ve formda kullanımı:**
```php
<input type="hidden" name="csrf_token" value="<?= \nsql\security\SessionManager::get_csrf_token() ?>">
```

**Token doğrulama:**
```php
if (!Nsql::validate_csrf($_POST['csrf_token'] ?? '')) {
    die('Geçersiz CSRF token');
}
```

Bu sayede formlarınızda CSRF saldırılarına karşı koruma sağlayabilirsiniz.

---

### 🔎 Query Builder: Kolon Kuralları ve Raw İfadeler (v1.5.18+)

`select`, `where`, `order_by`, `group_by`, `having` ve `join` yalnızca şu biçimleri kabul eder; hepsi driver'a göre quote edilir:

- `kolon`, `tablo.kolon`, `tablo.*`, `*` (backtick/çift tırnaklı yazım da olur: `` `tablo`.`kolon` ``)
- Aggregate: `COUNT(*)`, `COUNT(DISTINCT kolon)`, `SUM|AVG|MIN|MAX|GROUP_CONCAT(kolon)`
- Yalnızca `select` içinde: `ifade AS takma_ad` ve tamsayı (`select('1')`)

Diğer her şey (`SLEEP(5)`, `IF(...)`, `kolon -- `, tırnaklı alias içinde SQL …) `InvalidArgumentException` fırlatır. Böylece `order_by($_GET['sort'])` SQL injection'a açık olmaz; yine de kullanıcıdan gelen kolonları bir izin listesiyle sınırlamanız önerilir.

Serbest SQL gerekiyorsa raw metodları kullanın. **Raw içerik doğrulanmaz; kullanıcı girdisi koymayın**, değerleri isimli binding ile verin:

```php
$rows = $db->table('orders')
    ->select('id')
    ->select_raw('DATE(created_at) AS day')
    ->where_raw('total > :min', ['min' => 100])
    ->group_by_raw('DATE(created_at)')
    ->having_raw('COUNT(*) > :n', ['n' => 1])
    ->order_by_raw("FIELD(status, 'new', 'paid')")
    ->get();
```

JOIN closure'ının döndürdüğü ON koşulu da raw kabul edilir.

---

### 🧩 ORM Model (v1.5.16+)

```php
use nsql\database\orm\Model;

class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password'];
    protected array $hidden = ['password'];
}

$user = new User($db, $_POST);   // yalnızca fillable alanlar atanır
$user->save();                   // bool; hidden alanlar da kaydedilir
echo $user->to_json();           // password çıktıda yok

$user->force_fill(['is_admin' => 1]);        // güvenilir veri: fillable kontrolü yok
$user->set_attribute('role', 'editor');
```

- `$fillable` boşsa constructor, `fill()` ve `$model->alan = ...` hiçbir alanı atamaz. Alternatif: `$fillable` boş bırakıp `protected array $guarded = ['id', 'is_admin'];` — guarded dışındaki her alan atanabilir (varsayılan `['*']`).
- Tablo ve kolon adları yalnızca harf, rakam ve `_` içerebilir; aksi halde `InvalidArgumentException`. Aynı doğrulama `batch_insert()` / `batch_update()` için de geçerli (`$db->quote_identifier()`).
- `hidden` yalnızca `to_array()` / `to_json()` çıktısını etkiler.
- `$db` verilmezse `nsql::connection()` (varsayılan isimlendirilmiş bağlantı) kullanılır.

#### İlişkiler, casting ve soft delete (v1.12.0+)

```php
class Author extends Model
{
    protected array $fillable = ['name', 'settings', 'is_active', 'born_at'];
    protected array $casts = [
        'settings' => 'array',      // json metni ↔ PHP dizisi
        'is_active' => 'bool',
        'born_at' => 'datetime',    // DateTimeImmutable; to_array()'de 'Y-m-d H:i:s'
    ];

    public function posts(): array { return $this->has_many(Post::class, scope: fn ($q) => $q->order_by('id')); }
    public function profile(): ?Profile { return $this->has_one(Profile::class); }
}

class Post extends Model
{
    protected bool $soft_deletes = true;   // delete() → deleted_at; restore(), force_delete(), trashed()

    public function author(): ?Author { return $this->belongs_to(Author::class); }
}

$author = Author::find_or_fail(1, $db);
foreach ($author->posts as $post) {        // ilk erişimde yüklenir, sonra önbellekten
    echo $post->title, ' — ', $post->author->name;
}
$author->load('posts');                    // yeniden yükle
$active = Author::get(fn ($q) => $q->where('is_active', '=', true)->order_by('name'));
$posts = Post::get(null, $db, with: ['author']);   // eager loading (v2.2.0+): 2 sorgu, N+1 yok
echo json_encode($author);                 // cast'li alanlar + yüklenmiş ilişkiler
```

- İlişki metotları: `belongs_to($class, $foreign_key = '<ilişkili>_id', $owner_key = pk)`, `has_one` / `has_many($class, $foreign_key = '<bu_model>_id', $local_key = pk)`. Anahtar adları sınıfın snake_case adından türetilir (`BlogPost` → `blog_post_id`). İlişki metotları model örnekleri döndürür (v1.12.0 öncesi `has_many()` satır nesneleri döndürüyordu; özellik erişimi aynı çalışır).
- Cast tipleri: `int`, `float`/`decimal`, `bool`, `string`, `array`/`json`, `object`, `datetime`, `date`. Ham değer: `get_raw_attribute()`.
- Eager loading: `get(..., with: ['author'])`, `first(..., with: [...])`, `Model::eager_load($models, 'author')`; ilişki başına tek `WHERE ... IN (...)` sorgusu ([ayrıntı](docs/api-reference.md#eager-loading-v220)).
- Statik yardımcılar: `get(?scope)`, `first(?scope)`, `find()`, `find_or_fail()` (`ModelNotFoundException`, `DatabaseException` alt sınıfı), `hydrate($rows)`. `all()` geriye uyumluluk için satır nesneleri döndürmeye devam eder.
- Tablo adı: `$table` verilmezse `Inflector` ile türetilir: `BlogPost` → `blog_posts`, `Category` → `categories`, `Person` → `people` (2.0 varsayılanı). 1.x davranışı (`strtolower(Sınıf) . 's'`): `ORM_TABLE_NAMING=legacy`.

---

### 🔄 Veritabanı Bağlantı Güncelliği

`nsql` sınıfı, her sorgudan önce veritabanı bağlantısının canlı olup olmadığını otomatik olarak kontrol eder. Eğer bağlantı kopmuşsa, otomatik olarak yeniden bağlanır.

Bu özellik sayesinde uzun süreli çalışan uygulamalarda veya bağlantı kopmalarında veri kaybı ve hata riski en aza indirilir.

Manuel olarak bağlantı kontrolü yapmak isterseniz:

```php
$db->ensure_connection(); // Bağlantı kopmuşsa otomatik olarak yeniden bağlanır
$db->reconnect();         // Bağlantıyı açıkça yeniler (v1.5.15+)
```

> **v1.5.15+**: Sorgu sırasında bağlantı koparsa (MySQL 2006/2013) kopan bağlantı atılır, statement cache temizlenir ve sorgu yeni bağlantıda yeniden hazırlanıp çalıştırılır. Transaction içindeyken bağlantı koparsa sessizce yeniden bağlanılmaz; `nsql\database\exceptions\ConnectionException` fırlatılır ve transaction baştan tekrarlanmalıdır.

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

#### Streaming ve keyset chunk (v1.6.0+)

```php
// Gerçek streaming: tek sorgu, OFFSET yok, sabit bellek (MySQL unbuffered)
foreach ($db->get_yield("SELECT * FROM cok_buyuk_tablo", [], unbuffered: true) as $row) {
    // Akış sürerken aynı $db ile başka sorgu çalıştırılamaz
}

// Döngü içinde yazma gerekiyorsa: birincil anahtara göre parça parça (OFFSET yok)
foreach ($db->chunk_by_id("SELECT id, email FROM users WHERE active = :a", ['a' => 1], 'id', 1000) as $rows) {
    foreach ($rows as $user) {
        $db->update("UPDATE users SET notified = 1 WHERE id = :id", ['id' => $user->id]);
    }
}
```

- `YIELD_UNBUFFERED=true` ile `get_yield()` varsayılan olarak streaming çalışır (1.x'te varsayılan `false`, v2.0.0'da `true`). Kapalıyken eski LIMIT/OFFSET davranışı korunur.
- 1M satırlık ölçüm (`benchmarks/yield_streaming.php`, yerel MariaDB): unbuffered `get_yield` 1,05 sn / ~47 KB tepe, `chunk_by_id(5000)` 1,06 sn, LIMIT/OFFSET `get_yield` 95 sn.
- Bellek eşikleri `memory_limit`'e oranlanır: uyarı `MEMORY_WARNING_RATIO` (0.75), kritik `MEMORY_CRITICAL_RATIO` (0.9). Mutlak değer için `MEMORY_LIMIT_WARNING` / `MEMORY_LIMIT_CRITICAL`.

### get_results vs get_yield: Hangi Durumda Hangisi Kullanılmalı?

- **get_results()**: Tüm sorgu sonucunu dizi olarak belleğe yükler. Küçük ve orta ölçekli veri setleri (ör. 10.000 satır veya ~10 MB altı) için hızlı ve kullanışlıdır. Sonuçlar üzerinde toplu işlem yapmak ve debug() ile tablo halinde görmek için idealdir.
- **get_yield()**: Sonuçları generator ile satır satır döndürür, belleği şişirmez. Çok büyük veri setlerinde (10.000+ satır veya 10 MB üzeri) kullanılması önerilir. Özellikle milyonlarca satırlık sorgularda PHP'nin memory_limit sınırına takılmadan güvenle çalışır.

#### Pratik Sınır ve Tavsiye
- 10.000 satıra kadar veya toplamda 10 MB altı veri için `get_results` kullanabilirsiniz.
- 10.000 satırdan fazla veya büyük veri setlerinde (50 MB ve üzeri) `get_yield` kullanmak daha güvenlidir.
- Sınır, sunucunuzun RAM kapasitesine ve PHP memory_limit ayarına göre değişebilir. Kendi ortamınızda test ederek en iyi sonucu bulabilirsiniz.

> **Not:** `get_yield()` ile alınan sonuçlar debug() ile toplu olarak gösterilmez, sadece foreach ile satır satır işlenir. `get_results()` ise debug() ile tablo halinde gösterilir.

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
- Ayrıntılar ve isimlendirme politikası: [CONTRIBUTING.md](CONTRIBUTING.md)
- PSR-12 biçim; 1.x'te sınıf/metot adları `snake_case`, exception'lar `PascalCase` (2.0'da sınıflar `PascalCase`'e geçecek)
- PHPDoc ile dökümantasyon ekleyin
- Unit testler ekleyin
- Performans ve güvenlik göz önünde bulundurun

## 📝 Sürüm Geçmişi

- v2.2.0 (2026-10-06)
  - ORM eager loading: `Model::get(..., with: [...])`, `first(..., with: [...])`, `Model::eager_load()`; ilişki başına tek `IN` sorgusu (#72).
  - CSRF: tek token kaynağı (`csrf_token`), `validate_csrf_token($token, consume: true)` ile tek kullanımlık doğrulama (#70).
  - Bağlantı havuzu %80 doluluk uyarısı (`ConnectionPool::set_logger()`) ve README bağlantı yaşam döngüsü bölümü (#71).
  - Şema doğrulama: `integer` tanımı MySQL `tinyint(1)`'i kabul eder (#73).

- v2.1.1 (2026-10-06)
  - Güvenlik: session fixation koruması (#55), production'da `ENCRYPTION_KEY` zorunlu (#56), Redis/Memcached `clear()` önek kapsamlı (#57), paylaşılan query cache store bağlantı kapsamlı (#58), `QueryOptimizer` SQL yeniden yazmıyor (#59), `HealthCheck` hata ayrıntısı sızdırmıyor (#68), `RateLimiter` şema tablo adı doğrulaması (#69), demo seeder paketten çıkarıldı (#67), `QueryAnalyzer` tanı aracı olarak belgelendi (#66).
  - Düzeltmeler: PostgreSQL insert id `RETURNING` ile (#60), `DatabaseException::get_details()` (#61), `closeCursor()` (#62), statement cache LRU (#63), `chunk_by_id()` satır yorumu (#64), `SELECT ... INTO` primary'ye (#65), PHPStan baseline temizliği (#75).

- v2.1.0 (2026-10-05)
  - Şema doğrulama MVP (#54): `Schema` / `TableDefinition` / `ColumnDefinition` ile tip, nullable, default, uzunluk ve precision tanımı; `SchemaValidator` canlı DB (MySQL/MariaDB `information_schema`, PostgreSQL `information_schema`, SQLite `PRAGMA table_info`) ile farkları raporlar; `vendor/bin/nsql schema:check [--schema] [--strict] [--json]`.

- v2.0.0 (2026-10-05)
  - Major sürüm (bkz. UPGRADE.md): sınıf/interface/trait adları PascalCase (#24, eski snake_case adlar `legacy_autoload` ile 2.x boyunca çalışır); `THROW_ON_ERROR=true`, `YIELD_UNBUFFERED=true`, `ORM_TABLE_NAMING=inflector` varsayılan (#47, #45, #9); `nsql\database\security\*` ve `model_not_found_exception` takma adları kaldırıldı (#25).

- v1.13.3 (2026-10-05)
  - Doküman temizliği (#14): kırık linkler/anchor'lar, LICENSE, çalışmayan README örnekleri, mükerrer bölümler, eski "Planlanan" yol haritaları GitHub issues'a yönlendirildi; `docs/openapi.yaml` yalnızca health/metrics.

- v1.13.2 (2026-10-05)
  - Satır coverage %65.4 ve CI'da %50 eşiği (#40); MySQL/PostgreSQL `parse_dsn` port/dbname düzeltmesi.

- v1.13.1 (2026-10-05)
  - Yazılı isimlendirme politikası (CONTRIBUTING.md); exception'lar PascalCase (lint + test), `ModelNotFoundException` (#24).

- v1.13.0 (2026-10-05)
  - Web güvenlik yardımcıları opsiyonel `nsql\security` namespace'ine taşındı; eski adlar 2.0'a kadar `class_alias` (#25).

- v1.12.0 (2026-10-05)
  - ORM: `has_one` / `has_many` / `belongs_to` lazy load, `$casts`, `$guarded`, soft delete, `inflector` tablo adı çözümü (#9).

- v1.11.1 (2026-10-05)
  - CI'da PostgreSQL ve SQLite job'ları (`tests/Portable`), sürücüden bağımsız migration manager ve rate limiter, veritabanı başına özellik tablosu (#53).

- v1.11.0 (2026-10-05)
  - İsimlendirilmiş çoklu bağlantı (`nsql::connection()`, `ConnectionManager`) ve okuma/yazma ayrımı (`READ_WRITE_SPLIT`, `DB_READ_HOST`, `set_read_replica()`) (#51).

- v1.10.1 (2026-10-05)
  - QUERY_CACHE_DRIVER: redis/memcached paylaşılan query cache, adapter_simple_cache köprüsü (#18)

- v1.10.0 (2026-10-05)
  - PSR-3 set_logger, PSR-16 query cache store, on_query listener, slow query log (#52)

- v1.9.3 (2026-10-05)
  - memcached_adapter erişilebilirlik kontrolü, test fixture düzeltmesi

- v1.9.2 (2026-10-05)
  - CI onarımı: PHP >=8.1, test env sızıntısı, phpcs ruleset, Windows yol düzeltmesi

- v1.9.1 (2026-10-05)
  - nsql god object parçalama: memory_monitor sınıfı ve sorumluluk trait'leri, API değişmedi (#16)

- v1.9.0 (2026-10-05)
  - QB insert/update/delete/upsert, count/exists/pluck/value/paginate, or_where, raw() (#49)

- v1.8.0 (2026-10-05)
  - transaction(callable), savepoint, deadlock retry, inTransaction kontrolü (#50)

- v1.7.0 (2026-10-05)
  - THROW_ON_ERROR hata modeli, update/delete int dönüşü, UPGRADE.md (#47)

- v1.6.0 (2026-10-05)
  - Unbuffered get_yield, chunk_by_id, memory_limit oranlı bellek eşikleri (#45)

- v1.5.33 (2026-10-05)
  - Sorgu başına ping kaldırıldı, debug_backtrace yalnızca debug modunda (#44)

- v1.5.32 (2026-10-05)
  - Query cache O(1) LRU, eşleme sızıntısı düzeltmesi, dosya kilidi kaldırıldı (#46)

- v1.5.31 (2026-10-05)
  - Query builder where_in/where_null, IS NULL, LIMIT'siz offset, boş string ayarı (#48)

- v1.5.30 (2026-10-05)
  - session_manager: aktif oturum korunuyor, otomatik secure, HSTS opt-in, IP'siz fingerprint, süreli CSRF token (#43)

- v1.5.29 (2026-10-05)
  - Tek maskeleme yolu ve SENSITIVE_KEYS, maskeli debug/log, tam eşleşmeli interpolasyon, kimlik bilgisiz bağlantı hataları (#42)

- v1.5.28 (2026-10-05)
  - Monitoring ?token= varsayılan kapalı, NSQL_MONITORING_ALLOW_QUERY_TOKEN opt-in (#41)

- v1.5.27 (2026-10-05)
  - Cache açık entegrasyon suite'i, gerçek sorgulu query builder testleri, config::set() bootstrap düzeltmesi (#40)

- v1.5.26 (2026-10-05)
  - Klasör düzeni: examples/, tek storage kökü, log yolu düzeltmesi, bin/ dist'e dahil (#22)

- v1.5.25 (2026-10-05)
  - Şema validasyonu stub'ı ve doküman vaatleri kaldırıldı (#8)

- v1.5.24 (2026-10-05)
  - PHPStan seviye tutarlılığı ve baseline (#21); bin/nsql çalıştırma biti (#12)

- v1.5.23 (2026-10-04)
  - Şifreleme v2 formatı, arşiv anahtarlarıyla çözme, reencrypt(), arşiv 0600 (#39)

- v1.5.22 (2026-10-04)
  - Rate limiter token bucket ve yarış durumu düzeltmesi, saat enjeksiyonu, install() (#38)

- v1.5.21 (2026-10-04)
  - Güvenilir proxy desteği: TRUSTED_PROXIES, ip_resolver, sahte X-Forwarded-For/Proto koruması (#37)

- v1.5.20 (2026-10-04)
  - Migration manager: tarih önekli yükleme, proje içi yollar, base_migration, vendor/bin/nsql (#36)

- v1.5.19 (2026-10-04)
  - Query builder parametre isimlendirmesi, UNION ve idempotent compile() (#35)

- v1.5.18 (2026-10-04)
  - Query builder identifier güvenliği: katı kolon grameri, driver'a göre quote, *_raw() metodları (#33)

- v1.5.17 (2026-10-04)
  - get_row() LIMIT çakışması: first() / Model::find() / FOR UPDATE düzeltmesi (#34)

- v1.5.16 (2026-10-04)
  - ORM mass assignment koruması, identifier doğrulama/quote, hidden alanların kaydı; batch_* kolon adı doğrulaması (#32)

- v1.5.15 (2026-10-04)
  - Connection pool DSN başına / süreç içi yeniden tasarım; reconnect + statement yeniden hazırlama, transaction içinde ConnectionException (#30, #31)

- v1.5.14 (2026-10-04)
  - Query cache stale data düzeltmesi, cache varsayılan kapalı; konumsal parametre ve batch insert/update düzeltmesi (#29)

- v1.5.13 (2026-07-30)
  - Sync CLI path/env; CI Ubuntu gate; coverage clover + Codecov (#6, #20, #11)

- v1.5.12 (2026-07-30)
  - Pool config tek doğruluk kaynağı; Dockerfile composer fail-fast (#27, #19)

- v1.5.11 (2026-07-30)
  - Test suite `Unit` / `Integration` olarak bölündü; ORM/migration/cache smoke (#10)

- v1.5.10 (2026-07-30)
  - Vendor köküne yazım engeli; Composer prefer-dist / dirty tree kurtarma (#28)

- v1.5.9 (2026-07-29)
  - Transaction metotları yalnızca `TransactionTrait` içinde (#7)

- v1.5.8 (2026-07-29)
  - `.env.example` tek şablon; pool/cache env → config alias mapping (#13)

- v1.5.7 (2026-07-29)
  - Composer paket adı Packagist ile hizalandı: `ngunenc/nsql` (#5)

- v1.5.6 (2026-07-29)
  - Güvenlik: Redis cache JSON payload; object injection engellendi (#26)

- v1.5.5 (2026-07-28)
  - Çift PDO bağlantısı giderildi (`nsql` composition); health/metrics için monitoring token (#3, #4)

- v1.5.4 (2026-07-28)
  - Yapı: `.gitignore` güçlendirildi; `.php-cs-fixer.cache` tracking'den çıkarıldı

- v1.5.3 (2026-07-28)
  - Güvenlik: `encryption.key` git'ten kaldırıldı; `.gitignore` ve anahtar üretim dokümantasyonu

- v1.5.2 (2026-04-10)
  - `.env` / proje kökü: `set_project_root`, `NSQL_PROJECT_ROOT`, doküman güncellemeleri

- v1.5.1 (2026-04-10)
  - PHP 8.4: connection pool ve cache lock için stream tutamaçlarında tip/PHPDoc düzeltmesi

- v1.5.0 (2025-01-27)
  - Thread-safe connection pool (file-based lock)
  - SQL injection koruması iyileştirmeleri (LIMIT/OFFSET parametreleştirme, identifier quoting)
  - Güvenli IP/HTTPS tespiti (get_client_ip, is_https)
  - Exception handling iyileştirmeleri (exception wrapping, get_last_exception)
  - Memory leak düzeltmeleri (generator, connection pool circular buffer)
  - Cache invalidation race condition koruması
  - LFU cache algoritması ve dinamik cache size
  - Per-table TTL ve cache warming stratejileri
  - Type hints ve PHPDoc iyileştirmeleri
  - Magic number'lar config'e taşındı

- v1.4.0
  - Connection Pool optimizasyonları
  - Memory Management iyileştirmeleri
  - Cache performans optimizasyonları
  - Query Analyzer caching
  - Gelişmiş Error Handling

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

## 🛠 Geliştirme Komutları

### Test ve Kalite Kontrolü

```bash
# Test veritabanını kur
composer test:setup

# Tüm testleri çalıştır
composer test

# Test veritabanını temizle
composer test:cleanup

# Tam test döngüsü (kurulum + test + temizlik)
composer test:full
```

### Kod Kalitesi

```bash
# PSR-12 kod standardı kontrolü
composer lint

# PHPStan static analysis
composer stan

# PHP CS Fixer ile kod formatlama
composer fix
```

### Migration ve Seed

```bash
# Migration'ları çalıştır
vendor/bin/nsql migrate

# Seed verilerini yükle
vendor/bin/nsql seed

# Canlı şemayı database/schema.php (SCHEMA_PATH) ile karşılaştır
vendor/bin/nsql schema:check
```

## 📄 Lisans

Bu proje MIT lisansı altında lisanslanmıştır. Detaylı bilgi için [LICENSE](LICENSE) dosyasına bakın.

## 🙏 Teşekkürler

- PDO topluluğu
- Katkıda bulunan tüm geliştiriciler
- Bug report eden kullanıcılar

---

Geliştirici: [Necip Günenç](https://github.com/ngunenc)

## 🎯 Yol Haritası

Planlanan işler sabit tarihli bir liste yerine [GitHub issues](https://github.com/ngunenc/nsql/issues) üzerinden takip edilir. Yayınlanan değişiklikler için [CHANGELOG.md](CHANGELOG.md), sürüm geçişleri için [UPGRADE.md](UPGRADE.md) dosyasına bakın.

---

## 🌐 Uluslararasılaştırma ve Lokalizasyon (i18n & l10n)

nsql kütüphanesi, çoklu dil desteği ve lokalizasyon için aşağıdaki imkanları sunar:

### 1. Veritabanı Charset ve Collation
- Tüm örneklerde ve .env dosyasında `DB_CHARSET=utf8mb4` kullanılır. Bu ayar, Unicode karakter desteği sağlar ve çoklu dil veri saklama için uygundur.
- Tablo oluştururken charset ve collation ayarlarını belirtin:

```sql
CREATE TABLE kullanicilar (
    id INT PRIMARY KEY,
    ad VARCHAR(255),
    email VARCHAR(255)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

### 2. Dil Dosyası Entegrasyonu
- Uygulamanızda hata mesajları, arayüz metinleri ve loglar için dil dosyası kullanabilirsiniz.
- Örnek PHP dil dosyası:

```php
// lang/tr.php
return [
    'user_not_found' => 'Kullanıcı bulunamadı',
    'db_error' => 'Veritabanı hatası oluştu',
    'login_success' => 'Giriş başarılı',
];
```

Kullanım:
```php
$lang = require 'lang/tr.php';
echo $lang['user_not_found'];
```

### 3. Dinamik Dil Seçimi
- Kullanıcıya göre dil dosyası seçimi yapılabilir:

```php
$locale = $_GET['lang'] ?? 'tr';
$lang = require "lang/{$locale}.php";
```

### 4. Tarih, Para ve Sayı Formatları
- PHP `Intl` eklentisi ile tarih, para ve sayı formatlarını yerelleştirebilirsiniz:

```php
$fmt = new NumberFormatter('tr_TR', NumberFormatter::CURRENCY);
echo $fmt->formatCurrency(1234.56, 'TRY'); // 1.234,56 TL
```

### 5. Çoklu Dil İçin Entegrasyon Önerisi
- Tüm hata mesajlarını ve arayüz metinlerini dil dosyalarından çekin.
- Veritabanı charset ayarlarını her ortamda kontrol edin.
- Kullanıcıya dil seçimi imkanı sunun.
