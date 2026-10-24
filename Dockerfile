FROM php:8.5-fpm-alpine AS base

# Dipendenze di sistema e runtime PHP
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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Cache dipendenze Composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

# Copia granulare delle directory per risolvere il security hotspot di Sonar (evita COPY . .)
COPY --chown=www-data:www-data app/ app/
COPY --chown=www-data:www-data bootstrap/ bootstrap/
COPY --chown=www-data:www-data config/ config/
COPY --chown=www-data:www-data database/ database/
COPY --chown=www-data:www-data lang/ lang/
COPY --chown=www-data:www-data public/ public/
COPY --chown=www-data:www-data resources/ resources/
COPY --chown=www-data:www-data routes/ routes/
COPY --chown=www-data:www-data artisan ./

# Generazione autoloader ottimizzato per produzione
RUN composer dump-autoload --optimize --no-dev --no-interaction

# Creazione cartelle di runtime con permessi per l'utente non-privileged
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
