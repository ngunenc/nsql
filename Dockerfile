# PHP 8.4 FPM için Dockerfile
# Sürüm sabit: PHP2Shell (CVE-2026-109618 / CVE-2026-109619) yamasını içeren en düşük 8.4 sürümü.
# Güncel yama için: docker build --pull --build-arg PHP_VERSION=8.4.x .
ARG PHP_VERSION=8.4.27
FROM php:${PHP_VERSION}-fpm

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

# PHP sertleştirme (session.upload_progress kapalı, expose_php kapalı)
COPY docker/php/security.ini /usr/local/etc/php/conf.d/zz-nsql-security.ini

# Composer'ı yükle
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Dosyaları kopyala
COPY . .

# Composer bağımlılıkları — hata olursa build fail olmalı
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Kod root'a ait kalır (PHP süreci kendi kodunu değiştiremez); yalnızca storage/ yazılabilir.
# Anahtar dizini başkalarınca okunamaz.
RUN mkdir -p storage/logs storage/keys \
    && chown -R www-data:www-data storage \
    && chmod -R u=rwX,g=rX,o= storage \
    && chmod 700 storage/keys

# Port'u expose et
EXPOSE 9000

# PHP-FPM'i başlat
CMD ["php-fpm"]
