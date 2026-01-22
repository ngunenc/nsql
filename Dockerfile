# PHP 8.2 FPM için Dockerfile
FROM php:8.2-fpm

# Sistem bağımlılıklarını yükle
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite \
    && docker-php-ext-enable pdo_mysql pdo_pgsql pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Redis ve Memcached extension'larını yükle (opsiyonel)
RUN pecl install redis memcached \
    && docker-php-ext-enable redis memcached || true

# Composer'ı yükle
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Dosyaları kopyala
COPY . .

# Composer bağımlılıklarını yükle (production için)
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# İzinleri ayarla
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Port'u expose et
EXPOSE 9000

# PHP-FPM'i başlat
CMD ["php-fpm"]
