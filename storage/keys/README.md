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

`ENCRYPTION_KEY` tanımlı değilse kütüphane ilk kullanımda `encryption.key` oluşturur:

```php
use nsql\database\security\key_manager;

$key = key_manager::get_key(); // yoksa üretir ve storage'a yazar
```

### Anahtar rotation

Eski anahtarla şifrelenmiş veriler yeni anahtarla açılamaz. Rotate öncesi verileri çözüp yeniden şifreleyin:

```php
use nsql\database\security\key_manager;

$info = key_manager::rotate_key();
// $info['old_key'], $info['new_key'], $info['rotation_date']
```

## Güvenlik notu (v1.5.3)

Daha önce örnek/geliştirme anahtarı yanlışlıkla repoya commit edilmiş olabilir. Bu anahtar **compromised** kabul edilmeli; production'da mutlaka yeni `ENCRYPTION_KEY` kullanın. Git geçmişinden kalıcı silme için `git filter-repo` / BFG ile ayrı bir temizlik gerekir (force-push koordinasyonu).
