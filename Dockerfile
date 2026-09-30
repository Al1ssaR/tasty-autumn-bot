FROM composer:2.8.12 AS dependencies

WORKDIR /app

COPY . .

RUN composer install \
    --no-interaction \
    --no-ansi \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.4.25-cli-bookworm AS application

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libpq-dev \
        libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" \
        dom \
        mbstring \
        pdo_pgsql \
        xml \
        xmlwriter \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=dependencies /app/vendor ./vendor
COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
