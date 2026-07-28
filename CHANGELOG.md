# 📝 Değişiklik Günlüğü

Tüm önemli değişiklikler bu dosyada belgelenecektir.

Bu proje [Semantic Versioning](https://semver.org/spec/v2.0.0.html) kullanır.

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
