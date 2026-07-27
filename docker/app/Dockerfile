# Na Live Support application image — shared by app / worker / scheduler /
# reverb roles via a different command (Doc 18 FR-06: one deterministic image).
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
RUN composer dump-autoload --optimize --no-scripts

# Octane + FrankenPHP entrypoint; worker/scheduler/reverb override `command`.
EXPOSE 80
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80"]
