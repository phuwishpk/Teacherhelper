# EduVision backend for the self-hosted Docker server (docs/SELFHOST.md).
# Apache + mod_php, the same shape as the Plesk host, so public/.htaccess and
# the web app's public/app/.htaccess apply unchanged. Build context: repo root.
FROM composer:2 AS composer

FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libicu-dev libzip-dev unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd intl zip pdo_mysql opcache bcmath exif pcntl \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers remoteip \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY tools/selfhost/php.ini $PHP_INI_DIR/conf.d/zz-eduvision.ini
COPY tools/selfhost/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Dependencies first, so a code-only change reuses this layer.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --no-interaction \
 && chown -R www-data:www-data storage bootstrap/cache

COPY tools/selfhost/entrypoint.sh /usr/local/bin/eduvision-entrypoint
COPY tools/selfhost/worker.sh /usr/local/bin/eduvision-worker
RUN chmod +x /usr/local/bin/eduvision-entrypoint /usr/local/bin/eduvision-worker

ENTRYPOINT ["eduvision-entrypoint"]
CMD ["apache2-foreground"]
