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

# Redis / Memcached (opsiyonel — yoksa image yine build edilir)
RUN (pecl install redis && docker-php-ext-enable redis) || echo "redis extension skipped"
RUN (pecl install memcached && docker-php-ext-enable memcached) || echo "memcached extension skipped"

# Composer'ı yükle
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Dosyaları kopyala
COPY . .

# Composer bağımlılıkları — hata olursa build fail olmalı
RUN composer install --no-dev --optimize-autoloader --no-interaction

# İzinleri ayarla
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Port'u expose et
EXPOSE 9000

# PHP-FPM'i başlat
CMD ["php-fpm"]
