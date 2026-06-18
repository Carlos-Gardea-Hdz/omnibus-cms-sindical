# syntax=docker/dockerfile:1
# ==============================================================================
# Corporate CMS — production/staging image (PHP 8.5 + compiled Inertia assets)
# Multi-stage: build front-end assets with Node, run the app on PHP 8.5.
# ==============================================================================

# ---- Stage 1: build front-end assets (React 19 + Tailwind v4 via Vite) -------
FROM node:24-alpine AS assets
WORKDIR /app
RUN corepack enable
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY resources ./resources
COPY vite.config.js tsconfig.json ./
RUN pnpm run build

# ---- Stage 2: PHP dependencies ------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# ---- Stage 3: runtime ---------------------------------------------------------
FROM php:8.5-cli-alpine AS runtime

# PHP extensions (mlocati installer handles deps cleanly)
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions pdo_sqlite pdo_pgsql redis intl bcmath zip gd opcache pcntl

WORKDIR /var/www/html

# App code + vendored deps + built assets
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Composer (to finish autoloader) then dump optimized autoload
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs database \
    && touch database/database.sqlite \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R ug+rw storage bootstrap/cache

ENV APP_ENV=staging \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/database/database.sqlite \
    SESSION_DRIVER=file \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    LOG_CHANNEL=stderr

EXPOSE 8000
USER www-data

# Cache config/routes/views, run migrations (sqlite), then serve.
CMD php artisan config:cache \
    && php artisan route:cache \
    && php artisan migrate --force \
    && php artisan serve --host=0.0.0.0 --port=8000
