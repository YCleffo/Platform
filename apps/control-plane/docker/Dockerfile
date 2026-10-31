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
# Base FrankenPHP image
# ---------------------------------------------------------------------
FROM dunglas/frankenphp:1.12-php8.3@sha256:d5c2c5cdc8b6e73d861cccbf26ddab6443e3dc56e82641e5d991d21133e58a5d AS base

COPY --from=frankenphp-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

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
