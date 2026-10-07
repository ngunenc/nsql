# 🧪 Benchmark Rehberi

Bu dosya, nsql performansını temel senaryolarda ölçmek ve PDO ile karşılaştırmak için örnekler sağlar. ezSQL gibi diğer kütüphaneler opsiyonel olarak dahil edilebilir.

## Kurulum

```bash
composer install
```

Proje kökünde `.env` tanımlayın (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, …). Benchmark betikleri `benchmarks/bootstrap.php` içinde `config::set_project_root` kullanır.

Veritabanında `users` tablosu ve yeterli örnek kayıtların olduğundan emin olun.

## Sorgu sıcak yolu (kurulum gerektirmez)

`benchmarks/hot_path.php` bellek içi SQLite ile query cache kapalıyken `get_row()` / `get_results()` sorgu başı süresini ve ham PDO referansını ölçer:

```bash
php -d xdebug.mode=off benchmarks/hot_path.php 30000
```

Örnek (PHP 8.4, Windows; v2.2.1 → v2.2.2): `get_row` 12,9 → 9,3 µs, `get_results` (10 satır) 20,0 → 13,7 µs; ham PDO `get_row` ≈ 5,3 µs.

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
