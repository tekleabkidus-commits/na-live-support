# Na Live Support application image — shared by app / worker / scheduler /
# reverb roles via a different command (Doc 18 FR-06: one deterministic image).

# --- Stage 1: frontend assets ------------------------------------------------
FROM node:20-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
RUN npm run build

# --- Stage 2: PHP runtime ----------------------------------------------------
FROM dunglas/frankenphp:1-php8.4

WORKDIR /app

RUN install-php-extensions \
    pcntl \
    pdo_pgsql \
    pgsql \
    redis \
    intl \
    zip \
    gd \
    bcmath \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependency layer first for build caching.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
COPY --from=assets /build/public/build ./public/build
RUN composer dump-autoload --optimize --no-scripts \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Octane + FrankenPHP entrypoint; worker/scheduler/reverb override `command`.
EXPOSE 80
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80"]
