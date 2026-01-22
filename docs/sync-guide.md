# Production-Development Senkronizasyon Kılavuzu

## Genel Bakış

Bu kılavuz, production (diger/nsql) ve development ortamları arasında senkronizasyon yapmak için kullanılır.

## Version Control Strategy

### Git Workflow

1. **Development Branch**: `main` veya `develop` branch'inde geliştirme yapılır
2. **Production Branch**: `production` branch'i production kodunu içerir
3. **Release Tags**: Her release için version tag'i oluşturulur (örn: `v1.4.1`)

### Branch Stratejisi

```
main (development)
  ├── feature/* (yeni özellikler)
  ├── bugfix/* (hata düzeltmeleri)
  └── hotfix/* (acil düzeltmeler)
  
production (production)
  └── tags: v1.4.0, v1.4.1, etc.
```

## Senkronizasyon Senaryoları

### 1. Development → Production

**Kullanım:**
```bash
php scripts/sync_production.php --direction=to-production
```

**Ne zaman kullanılır:**
- Yeni özellikler development'ta test edildikten sonra
- Hata düzeltmeleri production'a aktarılırken
- Release öncesi son kontroller

**Senkronize edilenler:**
- `src/` dizini (tüm kaynak kod)
- `composer.json` ve `composer.lock`
- Dokümantasyon dosyaları (README.md, CHANGELOG.md, INSTALLATION.md)

**Hariç tutulanlar:**
- Test dosyaları (`tests/`)
- Benchmark dosyaları (`benchmarks/`)
- Development dokümantasyonu (`docs/`)
- Git dosyaları (`.git/`)
- Cache dosyaları (`.phpunit.cache/`)

### 2. Production → Development

**Kullanım:**
```bash
php scripts/sync_production.php --direction=to-development
```

**Ne zaman kullanılır:**
- Production'da yapılan acil düzeltmeleri development'a aktarırken
- Production'daki değişiklikleri geri almak için

## Dry Run (Simülasyon)

Değişiklik yapmadan ne yapılacağını görmek için:

```bash
php scripts/sync_production.php --direction=to-production --dry-run
```

## Manuel Senkronizasyon

### Git ile

```bash
# Development'tan Production'a
git checkout production
git merge main
git push origin production
git tag v1.4.1
git push origin v1.4.1

# Production'dan Development'a
git checkout main
git merge production
git push origin main
```

### Dosya Bazlı

1. **Sadece src/ dizini:**
```bash
rsync -av --exclude='.git' src/ /path/to/production/nsql/src/
```

2. **Composer dosyaları:**
```bash
cp composer.json /path/to/production/nsql/
cp composer.lock /path/to/production/nsql/
```

## Version Yönetimi

### Version Numaralandırma

- **Major.Minor.Patch** formatı kullanılır (örn: 1.4.1)
- **Major**: Breaking changes
- **Minor**: Yeni özellikler (geriye uyumlu)
- **Patch**: Hata düzeltmeleri

### CHANGELOG.md Güncelleme

Her release'de CHANGELOG.md güncellenmelidir:

```markdown
## [1.4.1] - 2026-01-22

### Added
- Yeni özellikler

### Changed
- Değişiklikler

### Fixed
- Hata düzeltmeleri
```

## Güvenlik Kontrolleri

Senkronizasyon öncesi:

1. ✅ Tüm testler başarılı mı?
2. ✅ PHPStan hataları var mı?
3. ✅ Composer dependencies güncel mi?
4. ✅ CHANGELOG.md güncellendi mi?
5. ✅ Version numarası doğru mu?

## Sorun Giderme

### "Kaynak dizin bulunamadı" hatası

Script'teki `$production_path` değişkenini doğru path ile güncelleyin:
```php
$production_path = '/gerçek/production/yolu/nsql';
```

### "Dosya kopyalanamadı" hatası

- Dosya izinlerini kontrol edin
- Disk alanını kontrol edin
- Path'lerin doğru olduğundan emin olun

### Çakışan dosyalar

Manuel olarak çözümleyin veya merge tool kullanın:
```bash
git merge-tool
```

## Best Practices

1. **Her zaman dry-run ile başlayın**
2. **Production'a geçmeden önce test edin**
3. **Version tag'leri kullanın**
4. **CHANGELOG.md'yi güncel tutun**
5. **Backup alın** (production'a geçmeden önce)

## Otomatik Senkronizasyon

CI/CD pipeline'ında otomatik senkronizasyon için:

```yaml
# .github/workflows/sync.yml
name: Sync to Production
on:
  release:
    types: [published]
jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Sync to Production
        run: php scripts/sync_production.php --direction=to-production
```
