ARG CONTROL_TEST_IMAGE=platform-control-plane-test:latest
FROM ${CONTROL_TEST_IMAGE} AS core-deps
USER root
WORKDIR /backend
COPY apps/backend/composer.json apps/backend/composer.lock ./
COPY apps/backend/config ./config
COPY apps/backend/src ./src
RUN --mount=type=cache,target=/tmp/cache \
    COMPOSER_CACHE_DIR=/tmp/cache COMPOSER_MAX_PARALLEL_HTTP=1 composer install --no-interaction --prefer-dist --no-progress --no-scripts \
    && composer yii-config-rebuild \
    && test -s /backend/config/.merge-plan.php

FROM ${CONTROL_TEST_IMAGE} AS test
COPY --from=core-deps /backend/vendor /backend/vendor
COPY apps/backend /backend
COPY --from=core-deps /backend/config/.merge-plan.php /backend/config/.merge-plan.php
USER root
RUN mkdir -p /backend/runtime /backend/tests/_output \
    && chown -R www-data:www-data /backend/runtime /backend/tests/_output
USER www-data
