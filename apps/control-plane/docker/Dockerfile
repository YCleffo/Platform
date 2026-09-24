FROM composer/composer:2-bin AS composer

# ---------------------------------------------------------------------
# Base FrankenPHP image
# ---------------------------------------------------------------------
FROM dunglas/frankenphp:1.12-php8.3 AS base

RUN install-php-extensions \
    opcache \
    intl \
    pdo_pgsql \
    zip

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

COPY . /app

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
