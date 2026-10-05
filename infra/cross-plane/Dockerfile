FROM platform-control-plane-test:latest AS core-deps
USER root
WORKDIR /backend
COPY apps/backend/composer.json apps/backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

FROM platform-control-plane-test:latest AS test
COPY --from=core-deps /backend/vendor /backend/vendor
COPY apps/backend /backend
USER www-data
