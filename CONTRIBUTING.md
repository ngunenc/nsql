# Katkı Rehberi

## Geliştirme ortamı

```bash
composer install
composer test           # unit + integration (MySQL/MariaDB) + portable
composer test:cache     # integration + portable, query cache açık
composer test:portable  # DB_DRIVER=mysql|pgsql|sqlite ile sürücüden bağımsız testler
composer stan           # PHPStan (seviye phpstan.neon'da)
composer lint           # PHP_CodeSniffer (phpcs.xml.dist)
```

Entegrasyon testleri `nsql_test_db` veritabanını kullanır (`phpunit.xml` içindeki `DB_*` değerleri).

## İsimlendirme politikası (#24)

Kod PSR-12 biçimindedir; isimlendirmede PSR-1'den bilinçli olarak ayrılan noktalar aşağıdadır.

### 1.x (geçerli)

| Öğe | Kural | Örnek |
|-----|-------|-------|
| Sınıf, interface, trait | `snake_case` | `query_builder`, `cache_adapter_interface`, `cache_trait` |
| Exception sınıfları | `PascalCase` + `Exception` son eki | `QueryException`, `ModelNotFoundException` |
| Exception metotları | `camelCase` (`Throwable` ile tutarlı) | `getQuery()`, `getContext()` |
| Diğer metot ve özellikler | `snake_case` | `get_results()`, `$primary_key` |
| Sabitler | `snake_case` (config) veya `UPPER_CASE` | `config::query_cache_timeout`, `FOREVER_TTL` |
| Namespace | küçük harf | `nsql\database\orm`, `nsql\security` |
| Dosya adı | sınıf adıyla birebir aynı (PSR-4) | `query_builder.php`, `QueryException.php` |

- Yeni sınıflar bulundukları paketin stiline uyar (1.x'te `snake_case`); yeni exception'lar her zaman `PascalCase`.
- Zorunluluk: `composer lint` exception dosyalarında `PascalCase` sınıf adı ister
  (`Squiz.Classes.ValidClassName`, `*xception.php`); `tests/Unit/NamingPolicyTest.php` exception adlarını ve
  dosya adı = sınıf adı kuralını denetler.
- Kamuya açık bir sınıfın adı 1.x içinde değişirse eski ad `class_alias` ile korunur ve `@deprecated` olarak
  işaretlenir (ör. `nsql\database\security\session_manager` → `nsql\security\session_manager`).

### 2.0 (planlanan, ayrı milestone)

- Tüm sınıf, interface ve trait adları `PascalCase`'e taşınır (`query_builder` → `QueryBuilder`, dosya adlarıyla birlikte).
- Eski `snake_case` sınıf adları 2.x boyunca `class_alias` ile çalışır ve 3.0'da kaldırılır.
- Metot ve özellik adları `snake_case` kalır (kamu API'sinin tamamını kırmamak için; PSR-1'den bilinçli sapma).
- 1.x'te deprecated olan takma adlar (`nsql\database\security\*`, `model_not_found_exception`) 2.0'da kaldırılır.

## Sürüm ve branch

- Sürüm branch'i ve annotated tag aynı adı taşır: `vX.Y.Z` (`release/` öneki yok).
- Her değişiklik `composer.json`, `CHANGELOG.md` ve README sürüm notunu günceller.
- Geriye uyumsuz değişiklikler 1.x'te config bayrağı veya takma adla opsiyonel sunulur, varsayılan 2.0'da değişir
  (bkz. `UPGRADE.md`).

## Pull request kontrol listesi

- [ ] `composer test`, `composer stan`, `composer lint` yeşil
- [ ] Davranış değişikliği için test (sürücüden bağımsızsa `tests/Portable`)
- [ ] `CHANGELOG.md` ve gerekiyorsa `UPGRADE.md` / `docs/` güncel
