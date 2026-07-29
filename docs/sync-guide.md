# Production-Development Senkronizasyon Kılavuzu

## Genel Bakış

`scripts/sync_production.php`, iki nsql kopyası arasında seçili dosyaları kopyalar.
**Placeholder path yoktur** — kaynak ve hedef CLI veya ortam değişkeni ile verilir.

> Bu script deneysel bir yardımcı araçtır. Üretim dağıtımı için Git tag / Composer tercih edin.

## Gereksinimler

- PHP CLI
- Yazılabilir hedef dizin (dry-run dışında)

## Kullanım

### Doğrudan path

```bash
php scripts/sync_production.php --source=/path/to/dev/nsql --target=/path/to/prod/nsql
php scripts/sync_production.php --source=/path/to/dev/nsql --target=/path/to/prod/nsql --dry-run
php scripts/sync_production.php --help
```

### Direction + production path

```bash
# Bu repo → production kopyası
export NSQL_PRODUCTION_PATH=/path/to/production/nsql
php scripts/sync_production.php --direction=to-production --dry-run
php scripts/sync_production.php --direction=to-production

# Production → bu repo
php scripts/sync_production.php --direction=to-development --dry-run
```

### Ortam değişkenleri

| Değişken | Anlamı |
|----------|--------|
| `NSQL_SYNC_SOURCE` | `--source` eşleniği |
| `NSQL_SYNC_TARGET` | `--target` eşleniği |
| `NSQL_PRODUCTION_PATH` | `--direction` kullanıldığında diğer taraf |

## Senkronize edilenler

- `src/`
- `composer.json`, `composer.lock`
- `README.md`, `CHANGELOG.md`

## Hariç tutulanlar

- `tests/`, `benchmarks/`, `docs/`, `.github/`
- `vendor/`, `coverage/`, `.git/`
- `.env`, `.env.*`, `*.log`

## Dry-run

```bash
php scripts/sync_production.php --source=./ --target=/tmp/nsql-copy --dry-run
```

Dosya yazılmaz; kopyalanacak öğe sayısı listelenir.

## Git ile (önerilen)

```bash
git checkout production
git merge main
git push origin production
git tag v1.5.13
git push origin refs/tags/v1.5.13
```

## Güvenlik kontrolleri

Senkronizasyon öncesi:

1. Testler yeşil mi? (`composer test`)
2. PHPStan / lint temiz mi?
3. `CHANGELOG.md` ve sürüm numarası güncel mi?
4. Önce `--dry-run`

## Sorun giderme

### "Kaynak/hedef gerekli"

`--source` ve `--target` verin veya `NSQL_SYNC_*` / `NSQL_PRODUCTION_PATH` ayarlayın.
Sabit `/path/to/production/nsql` artık kullanılmaz.

### "Kaynak dizin bulunamadı"

Path'in var olduğundan emin olun (`realpath` ile çözülür).

### "Dosya kopyalanamadı"

İzinler ve disk alanını kontrol edin.

## Best practices

1. Her zaman `--dry-run` ile başlayın
2. Production'a geçmeden önce test edin
3. Sürüm tag'leri kullanın
4. `CHANGELOG.md` güncel tutun
5. Hedef üzerinde backup alın
