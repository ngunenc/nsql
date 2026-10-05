# 📝 Değişiklik Günlüğü

Tüm önemli değişiklikler bu dosyada belgelenecektir.

Bu proje [Semantic Versioning](https://semver.org/spec/v2.0.0.html) kullanır.

## [1.5.32] - 2026-10-05

### Düzeltmeler (#46)
- **Bellek sızıntısı**: LRU eviction ve süre dolumu yalnızca cache kaydını siliyor, `table_to_keys` / `tag_to_keys` / `cache_tags` eşlemelerinde key kalıyordu; uzun süren worker'larda bu diziler sınırsız büyüyordu. Artık tüm silmeler tek bir `remove_cache_entry()` üzerinden geçiyor ve eşlemeler cache boyutuyla sınırlı kalıyor.
- **LRU gerçekten O(1)**: Erişim sırası ayrı dizide `array_search` + `array_splice` ile (O(n)) tutuluyordu. Artık `$query_cache` dizisinin ekleme sırası LRU sırası (erişimde unset + yeniden ekleme, eviction `array_key_first`). Statement cache de aynı şekilde düzeltildi; LRU eviction LFU sayaçlarını da temizliyor.
- **Süre dolumu per-table TTL'e uyuyor**: `purge_expired_cache()` artık `set_table_ttl()` değerlerini dikkate alıyor.
- **`warm_cache_for_table()` her çağrıda warm query listesini büyütüyordu**: Artık sorguları doğrudan `preload_query()` ile yüklüyor.

### Kaldırılanlar
- Cache invalidation'daki dosya kilidi (`sys_get_temp_dir()/nsql_cache.lock`) ve `cache_version` sayacı. Query cache process içi bir dizi olduğu için kilit hiçbir şeyi korumuyor, her invalidation'da dosya sistemine gidiyordu.
- Trait içindeki boş `warm_cache()` / `preload_query()` taslakları (gerçek uygulama `nsql` sınıfında).

### Yeni
- `get_cache_stats()`: `tracked_tables`, `tracked_tags` alanları.

### Testler
- `QueryCacheInternalsTest`: 10.000 sorgu sonrası eşlemelerin sınırlı kalması, LRU sırası, tablo/tag invalidation, süre dolumunda eşleme temizliği, per-table TTL, tablosuz sorgunun cache'lenmemesi, lock dosyası oluşmaması.

## [1.5.31] - 2026-10-05

### Düzeltmeler (#48)
- **`where('id', 'IN', [1, 2, 3])` çalışmıyordu**: dizi string'e çevrilmeye çalışılıyordu ("Array to string conversion"). Artık her eleman ayrı placeholder ile bağlanıyor. Boş dizide `IN` hiçbir satırla (`1 = 0`), `NOT IN` tüm satırlarla (`1 = 1`) eşleşiyor.
- **`where('deleted_at', 'IS', null)` geçersiz SQL üretiyordu** (`IS :param`). `IS` / `=` + `null` artık `IS NULL`, `IS NOT` / `!=` / `<>` + `null` artık `IS NOT NULL` üretiyor (`= NULL` hiçbir satır döndürmüyordu). Diğer operatörlerle `null`, `IS` ile null olmayan değer ve skaler beklenen yerde dizi açık `InvalidArgumentException` veriyor. Aynı kurallar `having()` için de geçerli.
- **`offset()` LIMIT olmadan yok sayılıyordu**. Artık LIMIT'siz de çalışıyor (MySQL ve SQLite'ın istediği "sınırsız" LIMIT sürücüye göre ekleniyor).
- **Boş string her zaman exception veriyordu** ve değiştirilemiyordu (`where('name', '=', '')`). Artık varsayılan olarak izin veriliyor; eski katı davranış için `allow_empty_strings(false)` veya `QUERY_BUILDER_ALLOW_EMPTY_STRING=false`.

### Yeni
- `query_builder::where_in()`, `where_not_in()`, `where_null()`, `where_not_null()`, `allow_empty_strings()`.
- `nsql::get_driver_name()` (`mysql` / `pgsql` / `sqlite`).

### Davranış değişikliği
- `where(col, '=', '')` artık exception yerine sorguyu çalıştırıyor.
- `where(col, '=', null)` artık `col IS NULL` olarak çalışıyor (önceden `col = NULL` ile hiç satır dönmüyordu).

### Testler
- `QueryBuilderIntegrationTest`: `limit()->offset()`, LIMIT'siz offset, dizi ile `IN` / `NOT IN`, boş dizi, `where_null` / `IS` / `= null`, geçersiz kombinasyonlar, boş string ayarı. `docs/api-reference.md` güncellendi.

## [1.5.30] - 2026-10-05

### Güvenlik / Düzeltmeler (#43)
- **Aktif oturum siliniyordu**: `session_manager::start()` oturum zaten açıksa `session_destroy()` çağırıyor, uygulamanın oturum verisini (ör. giriş bilgisi) siliyordu. Artık mevcut oturum kullanılıyor.
- **HTTP'de çerez gönderilmiyordu**: `secure` varsayılanı `true` idi ve `|| is_https()` ile birleşiyordu; yerel HTTP geliştirmede oturum çalışmıyordu. Varsayılan artık `null` (isteğin HTTPS olup olmadığına göre); açıkça `true` / `false` verilebilir.
- **Başlıklar**: HSTS HTTP isteklerinde de gönderiliyordu. Artık yalnızca HTTPS'te ve `hsts` seçeneğiyle (opt-in) gönderiliyor. Artık önerilmeyen `X-XSS-Protection` kaldırıldı. Başlıklar `headers_sent()` sonrasında gönderilmeye çalışılmıyor.
- **Fingerprint**: `REMOTE_ADDR` varsayılan alanlardan çıkarıldı; IP değiştiren (mobil) kullanıcılar "session hijacking" ile atılmıyor. İsteğe bağlı `fingerprint_ip`: `'prefix'` (IPv4 /24, IPv6 /64) veya `'full'`.
- **ID yenileme**: `_requests % (regenerate_interval / 2)` tek sayılarda float modulo üretiyordu ve istek sayısını saniye gibi kullanıyordu. Yenileme artık zamana dayalı (`regenerate_interval` saniye).
- **CSRF token** hiç yenilenmiyordu. Artık süreli (`CSRF_TOKEN_TTL`, varsayılan 7200 sn, `0` = süresiz); süresi dolan token reddediliyor ve yenisi üretiliyor. Yeni `session_manager::rotate_csrf_token()`.
- **Tek session API'si**: `security_manager::secure_session_start()` farklı ayarlarla (Lax, ayrı fixation mantığı) ikinci bir API idi. Artık `@deprecated` ve `nsql::secure_session_start()`'a delege ediyor; `security_manager::generate_csrf_token()` / `validate_csrf_token()` `session_manager`'ı kullanıyor.

### Yeni
- `session_manager::is_secure()`, `security_headers()`, `ip_prefix()`, `rotate_csrf_token()`; seçenekler: `hsts`, `fingerprint_ip`, `send_headers`.

### Kırıcı olabilecek değişiklik
- HSTS isteyenler `'hsts' => true` vermeli.
- Parmak izine IP dahil edilmesine güvenen uygulamalar `'fingerprint_ip' => 'full'` ayarlamalı. Fingerprint formatı değiştiği için yükseltme sonrası açık oturumlar bir kez geçersiz sayılabilir.

### Testler
- `tests/Unit/SessionManagerTest.php`: önceden açılmış oturumun verisi korunuyor, zamana dayalı ID yenileme, IP değişiminde oturumun düşmemesi, prefix modu, HTTP'de HSTS ve X-XSS-Protection yok, HTTPS + opt-in HSTS, otomatik `secure`, IPv4/IPv6 prefix, CSRF token süresi ve rotation.

## [1.5.29] - 2026-10-05

### Güvenlik (#42)
- **Debug log'u düz metin parametre yazıyordu**: `debug()` sorguyu parametreler yerleştirilmiş halde ve `json_encode($this->last_params)` ile hem sayfaya hem log dosyasına yazıyordu; şifre ve token'lar açık görünüyordu. Parametreler ve sonuç satırları artık kolon/parametre adına göre maskeleniyor (`********`).
- **Structured logger** (`logging\logger`) ve **audit log** context'leri aynı filtreden geçiyor.
- **Bağlantı hatası mesajları**: `connection_pool` ve `connection_trait` PDO mesajını exception'a ekliyordu (`Access denied for user 'root'@'host'`). Uygulamaya dönen `ConnectionException` mesajı artık yalnızca SQLSTATE ve sürücü kodunu içeriyor (`Veritabanı bağlantısı kurulamadı (SQLSTATE HY000, kod 1045).`); sürücü mesajı yalnızca log'a yazılıyor.

### Düzeltmeler
- **`sensitive_data_filter::filter()` her çağrıda fatal error veriyordu**: var olmayan `filterArray()` / `filterObject()` metodlarını çağırıyordu. Ayrıca nesneleri yerinde değiştiriyordu; artık kopya üzerinde çalışıyor.
- **`interpolate_query` öneki ortak placeholder'ları bozuyordu**: `str_replace(':id')` `:id2`'yi de değiştiriyordu. Artık tam eşleşmeli regex kullanılıyor; `null` değerler `NULL` olarak gösteriliyor ve değerdeki `$` karakterleri bozulmuyor.
- `convert_to_database_exception()` `ConnectionException`'ı yanlış argüman sırasıyla oluşturuyordu (hata kodu DSN alanına gidiyordu). `1045` (erişim reddi) de bağlantı hatası olarak sınıflanıyor.

### Yeni
- `sensitive_data_filter` tek maskeleme kaynağı: `DEFAULT_KEYS`, `configured_keys()`, statik `mask_array()`, `is_sensitive()`, `filter_array()`. Eşleşme büyük/küçük harf duyarsız, alt dize ile ve baştaki `:` yok sayılarak yapılıyor.
- `SENSITIVE_KEYS` config'i (virgülle ayrılmış veya dizi) varsayılan listeye ekleniyor.
- `connection_pool::safe_error_message(Throwable)`.

### Davranış değişikliği
- `security_manager` / `audit_logger` eskiden ayrı ayrı sabit listeler kullanıyordu (`key` gibi çok geniş ifadeler dahil). Artık ortak liste kullanılıyor; `key` yerine `api_key`, `private_key`, `secret_key`, `access_key`, `encryption_key`.

### Testler
- `tests/Unit/SensitiveDataFilterTest.php`: varsayılan anahtarlar, `:placeholder`, iç içe diziler, `SENSITIVE_KEYS`, nesnenin değiştirilmemesi, `security_manager` log'u, kimlik bilgisi içermeyen bağlantı mesajı.
- `tests/Integration/DebugLogMaskingTest.php`: debug log ve HTML'de şifre yok, `:id` / `:id2`, yanlış kullanıcıyla bağlantı hatası mesajı.

## [1.5.28] - 2026-10-05

### Güvenlik (#41)
- **URL'de gizli bilgi**: `endpoint_guard` monitoring token'ını `?token=` parametresinden de kabul ediyordu. URL'deki token web sunucusu erişim loglarına, proxy/CDN loglarına, tarayıcı geçmişine ve `Referer` başlığına sızar. `?token=` artık varsayılan olarak yok sayılıyor (doğru token ile bile `401`).
- Başlık gönderemeyen araçlar için açık opt-in: `NSQL_MONITORING_ALLOW_QUERY_TOKEN=true`.

### Kırıcı olabilecek değişiklik
- Monitoring endpoint'lerini `?token=` ile çağıran izleme araçları `Authorization: Bearer <token>` veya `X-NSQL-Monitoring-Token` başlığına geçmeli ya da opt-in'i açmalı.

### Yeni
- `endpoint_guard::authorize()`: isteği doğrular, yetkisizse `['status' => ..., 'body' => ...]` döner (`protect()` bunu kullanıyor; test edilebilir).
- `endpoint_guard::allows_query_token()`.

### Dokümantasyon
- README, `.env.example` ve `examples/monitoring/*.php` docblock'ları güncellendi.

### Testler
- `EndpointGuardTest`: varsayılanda `?token=` ile 401, opt-in ile kabul, başlıkla erişim, yanlış token / yapılandırılmamış token / kapalı endpoint durum kodları.

## [1.5.27] - 2026-10-05

### Testler (#40, altyapı)
- **Cache açık suite**: `NSQL_TEST_QUERY_CACHE=1` ile tüm entegrasyon testleri query cache açıkken koşuyor. Yeni `composer test:cache` script'i ve CI'da ayrı adım. `QueryCacheSuiteTest` her iki modun gerçekten istenen durumda çalıştığını doğruluyor.
- **Query builder testleri gerçek sorgu çalıştırıyor**: `QueryBuilderIntegrationTest` yalnızca SQL metnini kontrol ediyordu ve tabloda olmayan kolonlar (`category`, `price`) kullanıyordu. Artık kendi fixture tablolarıyla (`qb_products`, `qb_users`, `qb_categories`) join, group by/having, union, subquery (where/select/from/having) sorgularını çalıştırıp sonuçları doğruluyor. MySQL desteklemediği için `FULL JOIN` yalnızca SQL üretimi olarak test ediliyor.

### Düzeltmeler
- **`config::set()` ezilebiliyordu**: `set_project_root()` / `refresh()` sonrasında ilk `get()`'ten önce yapılan `config::set()` çağrısı, ilk `get()` sırasında `.env` yüklenirken siliniyordu (ör. `QUERY_CACHE_ENABLED=true` olan bir `.env` varken `set('query_cache_enabled', false)` etkisizdi). `set()` artık önce bootstrap yapıyor.

### Testler
- `ConfigEnvMappingTest::test_set_before_bootstrap_is_not_overwritten_by_env_file`.

## [1.5.26] - 2026-10-05

### Yapı (#22)
- Kökteki `index.php` demosu `examples/basic.php` olarak taşındı.
- `public/health.php` ve `public/metrics.php` `examples/monitoring/` altına taşındı (örnek oldukları açık; kendi `public/` dizininize kopyalayın).
- Çift storage kaldırıldı: `src/storage/` silindi, tek kök `storage/` (`logs/`, `keys/`).
- `.gitattributes` / `composer.json` archive listeleri güncellendi: `examples/`, `.cursor/`, `phpstan-baseline.neon` dışarıda.

### Düzeltmeler
- **`vendor/bin/nsql` dist kurulumda oluşmuyordu**: `.gitattributes` içinde `/bin export-ignore` vardı; GitHub zip'inden kurulan pakette `bin/nsql` yoktu ve Composer `bin` kaydı boşa düşüyordu. `bin/` artık pakete dahil.
- **`debug()` log'u CWD'ye yazıyordu**: `debug_trait::log_error()` `error_log.txt` dosyasını çalışma dizinine (ör. `public/`) yazıyordu. Artık `LOG_DIR` / `storage/logs` altına, `LOCK_EX` ile yazıyor.
- `security_manager::log_debug_info()` aynı şekilde göreli yolu CWD'ye yazıyordu; artık log dizinine yazıyor.
- Göreli `LOG_DIR` değeri (ör. `.env.example`'daki `storage/logs`) CWD yerine proje köküne göre çözülüyor.

### Yeni
- `config::resolve_log_path(string $file)`: tüm log yolları için tek çözümleyici (`log_path_trait` buna delege ediyor).

### Testler
- `tests/Unit/LogPathTest.php`: göreli/mutlak yol, göreli `LOG_DIR`, `security_manager` log'unun CWD'ye yazmaması.

## [1.5.25] - 2026-10-05

### Temizlik (#8)
- `src/database/schema/` yalnızca "v1.3.0'da eklenecek" diyen bir README içeriyordu; kodda karşılığı yoktu. Klasör kaldırıldı.
- README proje yapısı, `docs/kullanim-klavuzu.md` (`enableSchemaValidation()` / `validateTable()` örneği) ve `docs/teknik-detay.md` içindeki şema validasyonu vaatleri silindi. README'deki planlanan özellikler listesi henüz olmadığını belirtiyor.
- Şema validasyonu MVP'si ayrı bir roadmap issue'su olarak takip ediliyor: [#54](https://github.com/ngunenc/nsql/issues/54).

Kod davranışı değişmedi; public API etkilenmedi.

## [1.5.24] - 2026-10-05

### Düzeltmeler (#21)
- **PHPStan seviye tutarsızlığı**: `phpstan.neon` level 8 tanımlarken `composer stan` `--level=max` geçiyordu; yerel ve CI sonuçları komuta göre değişiyordu. Seviye artık yalnızca `phpstan.neon` içinde; `composer stan` `-c phpstan.neon` ile çalışıyor ve komut satırında seviye vermiyor.
- Mevcut level 8 bulguları `phpstan-baseline.neon` içine alındı; yeni kodda hata çıkarsa CI kırmızı olur. Baseline'ı yenilemek için `composer stan:baseline`.
- `phpstan.neon` içindeki geçersiz `memoryLimitFile` anahtarı kaldırıldı (bellek limiti `--memory-limit=1G` ile veriliyor).

### Düzeltmeler (#12)
- `bin/nsql` git'te çalıştırma bitiyle (`100755`) saklanıyor. `composer.json` `bin` kaydı 1.5.20'de eklenmişti; kurulumda `vendor/bin/nsql` oluşuyor.

## [1.5.23] - 2026-10-04

### Güvenlik (#39)
- **Zayıf efektif anahtar**: `encryption`, base64 anahtar **metnini** doğrudan `openssl_encrypt()` anahtarı olarak veriyordu. OpenSSL ilk 32 karakteri kullandığından efektif entropi ~192 bit'e düşüyordu. Yeni şifrelemeler base64'ten çözülmüş ham 32 byte anahtarı kullanıyor (32 byte'tan uzun anahtarlar HKDF-SHA256 ile 32 byte'a indirgenir).
- **IV**: GCM için 16 byte yerine önerilen 12 byte IV.
- **Yeni format `v2:`**: `"v2:" . base64(key_id[8] | iv[12] | tag[16] | ciphertext)`. `key_id` = SHA-256(ham anahtar)'ın ilk 8 byte'ı; sürüm ve `key_id` AAD olarak doğrulanıyor.
- **Rotation sonrası çözülemeyen veri**: Şifreli veride anahtar kimliği olmadığı için arşiv anahtarları hiç kullanılmıyordu. `encryption` artık mevcut anahtarla birlikte arşiv anahtarlarını da yükler; v2 verisi `key_id` ile doğru anahtarla, v1 verisi sırayla denenerek çözülür.
- **Katı çözme**: `decrypt()` base64'ü katı modda çözüyor ve minimum uzunluğu kontrol ediyor; bozuk girdi, değiştirilmiş veri ve bilinmeyen anahtar için açık hata veriyor.
- **Arşiv dosyaları** `0600` izinle yazılıyor (önceden varsayılan umask). Aynı saniyedeki rotation'lar birbirinin arşivini ezmiyor.
- **Saklama biçimi**: Anahtar dosyası ikinci kez base64 ile sarılıyordu; artık anahtar metni doğrudan yazılıyor. Eski çift base64 dosyalar okunmaya devam ediyor.

### Geriye dönük uyumluluk
- v1 (<= 1.5.22) şifreli veriler çözülmeye devam ediyor. `needs_reencrypt()` / `reencrypt()` ile v2'ye taşınabilir.
- Yeni `v2:` verisi 1.5.22 ve öncesi sürümlerle **çözülemez**; birden fazla sürümün aynı veriyi okuduğu ortamlarda önce tüm uygulamaları güncelleyin.
- Geçersiz anahtar artık `new encryption($key)` sırasında `InvalidArgumentException` veriyor (önceden ilk şifrelemede hata oluşuyordu).

### Yeni
- `encryption::__construct(?string $key = null, array $previous_keys = [])`, `reencrypt()`, `needs_reencrypt()`.
- `key_manager::get_archived_keys()` (en yeniden eskiye).
- Windows'ta anahtar dosyası izin uyarısı verilmiyor (POSIX izinleri raporlanmadığı için her zaman yanlış alarmdı).

### Testler
- `tests/Unit/EncryptionTest.php`: ham 32 byte anahtar + 12 byte IV doğrulaması (openssl ile bağımsız çözme), v1 uyumluluğu ve `reencrypt`, değiştirilmiş veri, başka anahtarın reddi, bozuk girdiler, rotation sonrası eski verinin çözülmesi, arşiv sırası ve `0600`, tek/çift base64 saklama.

## [1.5.22] - 2026-10-04

### Düzeltmeler (#38)
- **Token bucket çalışmıyordu**: Her istekte `window_start = now - window` yazıldığı için bir sonraki istekte "pencere doldu" koşulu doğru çıkıyor ve kova her saniye tamamen yenileniyordu; limit pratikte uygulanmıyordu. Artık token'lar geçen süreyle orantılı doluyor (saniyede `max_requests / window`), kapasiteyi aşmıyor.
- **Yarış durumu**: Okuma ve güncelleme ayrı sorgulardı; eşzamanlı istekler aynı token'ı harcayabiliyordu. Satır artık transaction içinde `INSERT ... ON DUPLICATE KEY UPDATE` + `SELECT ... FOR UPDATE` ile kilitlenip güncelleniyor. Kilit zaman aşımı gibi hatalar yutulmuyor, `PDOException` olarak yayılıyor.
- Reddedilen istekler token harcamıyor ve `total_requests`'e sayılmıyor.
- `RATE_LIMIT_*` değerleri sınıf sabitlerinden değil `config::get()` (.env) üzerinden okunuyor.
- `security_manager` rate limiter'ı bağlantısız oluşturuyordu; `check_rate_limit()` her zaman hata veriyordu. `new security_manager($db)` artık bağlantıyı iletiyor; `check_rate_limit()` `request_type` parametresini de alıyor.
- Constructor DB'ye dokunmuyor; tablo süreç başına bir kez, ilk kontrolde oluşturuluyor.

### Yeni
- `rate_limiter::__construct(?nsql $db, ?callable $clock = null, array $options = [])`: test için saat enjeksiyonu; `table`, `max_requests`, `window`, `burst` seçenekleri.
- `rate_limiter::install()`, `rate_limiter::schema_sql()`, `rate_limiter::refill_rate()`.

### Davranış değişikliği
- `RATE_LIMIT_DECAY` artık kullanılmıyor; dolum hızı `RATE_LIMIT_MAX_REQUESTS / RATE_LIMIT_WINDOW`. `RATE_LIMIT_BURST` aynı saniyedeki en fazla istek sayısı.
- Yeni kurulumlarda `tokens` kolonu `DOUBLE` (eski `FLOAT` tablolar çalışmaya devam eder).

### Testler
- `tests/Integration/RateLimiterTest.php`: kapasite, kademeli dolum (eski tam sıfırlama hatası), kapasite tavanı, saniyelik burst, reddedilen isteklerin sayılmaması, kimlik/tür izolasyonu, bağlantılar arası paylaşım, satır kilidi (ikinci bağlantı `1205` ile bekler), dış transaction içinde kullanım, `security_manager` entegrasyonu.

## [1.5.21] - 2026-10-04

### Güvenlik (#37)
- **İstemci IP sahteciliği**: `security_manager::get_client_ip()` `X-Forwarded-For` (ilk adres), `X-Real-IP` ve `CF-Connecting-IP` başlıklarına koşulsuz güveniyordu. Herhangi bir istemci başlık göndererek audit log'daki IP'yi, rate limit anahtarını ve oturum parmak izini istediği gibi belirleyebiliyordu. Bu başlıklar artık yalnızca `REMOTE_ADDR` `TRUSTED_PROXIES` listesindeyse dikkate alınıyor.
- **X-Forwarded-For zinciri** sağdan sola okunuyor; güvenilir proxy olmayan ilk adres istemcidir (zincirin başına eklenen sahte adresler yok sayılır). Geçersiz bir girdide yürüme durur.
- **HTTPS tespiti**: `is_https()` `X-Forwarded-Proto` / `X-Forwarded-Ssl` başlıklarına koşulsuz güveniyordu (düz HTTP'de `secure` cookie kararını istemci belirleyebiliyordu). Artık yalnızca güvenilir proxy'den gelirse kullanılıyor.
- `session_manager` parmak izindeki `REMOTE_ADDR` alanı çözülmüş istemci IP'sini kullanıyor (proxy arkasında tüm kullanıcılar aynı proxy IP'sini paylaşmıyor).

### Yeni
- `TRUSTED_PROXIES` config'i: IP veya CIDR (IPv4/IPv6), virgülle ayrılmış veya dizi. `*` yalnızca isteği gönderen eşe güvenir. Varsayılan boş (hiçbir proxy'ye güvenilmez).
- `nsql\database\security\ip_resolver`: `client_ip()`, `is_https()`, `is_trusted_proxy()`, `ip_in_range()`, `is_valid_ip()`. `[IPv6]:port` ve `IPv4:port` biçimleri destekleniyor.

### Kırıcı olabilecek değişiklik
- Proxy / load balancer / CDN arkasında çalışan uygulamalar `TRUSTED_PROXIES` ayarlamazsa `get_client_ip()` proxy adresini döndürür.

### Testler
- `tests/Unit/IpResolverTest.php`: sahte başlıkların reddi, zincir yürüme, IPv4/IPv6 CIDR, port biçimleri, `*`, `is_https()` ve `security_manager` entegrasyonu.

## [1.5.20] - 2026-10-04

### Düzeltmeler (#36)
- **Migration yükleme**: `load_migrations()` sınıf adını dosya adının tamamından (`nsql\database\migrations\2025_..._create_x`) arıyordu; tarih önekli hiçbir migration bulunamıyor, `migrate()` sessizce boş dönüyordu. Artık dosya ya bir migration nesnesi döndürür (`return new class extends base_migration {...};`) ya da tarih öneki çıkarılmış adla, dosyadaki namespace altında bir sınıf tanımlar.
- **Yollar**: Migration/seed dizini paketin içi (`vendor/ngunenc/nsql/src/database/migrations`) idi. Varsayılan artık `<proje kökü>/database/migrations` ve `database/seeds`; `MIGRATIONS_PATH` / `SEEDS_PATH` config'i veya constructor parametreleriyle değiştirilebilir. Göreli yollar proje köküne göre çözülür.
- **Constructor yan etkisi**: `new migration_manager($db)` her seferinde `CREATE TABLE` ve 4 `ALTER` çalıştırıyordu. Tablo artık ilk ihtiyaçta oluşturuluyor; eski şemaya yalnızca `SHOW COLUMNS` ile eksik olduğu görülen kolonlar ekleniyor.
- `migrate()` başarısız migration'ı `failed` olarak loglamıyor, süreyi kaydetmiyor ve `set_dry_run()` ayarını yok sayıyordu.
- Yükleme sırasında dizin yoksa oluşturuluyordu; artık oluşturulmuyor (yalnızca `create()` / `create_seeder()` oluşturur).
- `create()` / `create_seeder()` / `seed()` adlarında yalnızca harf, rakam ve `_` kabul ediliyor (dizin dışına yazma engellendi).

### Yeni
- `nsql\database\base_migration`: bağlantı `set_connection()` ile enjekte edilir, `$this->db()` ile kullanılır. `migration_manager` `set_connection()` metodu olan her migration'a kendi bağlantısını verir; migration içinde `new nsql()` gerekmez.
- `migration_manager::set_migrations_table()`, `get_migrations_path()`, `get_seeds_path()`.
- CLI: `composer.json` `"bin": ["bin/nsql"]` → paket kurulunca `vendor/bin/nsql` kullanılabilir (paket arşivinde artık `bin/` var). Autoload hem paketin kendi `vendor/`'ünden hem de kurulu olduğu projenin `vendor/`'ünden bulunur. `--path=` ve `--seeds-path=` seçenekleri.
- `migrate:create` ve `seed:create` şablonları anonim sınıf döndürür (sınıf adı çakışması olmaz).

### Kırıcı olabilecek değişiklik
- Paketle gelen `nsql\database\migrations\create_users_table` ve `create_test_table` test fixture'larıdır; `tests/Fixtures/Migrations` altına taşındı ve autoload classmap'ten çıkarıldı.
- `migration_manager` constructor'ında artık DB'ye dokunulmuyor; varsayılan migration dizini değişti (yukarıya bakın).

### Testler
- `tests/Integration/MigrationManagerTest.php`: tembel tablo oluşturma, varsayılan/config yolları, şablondan oluşturup çalıştırma, anonim ve sınıf tabanlı migration, bağımlılık sırası, rollback, `failed` logu, dry-run, eski tablo şemasının yükseltilmesi, seed, geçersiz ad.
- `ConnectionPoolIntegrationTest`: bağlantı bırakma testi Xdebug `develop` modunda yıkıcının geç çalışmasından etkilenmeyecek şekilde düzeltildi.

## [1.5.19] - 2026-10-04

### Düzeltmeler (#35)
- **Subquery parametreleri**: Subquery parametreleri `subquery_0_:id_0` gibi geçersiz adlarla bağlanıyor, `str_replace` `:id_1` değiştirirken `:id_10`'u bozuyordu. `from`, `select`, `where`, `where_in_subquery`, `where_exists`, `having` ve `join` subquery'leri gerçek veritabanında hata veriyordu.
- **UNION**: Union builder parametreleri yeni adla ekleniyor ama SQL'deki placeholder'lar değiştirilmiyordu (`HY093`).
- **Idempotency**: `build_query()` her çağrıda LIMIT/OFFSET/UNION parametrelerini tekrar ekliyordu; `get_query()` ardından `get()` fazla parametre hatası veriyordu. `first()` builder'ın kendi LIMIT'ini kalıcı olarak değiştiriyordu.
- `null` değerli yapılandırılmış parametreler (`['value' => null, 'type' => PDO::PARAM_NULL]`) geçersiz parametre sayılıyordu.

### Değişiklik
- Placeholder'lar artık yalnızca derleme sırasında tek bir sayaçla üretiliyor (`:__p0`, `:__p1` …). Subquery, UNION ve raw binding adları da aynı sayaçla yeniden adlandırılıyor; aynı raw binding adı ana sorguda ve subquery'de çakışmıyor.
- Yeni `query_builder::compile(): [sql, params]` (yan etkisiz). `get_query()` ve `get_params()` art arda çağrılabilir ve aynı sonucu döndürür.
- Yeni `query_builder::offset(int)`.

### Testler
- `tests/Integration/QueryBuilderExecutionTest.php`: subquery (IN, NOT IN, EXISTS, FROM, SELECT, JOIN, HAVING), UNION / UNION ALL, 20+ parametre, `get_query()` + `get()` tekrarları, `first()`, `offset()`, raw binding çakışması, `null` binding

## [1.5.18] - 2026-10-04

### Güvenlik (#33)
- **Query builder kolon doğrulaması**: Parantezli her ifade kabul ediliyordu (`order_by('SLEEP(5)')`, `select('BENCHMARK(...) AS x')`); kullanıcıdan gelen sıralama kolonuyla blind SQL injection mümkündü. Artık yalnızca şu biçimler kabul ediliyor:
  - `kolon`, `tablo.kolon`, `tablo.*`, `*`
  - `COUNT(*)`, `COUNT(DISTINCT kolon)`, `SUM|AVG|MIN|MAX|GROUP_CONCAT(kolon)`
  - yalnızca `select` içinde `ifade AS takma_ad` ve tamsayı literal
- **Quote**: Tüm tablo, kolon, alias ve subquery alias'ları `nsql::quote_identifier()` ile doğrulanıp driver'a göre quote ediliyor. Tırnaklı alias içeriği (`x AS "a, (SELECT 1)"`) ve backtick içeren adlar reddediliyor.
- `where()` subquery dalı, `where_in_subquery()` ve `having()` subquery dalında kolon quote edilmiyordu; düzeltildi.
- `from()` / `join()` subquery alias'ı doğrulanmıyordu; düzeltildi.
- `order_by()` yönü doğrulanmadan önce değil, ekleme anında normalize ediliyor (`'DESC, SLEEP(1)'` reddedilir).
- Builder parametre adları yalnızca harf/rakam/`_` içeriyor (aggregate veya tırnaklı kolonlarda geçersiz placeholder oluşmuyordu).

### Düzeltmeler
- `join()` ikinci argümanı PHP fonksiyon adıyla aynı olan bir kolon (`max`, `date` …) ise closure gibi çağrılıyordu.

### Yeni
- `select_raw()`, `where_raw()`, `order_by_raw()`, `group_by_raw()`, `having_raw()` — doğrulanmayan serbest SQL; değerler isimli binding ile (`['min' => 100]`).
- `!=` ve `NOT LIKE` operatörleri.

### Davranış değişikliği
- Aggregate argümanları quote ediliyor: `AVG(price)` → ``AVG(`price`)``.
- `select('UPPER(name)')`, `order_by('RAND()')` gibi serbest ifadeler artık exception fırlatıyor; `select_raw()` / `order_by_raw()` kullanın.
- `nsql::quote_identifier()` rakamla başlayan adları kabul ediyor (yalnızca rakamdan oluşanları reddediyor).

### Testler
- `tests/Integration/QueryBuilderIdentifierSecurityTest.php`

## [1.5.17] - 2026-10-04

### Düzeltmeler (#34)
- **`query_builder::first()` ve `model::find()` çalışmıyordu**: Builder `LIMIT :limit_N` üretirken `get_row()` yalnızca sayısal LIMIT tanıdığı için ikinci bir `LIMIT 1` ekliyor ve sözdizimi hatası oluşuyordu.
- `get_row()` artık `LIMIT 1`'i yalnızca SELECT/WITH sorgularında ve sorgu hiç `LIMIT` (sayısal, `?`, `:param`), `FOR UPDATE`, `FOR SHARE` veya `LOCK IN SHARE MODE` içermiyorsa ekliyor. Sondaki `;` temizleniyor.
- `get_row()` ilk satırı okuduktan sonra cursor'ı kapatıyor.

### Testler
- `tests/Integration/GetRowLimitTest.php`: `first()`, `Model::find()`, `FOR UPDATE`, `LOCK IN SHARE MODE`, sondaki `;`, `LIMIT ?` / `LIMIT :p` / `LIMIT 1 OFFSET 1`

## [1.5.16] - 2026-10-04

### Güvenlik (#32)
- **Mass assignment**: Constructor'a verilen alanlar artık `fill()` üzerinden geçiyor ve yalnızca `$fillable` içindeki alanlar atanıyor. `$fillable` boşsa constructor, `fill()` ve `$model->alan = ...` hiçbir alanı atamıyor (önceden her anahtar kabul ediliyordu).
- **SQL injection (ORM)**: `save()` ve `delete()` tablo, kolon ve primary key adlarını `nsql::quote_identifier()` ile doğrulayıp quote ediyor. Harf/rakam/`_` dışında karakter içeren adlar `InvalidArgumentException` fırlatıyor.
- **SQL injection (batch)**: `batch_insert()` ve `batch_update()` kolon/tablo adlarını kaçışsız backtick içine koyuyordu; aynı doğrulama artık burada da uygulanıyor.

### Düzeltmeler
- `hidden` alanlar (ör. `password`) `save()` sırasında atlanıyordu. Artık kaydediliyor; `hidden` yalnızca `to_array()` / `to_json()` çıktısını etkiliyor.
- `save()` her zaman `bool` döndürüyor (insert'te `int|false` dönüyordu). Insert sonrası primary key ve timestamp alanları modele yazılıyor.
- `to_json()` dönüş tipi `string`.

### Yeni
- `model::fill()`, `model::force_fill()`, `model::set_attribute()`, `model::is_fillable()`, `model::__isset()`.
- `nsql::quote_identifier()` artık public ve doğrulama yapıyor (`tablo` veya `şema.tablo`).

### Davranış değişikliği
- `$fillable` tanımlamayan modellerde `new Model($db, [...])` ve `$model->alan = ...` artık alan atamıyor. Güvenilir veriler için `force_fill()` / `set_attribute()` kullanın veya `$fillable` tanımlayın.

### Testler
- `tests/Integration/OrmModelSecurityTest.php`

### Bilinen sorun
- `model::find()` / `query_builder::first()` hâlâ `LIMIT` çakışması nedeniyle çalışmıyor (#34).

## [1.5.15] - 2026-10-04

### Düzeltmeler
- **Connection pool yeniden tasarımı (#30)**:
  - Havuz artık her DSN + kullanıcı için ayrı tutuluyor; farklı veritabanlarına bağlanan `nsql` örnekleri birbirinin bağlantısını almıyor.
  - State yalnızca PHP sürecine ait (PHP-FPM worker'ları arasında paylaşılmaz). Süreçler arası `flock` kilidi ve `sys_get_temp_dir()/nsql_connection_pool.lock` dosyası kaldırıldı.
  - Yeni bağlantılar artık "kullanımda" olarak sayılmıyor (önceden `min_connections` kadar bağlantı açılışta aktif işaretleniyor, dağıtılamıyordu).
  - Havuz kullanımdaki bir bağlantıyı asla kapatmıyor (önceden `connection_timeout` = 5 sn sonra kullanımdaki bağlantılar siliniyordu).
  - Bağlantılar ihtiyaç anında açılıyor; açılışta `min_connections` kadar bağlantı oluşturulmuyor.
  - `release_connection()` her seferinde `SELECT 1` atmıyor; açık kalan transaction'ı geri alıyor. Sağlık kontrolü yalnızca `health_check_interval` süresinden uzun boşta kalan bağlantılarda yapılıyor.
- **Yeniden bağlanma (#31)**:
  - Sınıftaki bozuk `ensure_connection()` (parametresiz `connect()` çağırıyordu) kaldırıldı. Bağlantı yönetiminin tek kaynağı artık `connection_trait`.
  - `nsql` sınıfındaki gölgelenen `$pdo`, `$retry_limit` ve static havuz özellikleri kaldırıldı.
  - Sorgu sırasında bağlantı koparsa (MySQL 2006/2013) kopan bağlantı havuzdan atılıyor, statement cache temizleniyor, sorgu yeni bağlantıda yeniden hazırlanıp çalıştırılıyor. Önceden eski bağlantının statement'ı tekrar deneniyordu.
  - Transaction içindeyken bağlantı koparsa sessizce yeniden bağlanılmıyor: `ConnectionException` (`error_codes::CONNECTION_LOST`) fırlatılıyor ve transaction seviyesi sıfırlanıyor.
  - `begin()` transaction başlatmadan önce bağlantıyı doğruluyor.

### Yeni
- `nsql::reconnect()`: bağlantıyı açıkça yeniler.
- `nsql::get_instance_pool_stats()`: yalnızca örneğin kendi havuzunun istatistikleri.
- `connection_pool::initialize()` artık havuz anahtarını döndürüyor. `get_connection()` ve `get_stats()` isteğe bağlı havuz anahtarı alıyor. Yeni `connection_pool::discard_connection()` metodu eklendi.

### Davranış değişikliği
- `nsql::get_pool_stats()` tüm havuzların toplamını döndürüyor. `active_connections`, `idle_connections`, `total_connections` ve `max_connections` korunuyor; `total_connections` artık şu an açık bağlantı sayısı. Ömür boyu açılan bağlantı sayısı `created_connections` içinde. Dinamik tuning alanları (`current_load_factor`, `adaptive_health_check_interval` …) kaldırıldı.
- Kullanılmayan `error_handling_trait::execute_with_retry()` artık sınıf metodu tarafından gölgelenmiyor; sınıfın iç metodu `run_with_reconnect()` olarak yeniden adlandırıldı.

### Testler
- `tests/Integration/ConnectionPoolIntegrationTest.php`:
  - Örnek başına tek bağlantı ve bırakılan bağlantının yeniden kullanılması
  - Farklı veritabanları için ayrı havuz
  - Kilit dosyası oluşturulmaması
  - `KILL` sonrası yeniden bağlanma
  - Transaction içinde bağlantı kaybında exception

## [1.5.14] - 2026-10-04

### Düzeltmeler
- **Query cache eski veri (#29)**: `get_results()` (ve query builder `get()`) cache'e tablo bilgisi olmadan yazıldığı için `insert` / `update` / `delete` sonrası 30 dakikaya kadar eski sonuç dönüyordu. Artık tüm SELECT cache girdileri tablolarıyla kaydediliyor; tablosu tespit edilemeyen sorgular cache'lenmiyor.
- **Transaction + cache**: Transaction içinde cache okunmuyor/yazılmıyor; rollback sonrası commit edilmemiş veri dönmüyor.
- **Eksik invalidation**: `batch_insert()`, `batch_update()` ve yazma yapan `query()` (`DELETE`, `TRUNCATE`, DDL …) cache'i temizliyor. Tablo tespit edilemeyen yazmalar tüm cache'i temizler.
- **Tablo adı çıkarımı**: Backtick/çift tırnaklı ve şema önekli adlar, düz `JOIN`, virgüllü `FROM` listesi, subquery, `INSERT IGNORE`, `REPLACE INTO`, `TRUNCATE TABLE` destekleniyor (query builder sorguları artık doğru invalidate ediliyor).
- **Konumsal parametreler**: `?` placeholder'lı sorgular (`[$a, $b]` dizisiyle) `:0` gibi geçersiz isimle bağlandığı için `HY093` veriyordu. `batch_insert()`, `batch_update()` ve ORM `save()` bu yüzden çalışmıyordu.

### Davranış değişikliği
- **`QUERY_CACHE_ENABLED` varsayılanı `false`** (`config::query_cache_enabled`, `.env.example`). Cache'e güvenen uygulamalar `.env` içinde `QUERY_CACHE_ENABLED=true` ayarlamalı.

### Testler
- `tests/Integration/QueryCacheIntegrationTest.php`: cache açıkken yazma, batch, raw query ve rollback senaryoları
- `tests/Unit/QueryCacheTableExtractionTest.php`: tablo adı çıkarımı

## [1.5.13] - 2026-07-30

### Düzeltmeler
- **Sync script (#6)**: Placeholder path kaldırıldı; `--source` / `--target` ve `NSQL_SYNC_*` / `NSQL_PRODUCTION_PATH`. `--help` + dry-run. `docs/sync-guide.md` güncellendi; var olmayan `INSTALLATION.md` referansları temizlendi.
- **CI matrix (#20)**: Ana gate yalnızca Ubuntu + MySQL (PHP 8.0–8.4). Windows job MySQL’siz unit smoke ve `continue-on-error`.
- **Coverage / Codecov (#11)**: `phpunit.xml` `<source>` include; `composer test:coverage` clover üretir; CI Codecov yükler. Dokümandaki “%70+” iddiası gerçek ölçümle (~%30 satır) düzeltildi.

## [1.5.12] - 2026-07-30

### Düzeltmeler
- **Pool config tutarsızlığı (#27)**: `config::default_values()` tek doğruluk kaynağı; class constant / `apply_defaults` / `.env.example` / call-site fallback hizalandı. Varsayılan tablo `docs/api-reference.md` içinde.
- **Dockerfile (#19)**: `composer install ... || true` kaldırıldı — bağımlılık hatasında build fail olur.

## [1.5.11] - 2026-07-30

### Geliştirmeler
- **Test suite bölündü (#10)**: `tests/Unit/` ve `tests/Integration/` yapısı; monolit `nsql_test.php` kaldırıldı. ORM / migration / cache adapter smoke testleri eklendi. PHPUnit suite'leri: `unit`, `integration`.

## [1.5.10] - 2026-07-30

### Düzeltmeler
- **Composer vendor dirty tree (#28)**: Proje kökü tespiti Composer paket dizinini (`vendor/ngunenc/nsql`) atlar; key/log yolları uygulama köküne yazılır. README’de `--prefer-dist` ve `has uncommitted changes` kurtarma adımları.

## [1.5.9] - 2026-07-29

### Düzeltmeler
- **Transaction çift tanım (#7)**: `begin` / `commit` / `rollback` yalnızca `transaction_trait` içinde; `nsql.php` kopyaları kaldırıldı. Null PDO kontrolü ve `*_transaction` alias'ları trait'e taşındı.

## [1.5.8] - 2026-07-29

### Düzeltmeler
- **Env şablonları (#13)**: Resmi şablon tekilleştirildi (`.env.example`). `env.example` deprecated yönlendirme dosyası.
- **Config mapping**: `DB_MIN_CONNECTIONS` ↔ `MIN_CONNECTIONS` (ve diğer pool `DB_*` alias'ları) `config::get` / `has` ile okunuyor. Defaults UPPER_SNAKE ve class constant'larla hizalandı.

## [1.5.7] - 2026-07-29

### Düzeltmeler
- **Paket adı (#5)**: Resmi Composer / Packagist adı `ngunenc/nsql` olarak hizalandı (`composer.json` önceki `nsql/nsql` tutarsızlığı giderildi). Packagist'te paket zaten `ngunenc/nsql` olarak yayında; yerinde `nsql/nsql` rename desteklenmediği için şimdilik bu ad kullanılacak.

## [1.5.6] - 2026-07-29

### Güvenlik
- **Redis cache (#26)**: `serialize`/`unserialize` kaldırıldı. Yeni payload formatı `nsql:j1:{json}` (`safe_serializer`). Eski PHP serialize kayıtları yalnızca `unserialize(..., ['allowed_classes' => false])` ile okunur; object içeren payload'lar reddedilir (cache miss). Üretimde Redis cache flush önerilir.

## [1.5.5] - 2026-07-28

### Düzeltmeler
- **Çift PDO bağlantısı (#3)**: `nsql` artık `PDO`'yu extend etmez; `parent::__construct` kaldırıldı. Fiziksel bağlantı yalnızca `connection_pool` üzerinden alınır. Ham PDO için `get_pdo()` kullanın. **BC**: `nsql instanceof PDO` artık `false`.
- **Monitoring güvenlik (#4)**: `public/health.php` ve `public/metrics.php` için `NSQL_MONITORING_TOKEN` zorunlu; Bearer / `X-NSQL-Monitoring-Token` / `?token=` desteklenir. Exception mesajları istemciye sızdırılmaz (`endpoint_guard`).

### Yapı
- `nsql\database\monitoring\endpoint_guard` eklendi
- `.env.example` ve Docker nginx notları güncellendi

## [1.5.4] - 2026-07-28

### Yapı
- **`.gitignore`**: Key, log, coverage ve araç cache kuralları güçlendirildi (`*.key`, `storage/keys/*`, `storage/logs/*`, `*.log`, `coverage/`, `.phpunit.cache/`, `.php-cs-fixer.cache`, ek PHPUnit/PHPStan cache kalıpları) (#23).
- **Tracking**: Yanlışlıkla takip edilen `.php-cs-fixer.cache` repodan çıkarıldı (`git rm --cached`).

## [1.5.3] - 2026-07-28

### Güvenlik
- **Encryption key**: `storage/keys/encryption.key` artık git'te takip edilmiyor; `.gitignore` `*.key` ve `storage/keys/*` engelliyor.
- **Rotate**: Daha önce repoya commit edilmiş anahtar **compromised** kabul edilmelidir. Production'da yeni `ENCRYPTION_KEY` kullanın; eski anahtarla şifrelenmiş verileri rotate edin.
- **Dokümantasyon**: `storage/keys/README.md`, `.env.example` ve README'de anahtar üretim / rotation adımları eklendi.
- **Geçmiş**: `encryption.key` `git filter-repo` ile tüm branch/tag geçmişinden kalıcı silindi (#2).

## [1.5.2] - 2026-04-10

### İyileştirmeler
- **Yapılandırma**: Veritabanı bilgileri için `.env` tespiti güçlendirildi (`NSQL_PROJECT_ROOT`, `getcwd()`, paket yolundan yukarı arama). `config::set_project_root(?string)` eklendi.
- **nsql**: Varsayılan veritabanı adı `config` ile hizalandı; örnek ve testlerde `set_project_root` kullanımı.
- **Dokümantasyon**: `docs/kullanim-klavuzu.md`, `api-reference.md`, `teknik-detay.md`, `examples.md`, `production-scenarios.md`, `benchmarks.md` ve `.env.example` güncellendi.

## [1.5.1] - 2026-04-10

### Düzeltmeler
- **PHP 8.4**: `connection_pool` ve `cache_trait` içinde dosya kilit stream tutamaçları için özellik/metot imzalarından `resource` native tipi kaldırıldı; `@var resource|null`, `@param resource|null`, `@return resource|null` PHPDoc ile belgelendi. `fopen()` dönüşü özelliklere güvenli şekilde atanabiliyor.

## [1.4.3] - 2026-01-22

### 📊 Proje Analiz ve Raporlama
- **Kapsamlı Analiz Raporu**: PROJE_GUNCEL_ANALIZ_RAPORU.md oluşturuldu
- **Rapor Güncellemeleri**: PROJE_ANALIZ_RAPORU.md ve PROJE_TEST_RAPORU.md güncellendi
- **Geliştirme Planı**: GELISTIRME_PLANI.md tamamlandı (52/52 görev)

### 🔧 İyileştirmeler
- **PHPStan Memory**: Memory limit 512M → 1G, parallel processing optimize edildi
- **Test Coverage**: Test sayısı 53'e çıkarıldı (%70+ coverage)
- **Dokümantasyon**: Tüm raporlar güncel duruma getirildi

### 📝 Dokümantasyon
- **Yeni Raporlar**: PROJE_GUNCEL_ANALIZ_RAPORU.md eklendi
- **Güncellenen Raporlar**: PROJE_ANALIZ_RAPORU.md ve PROJE_TEST_RAPORU.md güncellendi
- **Versiyon Bilgileri**: Tüm raporlarda versiyon bilgileri güncellendi

## [1.4.1] - 2026-01-22

### 🐛 Kritik Hata Düzeltmeleri
- **Versiyon Tutarsızlığı**: composer.json'da versiyon 1.4.0 → 1.4.1 güncellendi
- **get_chunk() Parametre Uyumsuzluğu**: `get_chunk()` metoduna opsiyonel `$chunk_size` parametresi eklendi
- **Test Coverage**: Test sayısı 9'dan 33'e çıkarıldı (%70+ coverage hedeflendi)
- **PHPStan Hataları**: Type hints, null pointer kontrolleri ve error handling iyileştirildi
- **PSR-12 Uyumluluğu**: Kod formatı PSR-12 standardına uygun hale getirildi
- **Encryption Key Management**: Güvenli key management sistemi eklendi (key_manager.php)

### 🔧 İç Yapı İyileştirmeleri
- **Type Safety**: `handle_exception()` ve `safe_execute()` metodlarına type hints eklendi
- **Null Safety**: PDO null kontrolleri tüm kritik metodlara eklendi
- **Error Handling**: Transaction metodlarında RuntimeException throw ediliyor
- **Key Management**: Key rotation, archiving ve secure storage özellikleri eklendi

### ✨ Yeni Özellikler
- **Key Manager**: Güvenli encryption key yönetimi için `key_manager` sınıfı eklendi
- **Key Rotation**: `rotate_key()` metodu ile key rotation desteği
- **Key Validation**: `is_key_valid()` metodu ile key doğrulama
- **Secure Storage**: Key'ler güvenli dosya storage'da saklanıyor (0600 izinler)

### 🧪 Test İyileştirmeleri
- **Integration Tests**: Tam CRUD workflow ve transaction testleri eklendi
- **Edge Cases**: Boş sonuçlar, null değerler, büyük veri setleri testleri
- **Security Tests**: SQL injection, XSS, CSRF koruması detaylı testleri
- **Performance Tests**: Chunk performans testleri eklendi

## [1.4.0] - 2024-12-19

### 🚀 Performans Optimizasyonları
- **Connection Pool**: Health check interval 30s → 60s (%50 performans artışı)
- **Memory Management**: Memory check interval 30s → 60s (%50 performans artışı)
- **Cache Performance**: LRU algoritması O(n) → O(1) (100x daha hızlı)
- **Query Analyzer**: Analiz sonuçları cache'leme (100 analiz sonucu)
- **Chunk Size**: Akıllı chunk size ayarlaması (200-15000 arası)

### 🔧 Yapılandırma İyileştirmeleri
- **Connection Pool**: Max connections 10 → 15, idle timeout 300s → 600s
- **Memory Limits**: Warning 128MB → 192MB, Critical 256MB → 384MB
- **Cache Sizes**: Query cache 100 → 200, Statement cache 100 → 150
- **Cache TTL**: Query cache timeout 3600s → 1800s (daha güncel veri)

### 📊 Yeni İstatistik API'leri
- **get_all_stats()**: Tüm istatistikleri tek API'de
- **get_all_cache_stats()**: Cache performans istatistikleri
- **get_query_analyzer_stats()**: Query analyzer istatistikleri
- **Hit/Miss Tracking**: Cache hit rate hesaplama
- **Memory Stats**: Detaylı bellek kullanım istatistikleri

### 🛡️ Error Handling İyileştirmeleri
- **Enhanced safe_execute()**: Daha iyi exception handling
- **PDO Exception Handling**: Özel PDO hata yönetimi
- **Debug Mode Support**: Debug modunda detaylı hata mesajları
- **Error Logging**: Timestamp'li hata loglama
- **Graceful Degradation**: Production modunda güvenli hata yönetimi

### 🧪 Test Coverage İyileştirmeleri
- **Test Database Setup**: Test veritabanı kurulumu
- **Test Table Creation**: Test tabloları oluşturma
- **Test Environment**: Test ortamı yapılandırması
- **Test Data Management**: Test verisi yönetimi

### 📚 Dokümantasyon Güncellemeleri
- **README.md**: v1.4 özellikleri ve yeni API'ler
- **API Reference**: Yeni istatistik metodları
- **Examples**: Yeni örnekler ve kullanım senaryoları
- **Technical Details**: Performans optimizasyonları detayları

## [1.2.0] - 2024-12-19

### ✨ Yeni Özellikler
- **Config Sınıfı**: Merkezi yapılandırma yönetimi
- **Test Ortamı**: Kapsamlı test altyapısı
- **Composer Scripts**: Otomatik test ve kalite kontrol komutları
- **API Dokümantasyonu**: Kapsamlı API referansı
- **Örnekler**: Detaylı kullanım örnekleri

### 🔧 İyileştirmeler
- **PHPStan**: 122 hatadan 53 hataya düşürüldü (%57 iyileştirme)
- **PSR-12**: 1000+ hatadan 200+ hataya düşürüldü (%80 iyileştirme)
- **Type Safety**: Tüm metodlara type hints eklendi
- **Error Handling**: Gelişmiş hata yönetimi
- **Performance**: Connection pool ve cache optimizasyonları

### 🐛 Hata Düzeltmeleri
- **Config Constants**: Eksik sabitler eklendi
- **Connection Pool**: Undefined properties sorunları düzeltildi
- **Security Manager**: Mixed type sorunları çözüldü
- **Migration Manager**: Array type ve undefined properties düzeltildi
- **Traits**: Undefined methods ve properties sorunları çözüldü

### 📚 Dokümantasyon
- **README.md**: Güncellenmiş kurulum ve kullanım bilgileri
- **API Reference**: Kapsamlı API dokümantasyonu
- **Examples**: Detaylı kullanım örnekleri
- **Technical Details**: Güncellenmiş teknik detaylar

### 🧪 Test
- **Test Suite**: 9 test metodu eklendi
- **Test Database**: Otomatik test veritabanı kurulumu
- **Test Scripts**: Composer ile test komutları
- **Coverage**: Test coverage raporları

## [1.1.0] - 2024-12-18

### ✨ Yeni Özellikler
- **Security Features**: XSS, CSRF, SQL injection koruması
- **Performance Features**: Connection pool, query cache, statement cache
- **Migration System**: Veritabanı migration yönetimi
- **Debug System**: Gelişmiş debug ve logging

### 🔧 İyileştirmeler
- **Query Builder**: Fluent interface ile sorgu oluşturma
- **Transaction Support**: Nested transaction desteği
- **Error Handling**: Kapsamlı hata yönetimi
- **Code Quality**: PSR-12 standartları

### 🐛 Hata Düzeltmeleri
- **PDO Wrapper**: Temel PDO wrapper sorunları
- **Connection Management**: Bağlantı yönetimi iyileştirmeleri
- **Memory Management**: Bellek kullanımı optimizasyonları

## [1.0.0] - 2024-12-17

### ✨ İlk Sürüm
- **Core Features**: Temel PDO wrapper fonksiyonları
- **Basic Security**: Temel güvenlik özellikleri
- **Simple API**: Basit ve kullanımı kolay API
- **Documentation**: Temel dokümantasyon

### 🔧 Temel Özellikler
- **Database Connection**: MySQL/MariaDB bağlantı desteği
- **CRUD Operations**: Create, Read, Update, Delete işlemleri
- **Prepared Statements**: SQL injection koruması
- **Error Handling**: Temel hata yönetimi

---

## 📋 Gelecek Sürümler

### [1.3.0] - Planlanan
- **Multi-Database Support**: PostgreSQL, SQLite desteği
- **ORM Features**: Object-Relational Mapping
- **Advanced Caching**: Redis, Memcached entegrasyonu
- **API Documentation**: Swagger/OpenAPI dokümantasyonu

### [1.4.0] - Planlanan
- **Microservice Support**: Service discovery ve load balancing
- **Real-time Features**: WebSocket desteği
- **Advanced Security**: OAuth2, JWT token desteği
- **Monitoring**: Metrics ve health check endpoints

### [2.0.0] - Planlanan
- **Breaking Changes**: API değişiklikleri
- **Performance Rewrite**: Tamamen yeniden yazılmış performans optimizasyonu
- **Modern PHP**: PHP 8.2+ özellikleri
- **Cloud Native**: Kubernetes ve Docker desteği

---

## 🔄 Sürüm Politikası

### Major Version (X.0.0)
- Breaking changes
- API değişiklikleri
- Büyük mimari değişiklikler

### Minor Version (X.Y.0)
- Yeni özellikler
- Geriye uyumlu değişiklikler
- Performans iyileştirmeleri

### Patch Version (X.Y.Z)
- Hata düzeltmeleri
- Güvenlik yamaları
- Dokümantasyon güncellemeleri

---

## 📞 Katkıda Bulunma

Bu projeye katkıda bulunmak için:

1. **Fork** yapın
2. **Feature branch** oluşturun (`git checkout -b feature/amazing-feature`)
3. **Commit** yapın (`git commit -m 'Add amazing feature'`)
4. **Push** yapın (`git push origin feature/amazing-feature`)
5. **Pull Request** oluşturun

### Katkı Kuralları
- PSR-12 kod standardına uyun
- PHPStan level 8'de hata vermeyen kod yazın
- Test yazın
- Dokümantasyonu güncelleyin
- CHANGELOG.md'yi güncelleyin

---

## 📄 Lisans

Bu proje MIT lisansı altında lisanslanmıştır. Detaylar için [LICENSE](LICENSE) dosyasına bakın.

---

**Son Güncelleme**: 2024-12-19
**Sonraki Sürüm**: 1.3.0 (Planlanan)
