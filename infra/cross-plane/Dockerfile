FROM platform-control-plane-test:latest AS core-deps
USER root
WORKDIR /backend
COPY apps/backend/composer.json apps/backend/composer.lock ./
COPY apps/backend/config ./config
COPY apps/backend/src ./src
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

FROM platform-control-plane-test:latest AS test
COPY --from=core-deps /backend/vendor /backend/vendor
COPY apps/backend /backend
USER root
RUN mkdir -p /backend/runtime /backend/tests/_output \
    && chown -R www-data:www-data /backend/runtime /backend/tests/_output
USER www-data
