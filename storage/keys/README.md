# Encryption keys

Bu dizin yerel şifreleme anahtarları içindir. **Anahtar dosyaları asla git'e eklenmez.**

## Anahtar üretme

Öncelik sırası (`key_manager`):

1. `ENCRYPTION_KEY` ortam değişkeni (önerilen production yolu)
2. Config `encryption_key`
3. `storage/keys/encryption.key` (yoksa otomatik üretilir)

### Ortam değişkeni ile (önerilen)

```bash
# 32 byte rastgele anahtar (base64)
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

`.env` içine ekleyin:

```env
ENCRYPTION_KEY=üretilen-base64-değer
```

### Dosya tabanlı (geliştirme)

`ENCRYPTION_KEY` tanımlı değilse kütüphane ilk kullanımda `encryption.key` oluşturur. v2.1.1'den itibaren bu yalnızca production dışındaki ortamlarda (`ENV=development`, `testing` …) yapılır; `ENV` tanımlı değilse ortam `production` sayılır ve anahtar yoksa `RuntimeException` fırlatılır. Bilerek dosya tabanlı anahtar istiyorsanız `ENCRYPTION_KEY_AUTO_GENERATE=true` verin ve dosyayı web kökünün dışında tutun (`ENCRYPTION_KEY_STORAGE` ile mutlak yol). Windows'ta dosya izinleri (`0600`) uygulanmaz.

```php
use nsql\security\KeyManager;

$key = KeyManager::get_key(); // yoksa üretir ve storage'a yazar
```

### Anahtar rotation

**Anahtar dosyadaysa** `rotate_key()` yeni anahtarı yazar, eskisini `archive/` altına (0600) taşır; eski veriler arşivdeki anahtarla çözülmeye devam eder. Eşzamanlı rotasyonlar `.rotate.lock` ile tekilleştirilir.

```php
use nsql\security\KeyManager;

$info = KeyManager::rotate_key();
// $info['old_key'], $info['new_key'], $info['rotation_date']
```

**Anahtar `ENCRYPTION_KEY` ortam değişkeninden veya `.env`'den geliyorsa** (v2.5.0+) `rotate_key()` `LogicException` fırlatır: dosyaya yazılan anahtarı diğer süreçler göremez ve rotasyondan sonra şifrelenen veri çözülemez hale gelirdi. Elle döndürün:

```env
ENCRYPTION_KEY=yeni-base64-anahtar
ENCRYPTION_PREVIOUS_KEYS=eski-base64-anahtar,daha-eski-base64-anahtar
```

Tüm süreçleri yeniden başlatın; eski veriler çözülür, `Encryption::needs_reencrypt()` / `reencrypt()` ile yeni anahtara taşınabilir.

## Güvenlik notu (v1.5.3)

Daha önce örnek/geliştirme anahtarı yanlışlıkla repoya commit edilmiş olabilir. Bu anahtar **compromised** kabul edilmeli; production'da mutlaka yeni `ENCRYPTION_KEY` kullanın. Git geçmişinden kalıcı silme için `git filter-repo` / BFG ile ayrı bir temizlik gerekir (force-push koordinasyonu).
