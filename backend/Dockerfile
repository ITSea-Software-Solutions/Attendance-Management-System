# ══════════════════════════════════════════════════════════════════════════════
# Stage 1 — Laravel scaffold + dependency install
# Uses composer image to create a full Laravel 11 project and pull all packages.
# This layer is cached; only rebuilds when composer.json changes.
# ══════════════════════════════════════════════════════════════════════════════
FROM php:8.4-cli-alpine AS builder

# ── Composer + build deps ────────────────────────────────────────────────────
RUN apk add --no-cache git curl unzip
RUN php -r "copy('https://getcomposer.org/installer','/tmp/composer-setup.php');" \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php

# Composer 2.8+ blocks installing packages flagged by security advisories by
# default. Every laravel/framework in the skeleton's ^11.31 range is currently
# flagged, so create-project/require fail to resolve. Disable the block so the
# build completes. NOTE: acceptable for the test/demo deployment only — before a
# real production build, bump laravel/framework to a patched version and remove this.
RUN composer config --global policy.advisories.block false

WORKDIR /app

# Create fresh Laravel 11 project (downloads from Packagist)
RUN composer create-project "laravel/laravel:^11.0" . \
    --prefer-dist --no-interaction --quiet

# Install our additional packages on top of the scaffold
RUN composer require \
    "laravel/sanctum:^4.0" \
    "guzzlehttp/guzzle:^7.2" \
    "predis/predis:^2.0" \
    --no-interaction --quiet

# ══════════════════════════════════════════════════════════════════════════════
# Stage 2 — PHP-FPM runtime image
# ══════════════════════════════════════════════════════════════════════════════
FROM php:8.4-fpm-alpine

# ── System dependencies ────────────────────────────────────────────────────────
RUN apk add --no-cache \
    git curl libpng-dev libjpeg-turbo-dev freetype-dev \
    libxml2-dev zip libzip-dev oniguruma-dev icu-dev \
    linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache sockets

# Bring composer into the runtime image for autoload generation
COPY --from=builder /usr/local/bin/composer /usr/local/bin/composer

# ── PHP runtime config ─────────────────────────────────────────────────────────
COPY docker/php/php.ini  /usr/local/etc/php/conf.d/app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# ── Copy full Laravel project from builder (vendor/, artisan, public/, etc.) ──
COPY --from=builder /app .

# ── Overlay with our custom application source files ──────────────────────────
#   These replace / extend the defaults created by laravel/laravel scaffold.
COPY app/                    app/
COPY bootstrap/app.php       bootstrap/app.php
COPY bootstrap/providers.php bootstrap/providers.php
COPY database/migrations/    database/migrations/
COPY database/seeders/       database/seeders/
COPY routes/api.php          routes/api.php
COPY routes/web.php          routes/web.php
COPY routes/console.php      routes/console.php
# Copy ALL repo config over the scaffold defaults. Cherry-picking individual
# files here has repeatedly caused silently-missing config (biometric.php,
# plans.php, filesystems.php with the 'private' disk) — never again.
COPY config/ config/

# ── Regenerate autoload so our new classes are discovered ─────────────────────
RUN composer dump-autoload --optimize --quiet

# ── Storage directories + permissions ─────────────────────────────────────────
RUN mkdir -p \
        storage/app/public \
        storage/app/aadhaar \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# ── Entrypoint: runs setup on every container start, then launches php-fpm ────
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/queue-entrypoint.sh /queue-entrypoint.sh
RUN sed -i 's/\r//' /entrypoint.sh /queue-entrypoint.sh \
    && chmod +x /entrypoint.sh /queue-entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
