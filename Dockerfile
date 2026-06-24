# syntax=docker/dockerfile:1

###############################################################################
# Stage 1 — PHP dependencies (composer)
###############################################################################
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY . .
RUN composer install \
      --no-dev --prefer-dist --no-interaction --no-progress \
      --optimize-autoloader --no-scripts

###############################################################################
# Stage 2 — front-end assets (vite build)
###############################################################################
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

###############################################################################
# Stage 3 — application image (php-fpm)
###############################################################################
FROM php:8.4-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev libonig-dev unzip openssl \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath intl zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
# App source + vendor (from vendor stage, which has the full tree + optimized autoload)
COPY --from=vendor /app ./
# Compiled front-end assets
COPY --from=assets /app/public/build ./public/build

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions \
               storage/framework/views storage/logs storage/keys bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

###############################################################################
# Stage 4 — web (nginx) — same code path so fastcgi SCRIPT_FILENAME matches
###############################################################################
FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
