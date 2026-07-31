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

# TLS material for the whole stack (Doc 02 SEC-06). Baked here so certificates
# are deterministic and need no runtime acquisition — see the script's header
# for why, and for the line separating this from Doc 18's production certs.
COPY docker/app/generate-dev-certs.sh /usr/local/bin/generate-dev-certs.sh
RUN chmod +x /usr/local/bin/generate-dev-certs.sh && /usr/local/bin/generate-dev-certs.sh

# Dependency layer first for build caching.
# Dev dependencies ARE installed: this is the local/CI image and the
# Integration/Isolation suites run inside it (parity, Doc 01 SEC-01). The
# hardened production image (--no-dev, distroless-ish) is Doc 18's session.
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
COPY --from=assets /build/public/build ./public/build
RUN composer dump-autoload --optimize --no-scripts \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Octane + FrankenPHP entrypoint; worker/scheduler/reverb override `command`.
#
# The Caddyfile pins TLS to the image-baked certificate and serves :80 only as a
# 308 redirect (SEC-06). Port 80 stays exposed FOR that redirect — a developer
# who types http://localhost lands on https rather than on a connection error,
# which is the behaviour production has too.
EXPOSE 80 443
CMD ["php", "artisan", "octane:frankenphp", "--caddyfile=/app/docker/app/Caddyfile"]
