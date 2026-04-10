# 🧪 Benchmark Rehberi

Bu dosya, nsql performansını temel senaryolarda ölçmek ve PDO ile karşılaştırmak için örnekler sağlar. ezSQL gibi diğer kütüphaneler opsiyonel olarak dahil edilebilir.

## Kurulum

```bash
composer install
```

Proje kökünde `.env` tanımlayın (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, …). Benchmark betikleri `benchmarks/bootstrap.php` içinde `config::set_project_root` kullanır.

Veritabanında `users` tablosu ve yeterli örnek kayıtların olduğundan emin olun.

## Çalıştırma

PowerShell veya bash:

```bash
php benchmarks/select_small_vs_large.php
php benchmarks/iterators_vs_array.php
php benchmarks/cache_hit_miss.php
```

## Senaryolar

- Küçük/Orta sorgular: nsql vs PDO süre karşılaştırması
- Generator vs Array: Bellek ve süre karşılaştırması
- Cache hit/miss: İkinci çağrıda beklenen hızlanma

## ezSQL ile Karşılaştırma (Opsiyonel)

- Projenize ezSQL ekleyin ve `benchmarks/bootstrap.php` içinde ezSQL örneğini oluşturun.
- Karşılaştırma bloklarını ilgili betiklere ekleyerek aynı sorguları ezSQL ile de çalıştırın.

## Sonuçların Sunumu

Betikler, konsolda tablo formatında süre ve/veya bellek ölçümlerini verir. CI için JSON çıktı isterseniz betiklere `--json` bayrağı ekleyip `print_results` yerine `json_encode` ile yazdırabilirsiniz.
