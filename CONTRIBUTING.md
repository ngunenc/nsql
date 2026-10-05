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

### 2.x (geçerli)

| Öğe | Kural | Örnek |
|-----|-------|-------|
| Sınıf, interface, trait | `PascalCase` | `QueryBuilder`, `CacheAdapterInterface`, `CacheTrait`, `Nsql` |
| Exception sınıfları | `PascalCase` + `Exception` son eki | `QueryException`, `ModelNotFoundException` |
| Exception metotları | `camelCase` (`Throwable` ile tutarlı) | `getQuery()`, `getContext()` |
| Diğer metot ve özellikler | `snake_case` (PSR-1'den bilinçli sapma) | `get_results()`, `$primary_key` |
| Sabitler | `snake_case` (config) veya `UPPER_CASE` | `Config::query_cache_timeout`, `FOREVER_TTL` |
| Namespace | küçük harf | `nsql\database\orm`, `nsql\security` |
| Dosya adı | sınıf adıyla birebir aynı (PSR-4) | `QueryBuilder.php`, `QueryException.php` |

- Zorunluluk: `composer lint` `src/` altında `PascalCase` sınıf adı ister (`Squiz.Classes.ValidClassName`);
  `tests/Unit/NamingPolicyTest.php` sınıf adlarını ve dosya adı = sınıf adı kuralını denetler.
- 1.x `snake_case` sınıf adları (`nsql\database\query_builder` vb.) 2.x boyunca çalışır: `src/legacy_class_map.php`
  eski → yeni eşlemesini tutar, `src/legacy_autoload.php` eski ad istendiğinde `class_alias` tanımlar. Yeni sınıflar
  bu haritaya eklenmez. Harita ve autoloader 3.0'da kaldırılır.
- Kamuya açık bir sınıfın adı 2.x içinde değişirse eski ad aynı yolla korunur ve UPGRADE.md'de belgelenir.

### 1.x (eski)

Sınıf/interface/trait adları `snake_case`, exception'lar `PascalCase` idi. 1.x'te deprecated olan takma adlar
(`nsql\database\security\*`, `nsql\database\orm\model_not_found_exception`) 2.0'da kaldırıldı.
## Sürüm ve branch

- Sürüm branch'i ve annotated tag aynı adı taşır: `vX.Y.Z` (`release/` öneki yok).
- Her değişiklik `composer.json`, `CHANGELOG.md` ve README sürüm notunu günceller.
- Geriye uyumsuz değişiklikler minor sürümlerde config bayrağı veya takma adla opsiyonel sunulur, varsayılan bir
  sonraki major sürümde değişir (bkz. `UPGRADE.md`).

## Pull request kontrol listesi

- [ ] `composer test`, `composer stan`, `composer lint` yeşil
- [ ] Davranış değişikliği için test (sürücüden bağımsızsa `tests/Portable`)
- [ ] `CHANGELOG.md` ve gerekiyorsa `UPGRADE.md` / `docs/` güncel
