FROM composer/composer:2-bin AS composer


# ---------------------------------------------------------------------
# Base PHP image
# ---------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.3-bookworm AS base

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

USER www-data
