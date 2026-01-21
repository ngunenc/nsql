# 🔧 Sorun Giderme Rehberi

## "Root package cannot require itself" Hatası

Bu hata genellikle şu durumlardan kaynaklanır:

### 1. Yanlış Dizinde Çalışma
**Sorun**: `composer require ngunenc/nsql` komutunu nsql projesinin kendi dizininde çalıştırıyorsunuz.

**Çözüm**: 
- Başka bir proje dizinine geçin
- O projede `composer require ngunenc/nsql` komutunu çalıştırın

```bash
# Yanlış ❌
cd C:\wamp\www\projeler\nsql
composer require ngunenc/nsql

# Doğru ✅
cd C:\wamp\www\projeler\yeni-proje
composer require ngunenc/nsql
```

### 2. Composer.json'da Zaten Mevcut
**Sorun**: Projenizin `composer.json` dosyasında zaten `ngunenc/nsql` paketi var.

**Çözüm**: 
- `composer.json` dosyasını kontrol edin
- Eğer zaten varsa, sadece `composer update ngunenc/nsql` kullanın

```bash
# Kontrol et
cat composer.json | grep ngunenc/nsql

# Güncelle
composer update ngunenc/nsql
```

### 3. Composer Cache Sorunu
**Sorun**: Composer cache'i eski bilgiler içeriyor.

**Çözüm**: Cache'i temizleyin

```bash
composer clear-cache
composer require ngunenc/nsql
```

### 4. Paket Adı Çakışması
**Sorun**: Projenizin `composer.json` dosyasında `name` alanı `ngunenc/nsql` olarak ayarlanmış.

**Çözüm**: 
- Projenizin `composer.json` dosyasını açın
- `name` alanını kontrol edin
- Eğer `ngunenc/nsql` ise, farklı bir paket adı kullanın

```json
{
    "name": "your-username/your-project",  // ✅ Doğru
    // "name": "ngunenc/nsql"  // ❌ Yanlış (nsql projesi için)
}
```

### 5. Packagist'te Paket Bulunamıyor
**Sorun**: Paket henüz Packagist'te yayınlanmamış veya güncellenmemiş.

**Çözüm**: 
- GitHub repository'yi Packagist'e manuel olarak ekleyin
- Veya webhook kurulumu yapın
- Veya geçici olarak GitHub repository'yi direkt kullanın:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ngunenc/nsql.git"
        }
    ],
    "require": {
        "ngunenc/nsql": "^1.4.1"
    }
}
```

## Doğru Kullanım Örneği

```bash
# 1. Yeni bir proje dizini oluşturun
mkdir my-project
cd my-project

# 2. Composer projesi başlatın (eğer yoksa)
composer init

# 3. nsql paketini ekleyin
composer require ngunenc/nsql

# 4. Kullanın
php -r "require 'vendor/autoload.php'; use nsql\database\nsql; echo 'nsql yüklendi!';"
```

## Hızlı Test

Başka bir dizinde test etmek için:

```bash
# Geçici test dizini
cd C:\wamp\www\projeler
mkdir test-nsql
cd test-nsql

# Composer init
composer init --no-interaction --name="test/project"

# nsql'i ekle
composer require ngunenc/nsql

# Test et
php -r "require 'vendor/autoload.php'; echo 'Başarılı!';"
```
