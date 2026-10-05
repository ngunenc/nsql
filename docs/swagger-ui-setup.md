# Swagger UI Kurulum ve Kullanım

Bu kılavuz, nsql kütüphanesi için Swagger UI'ı nasıl kurup kullanacağınızı gösterir.

> **Kapsam (v1.13.3):** nsql bir PHP kütüphanesidir, HTTP API sunmaz. [`openapi.yaml`](openapi.yaml) yalnızca `examples/monitoring/` altındaki `health.php` ve `metrics.php` örnek endpoint'lerini tanımlar. Aşağıdaki sorgu/CRUD endpoint örnekleri uygulamanızda kendi API'nizi belgelemek içindir.

## 📑 İçindekiler

- [Kurulum](#-kurulum)
- [Yapılandırma](#-yapılandırma)
- [Kullanım](#-kullanım)
- [Özelleştirme](#-özelleştirme)

## 🚀 Kurulum

### 1. Composer ile Kurulum

```bash
composer require swagger-api/swagger-ui
```

### 2. Manuel Kurulum

```bash
# Swagger UI'ı indir
wget https://github.com/swagger-api/swagger-ui/archive/refs/tags/v5.0.0.tar.gz
tar -xzf v5.0.0.tar.gz
mv swagger-ui-5.0.0 public/swagger-ui
```

### 3. Docker ile Kurulum

```dockerfile
FROM nginx:alpine
COPY docs/openapi.yaml /usr/share/nginx/html/
COPY swagger-ui/ /usr/share/nginx/html/swagger-ui/
```

## ⚙️ Yapılandırma

### 1. Swagger UI HTML Dosyası

`public/swagger-ui/index.html` dosyası oluşturun:

```html
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>nsql API Documentation</title>
    <link rel="stylesheet" type="text/css" href="./swagger-ui.css" />
    <link rel="icon" type="image/png" href="./favicon-32x32.png" sizes="32x32" />
    <link rel="icon" type="image/png" href="./favicon-16x16.png" sizes="16x16" />
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="./swagger-ui-bundle.js" charset="UTF-8"></script>
    <script src="./swagger-ui-standalone-preset.js" charset="UTF-8"></script>
    <script>
        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: "/docs/openapi.yaml",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout",
                validatorUrl: null,
                docExpansion: "list",
                filter: true,
                showExtensions: true,
                showCommonExtensions: true
            });
        };
    </script>
</body>
</html>
```

### 2. Nginx Yapılandırması

```nginx
server {
    listen 80;
    server_name api-docs.example.com;
    root /var/www/nsql/public;
    
    location /swagger-ui/ {
        alias /var/www/nsql/public/swagger-ui/;
        try_files $uri $uri/ =404;
    }
    
    location /docs/ {
        alias /var/www/nsql/docs/;
        try_files $uri $uri/ =404;
    }
}
```

### 3. PHP ile Basit Server

```php
<?php
// public/swagger-server.php
$requestUri = $_SERVER['REQUEST_URI'];

if (strpos($requestUri, '/swagger-ui/') === 0) {
    $file = __DIR__ . '/swagger-ui' . substr($requestUri, 12);
    if (file_exists($file)) {
        $mimeType = mime_content_type($file);
        header("Content-Type: {$mimeType}");
        readfile($file);
        exit;
    }
}

if (strpos($requestUri, '/docs/') === 0) {
    $file = __DIR__ . '/../docs' . substr($requestUri, 6);
    if (file_exists($file)) {
        header("Content-Type: application/yaml");
        readfile($file);
        exit;
    }
}

http_response_code(404);
```

## 📖 Kullanım

### 1. Dokümantasyonu Görüntüleme

Tarayıcınızda şu adresi açın:
```
http://localhost/swagger-ui/
```

### 2. API Test Etme

Swagger UI'da "Try it out" butonuna tıklayarak API'yi test edebilirsiniz.

### 3. Kod Örnekleri Oluşturma

Swagger UI, farklı diller için kod örnekleri oluşturabilir:
- PHP
- cURL
- JavaScript
- Python

## 🎨 Özelleştirme

### 1. Tema Değiştirme

```javascript
const ui = SwaggerUIBundle({
    // ...
    theme: "monokai", // veya "default", "dark"
});
```

### 2. Özel CSS

```html
<style>
    .swagger-ui .topbar {
        background-color: #your-color;
    }
</style>
```

### 3. Özel JavaScript

```javascript
const ui = SwaggerUIBundle({
    // ...
    onComplete: function() {
        console.log("Swagger UI loaded");
    }
});
```

## 🔧 Otomatik Dokümantasyon Güncelleme

### 1. CI/CD Pipeline

```yaml
# .github/workflows/docs.yml
name: Update API Docs
on:
  push:
    paths:
      - 'src/**'
      - 'docs/openapi.yaml'

jobs:
  update-docs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Update OpenAPI spec
        run: |
          # OpenAPI spec'i güncelle
          php scripts/generate-openapi.php > docs/openapi.yaml
      - name: Deploy docs
        run: |
          # Dokümantasyonu deploy et
          rsync -av docs/ server:/var/www/docs/
```

### 2. PHP ile Otomatik Güncelleme

```php
<?php
// scripts/generate-openapi.php
require_once __DIR__ . '/../vendor/autoload.php';

use nsql\database\Nsql;

// Reflection kullanarak metodları analiz et
$reflection = new ReflectionClass(Nsql::class);
$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

// OpenAPI spec oluştur
$spec = [
    'openapi' => '3.0.3',
    'info' => [
        'title' => 'nsql Database Library API',
        'version' => '1.4.0',
    ],
    'paths' => []
];

foreach ($methods as $method) {
    // Metod bilgilerini OpenAPI formatına çevir
    // ...
}

echo yaml_emit($spec);
```

## 📝 Notlar

- OpenAPI spec dosyası (`docs/openapi.yaml`) manuel olarak güncellenebilir
- Swagger UI, OpenAPI 3.0.3 formatını destekler
- Production'da Swagger UI'ı sadece internal network'te kullanın

---

**Son Güncelleme**: 2026-01-22  
**Versiyon**: 1.4.0
