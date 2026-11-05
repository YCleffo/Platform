FROM composer/composer:2-bin AS composer

# ---------------------------------------------------------------------
# Base PHP image
# ---------------------------------------------------------------------
# FrankenPHP 1.13.4 includes the former Go dependency overrides and PHP2Shell fixes.
FROM dunglas/frankenphp:php8.3.36@sha256:29d69af811fa99d158d0ad7f2b4ef3b8d8f591955656975f16ae56cb4f125119 AS base

# Check CLI and the PHP embedded in the HTTP server; neither may regress.
RUN php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 && PHP_VERSION_ID >= 80336 && PHP_ZTS ? 0 : 1);' \
    && frankenphp php-cli -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 && PHP_VERSION_ID >= 80336 && PHP_ZTS ? 0 : 1);'

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    opcache \
    mbstring \
    intl \
    dom \
    ctype \
    curl \
    phar \
    openssl \
    xml \
    xmlwriter \
    simplexml \
    pdo \
    pdo_pgsql

# Apply Debian security fixes after compiling PHP extensions.
RUN apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && dpkg --compare-versions "$(dpkg-query -W -f='${Version}' apt)" ge '3.0.4' \
    && rm -rf /var/lib/apt/lists/*

ENV LC_ALL=C.UTF-8

COPY docker/php/security.ini /usr/local/etc/php/conf.d/zz-security.ini


# ---------------------------------------------------------------------
# Development image
# ---------------------------------------------------------------------
FROM base AS dev

ARG USER_ID=10001
ARG GROUP_ID=10001
ARG USER_NAME=appuser
ARG GROUP_NAME=appuser

RUN install-php-extensions \
    xdebug

COPY --from=composer /composer /usr/bin/composer

RUN \
    groupadd --gid ${GROUP_ID} ${GROUP_NAME} \
    && useradd \
    --create-home \
    --gid ${GROUP_ID} \
    --uid ${USER_ID} \
    ${USER_NAME} \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /home/${USER_NAME}/.cache/composer \
    && chown -R ${USER_NAME}:${GROUP_NAME} \
    /home/${USER_NAME} \
    /data/caddy \
    /config/caddy

USER ${USER_NAME}

COPY docker/php/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
COPY docker/dev/Caddyfile /etc/frankenphp/Caddyfile


# ---------------------------------------------------------------------
# Production dependency builder
# ---------------------------------------------------------------------
FROM base AS prod-builder

COPY --from=composer /composer /usr/bin/composer

WORKDIR /app

COPY . /app

RUN --mount=type=cache,target=/tmp/cache \
    composer install \
    --no-dev \
    --no-progress \
    --no-interaction \
    --classmap-authoritative \
    && rm composer.lock composer.json


# ---------------------------------------------------------------------
# Production image
# ---------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /app

COPY docker/Caddyfile /etc/frankenphp/Caddyfile

COPY --from=prod-builder \
    --chown=www-data:www-data \
    /app \
    /app

RUN mkdir -p /data/caddy /config/caddy /app/runtime/cache/ro /app/runtime/logs \
    && chown -R www-data:www-data /data /config /app/runtime

# The dev target still compiles Xdebug; remove compiler headers only from prod.
RUN apt-get purge -y linux-libc-dev

USER www-data
