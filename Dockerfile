FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    postgresql-dev \
    oniguruma-dev \
    linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring zip exif pcntl bcmath gd intl

# phpredis (REDIS_CLIENT=phpredis): queues, cache and locks on Redis. Built from PECL with a pinned version and the
# build toolchain removed afterwards, so the image keeps only the compiled extension.
ARG PHPREDIS_VERSION=6.3.0
RUN apk add --no-cache --virtual .phpredis-build $PHPIZE_DEPS \
    && pecl install "redis-${PHPREDIS_VERSION}" \
    && docker-php-ext-enable redis \
    && apk del .phpredis-build \
    && rm -rf /tmp/pear

# The startup CMD caches about 2000 routes and the deploy runs migrations, Swagger generation and demo:reset: the
# 128M default is not enough. Applies to both the CLI and php-fpm.
RUN echo "memory_limit = 2048M" > /usr/local/etc/php/conf.d/zz-memory-limit.ini

# Upload limits (PHP defaults are 2M/8M). The largest file the API validates is the withholding-tax tariff import
# (max:102400 KB = 100M); documents, attachments and the backup restore are capped at 25600 KB. post_max_size sits above
# upload_max_filesize to leave room for the multipart envelope and the other form fields. The reverse proxy must allow
# the same size (client_max_body_size).
RUN printf 'upload_max_filesize = 100M\npost_max_size = 110M\n' > /usr/local/etc/php/conf.d/zz-uploads.ini

# OPcache sized for ~15000 classes (modules and vendor) plus the cached routes/config. The image is rebuilt on every
# deploy, but validate_timestamps stays at its default on purpose: it is not switched off here.
RUN printf 'opcache.memory_consumption = 256\nopcache.interned_strings_buffer = 16\nopcache.max_accelerated_files = 32531\n' > /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data app/ app/
COPY --chown=www-data:www-data bootstrap/ bootstrap/
COPY --chown=www-data:www-data config/ config/
COPY --chown=www-data:www-data database/ database/
COPY --chown=www-data:www-data lang/ lang/
COPY --chown=www-data:www-data modules/ modules/
COPY --chown=www-data:www-data packages/ packages/
COPY --chown=www-data:www-data public/ public/
COPY --chown=www-data:www-data resources/ resources/
COPY --chown=www-data:www-data routes/ routes/
COPY --chown=www-data:www-data artisan ./

RUN composer dump-autoload --optimize --no-dev --no-interaction

RUN mkdir -p /var/www/html/storage/logs \
             /var/www/html/storage/framework/cache/data \
             /var/www/html/storage/framework/sessions \
             /var/www/html/storage/framework/views \
             /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan view:cache && php-fpm"]
