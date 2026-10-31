FROM composer/composer:2-bin AS composer

# Rebuild the official FrankenPHP 1.12.7 binary with the upstream security
# releases that are newer than the current prebuilt image. Mercure and Vulcain
# stay enabled because they are part of the upstream binary; only dependency
# versions change.
FROM dunglas/frankenphp:1.12.7-builder-php8.3@sha256:ff93138a6f94e59de46d69e49f77007fe0274f04ca1ec5b70a58ebbf0c821e98 AS frankenphp-builder

ENV PATH="/usr/local/go/bin:${PATH}" \
    GOTOOLCHAIN=go1.26.10 \
    GODEBUG=http2client=0

WORKDIR /go/src/app/caddy

RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    go get \
        github.com/getkin/kin-openapi@v0.149.0 \
        github.com/go-jose/go-jose/v4@v4.1.6 \
        google.golang.org/grpc@v1.83.2 \
        golang.org/x/crypto@v0.57.0 \
        golang.org/x/net@v0.60.0 \
    && go mod tidy

WORKDIR /go/src/app/caddy/frankenphp

RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    GOBIN=/usr/local/bin \
    ../../go.sh install \
        -trimpath \
        -ldflags "-w -s -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP v1.12.7 PHP 8.3 Caddy' -X 'github.com/caddyserver/caddy/v2.CustomBinaryName=frankenphp' -X 'github.com/caddyserver/caddy/v2/modules/caddyhttp.ServerHeader=FrankenPHP Caddy'" \
        -buildvcs=false



# ---------------------------------------------------------------------
# Base PHP image
# ---------------------------------------------------------------------
FROM dunglas/frankenphp:1.12-php8.3@sha256:d5c2c5cdc8b6e73d861cccbf26ddab6443e3dc56e82641e5d991d21133e58a5d AS base

COPY --from=frankenphp-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

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
