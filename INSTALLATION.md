# 📦 nsql Kurulum Rehberi

## 🚀 Hızlı Kurulum (Packagist'te yayınlanana kadar)

Paket henüz Packagist'te yayınlanmadığı için, GitHub repository'yi direkt kullanmanız gerekiyor.

### Yöntem 1: Repository ile Kurulum (Önerilen)

Projenizin `composer.json` dosyasına şunu ekleyin:

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

Sonra:
```bash
composer require ngunenc/nsql:^1.4.1
```

### Yöntem 2: Tek Komutla Kurulum

```bash
composer require ngunenc/nsql:^1.4.1 --repository='{"type":"vcs","url":"https://github.com/ngunenc/nsql.git"}'
```

### Yöntem 3: Composer.json'u Manuel Oluşturma

```bash
# composer.json dosyasını oluştur
cat > composer.json << 'EOF'
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
EOF

# Paketi yükle
composer install
```

## 📋 Adım Adım Kurulum

### 1. Yeni Proje Oluşturma

```bash
# Proje dizini oluştur
mkdir my-project
cd my-project

# Composer init (eğer composer.json yoksa)
composer init
```

### 2. Repository Ekleme

`composer.json` dosyasını açın ve `repositories` bölümünü ekleyin:

```json
{
    "name": "your-username/your-project",
    "type": "project",
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ngunenc/nsql.git"
        }
    ],
    "require": {
        "php": ">=8.0",
        "ngunenc/nsql": "^1.4.1"
    }
}
```

### 3. Paketi Yükleme

```bash
composer install
# veya
composer require ngunenc/nsql:^1.4.1
```

### 4. Kullanım

```php
<?php
require 'vendor/autoload.php';

use nsql\database\nsql;

// Veritabanı bağlantısı
$db = new nsql(
    host: 'localhost',
    db: 'veritabani_adi',
    user: 'kullanici',
    pass: 'sifre'
);

// Test
$result = $db->get_results("SELECT 1 as test");
var_dump($result);
```

## 🔧 Packagist'e Ekleme (Kalıcı Çözüm)

Paketi Packagist'te yayınlamak için:

### 1. Packagist'e Giriş
- https://packagist.org adresine gidin
- GitHub hesabınızla giriş yapın

### 2. Paketi Ekle
- "Submit" butonuna tıklayın
- Repository URL'ini girin: `https://github.com/ngunenc/nsql`
- "Check" butonuna tıklayın
- Paket bilgilerini kontrol edin
- "Submit" ile onaylayın

### 3. Webhook Kurulumu (Otomatik Güncelleme)

GitHub repository ayarlarından:

1. **Settings** → **Webhooks** → **Add webhook**
2. **Payload URL**: `https://packagist.org/api/github?username=ngunenc`
3. **Content type**: `application/json`
4. **Events**: `Just the push event`
5. **Active**: ✅ işaretli
6. **Add webhook**

Artık her push'ta Packagist otomatik güncellenecek!

### 4. Packagist'te Yayınlandıktan Sonra

Webhook kurulumundan sonra, normal kurulum çalışacak:

```bash
composer require ngunenc/nsql:^1.4.1
```

Repository eklemeye gerek kalmayacak.

## ⚠️ Sorun Giderme

### "Root package cannot require itself" Hatası

Bu hata, `composer require` komutunu nsql projesinin kendi dizininde çalıştırdığınızda oluşur.

**Çözüm**: Başka bir proje dizininde çalıştırın.

### "Package not found" Hatası

Paket Packagist'te yoksa, repository ekleyin (yukarıdaki Yöntem 1).

### Cache Sorunu

```bash
composer clear-cache
composer require ngunenc/nsql:^1.4.1 --repository='{"type":"vcs","url":"https://github.com/ngunenc/nsql.git"}'
```

## 📚 Daha Fazla Bilgi

- [GitHub Repository](https://github.com/ngunenc/nsql)
- [Dokümantasyon](https://github.com/ngunenc/nsql/blob/main/README.md)
- [CHANGELOG](https://github.com/ngunenc/nsql/blob/main/CHANGELOG.md)
