FROM composer/composer:2-bin AS composer

# ---------------------------------------------------------------------
# Base FrankenPHP image
# ---------------------------------------------------------------------
# FrankenPHP 1.13.4 includes the former Go dependency overrides and PHP2Shell fixes.
FROM dunglas/frankenphp:php8.3.36@sha256:29d69af811fa99d158d0ad7f2b4ef3b8d8f591955656975f16ae56cb4f125119 AS base

# Check CLI and the PHP embedded in the HTTP server; neither may regress.
RUN php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 && PHP_VERSION_ID >= 80336 && PHP_ZTS ? 0 : 1);' \
    && frankenphp php-cli -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 && PHP_VERSION_ID >= 80336 && PHP_ZTS ? 0 : 1);'

RUN install-php-extensions \
    opcache \
    intl \
    pdo_pgsql \
    zip

# Apply available Debian fixes, then remove compiler headers from runtime images.
# PHP extensions are already compiled; test/Composer operations need no C++ toolchain.
RUN apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get purge -y linux-libc-dev \
    && dpkg --compare-versions "$(dpkg-query -W -f='${Version}' libbrotli1)" ge '1.1.0-2+deb13u1' \
    && rm -rf /var/lib/apt/lists/*

ENV LC_ALL=C.UTF-8

WORKDIR /app

# ---------------------------------------------------------------------
# Development / CI tools
# ---------------------------------------------------------------------
FROM base AS tools

COPY --from=composer /composer /usr/bin/composer

# ---------------------------------------------------------------------
# Production dependency builder
# ---------------------------------------------------------------------
FROM base AS prod-builder

COPY --from=composer /composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock /app/

RUN --mount=type=cache,target=/tmp/cache \
    composer install \
        --no-dev \
        --no-progress \
        --no-interaction \
        --prefer-dist \
        --classmap-authoritative \
        --no-scripts

COPY bin /app/bin
COPY config /app/config
COPY migrations /app/migrations
COPY public /app/public
COPY src /app/src

RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# ---------------------------------------------------------------------
# Production image
# ---------------------------------------------------------------------
FROM base AS app

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:8000 \
    SERVER_ROOT=public/

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=prod-builder \
    --chown=www-data:www-data \
    /app \
    /app

RUN mkdir -p /app/var/cache /app/var/log \
    && chown -R www-data:www-data /app/var \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /data /config

USER www-data

EXPOSE 8000

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# Isolated CI/test image; production remains --no-dev and non-root.
FROM base AS test

COPY --from=composer /composer /usr/bin/composer
COPY composer.json composer.lock /app/
RUN --mount=type=cache,target=/tmp/cache \
    composer install --no-progress --no-interaction --prefer-dist --no-scripts
COPY . /app
RUN mkdir -p /app/var/cache /app/var/log \
    && chown -R www-data:www-data /app/var \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /data /config
ENV APP_ENV=test APP_DEBUG=0 SERVER_NAME=:8000 SERVER_ROOT=public/
USER www-data
EXPOSE 8000
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
