#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

: "${CONTROL_TEST_PASSWORD:?CONTROL_TEST_PASSWORD is required for the isolated Commercial test database.}"
export CROSS_PLANE_REQUIRED=1

echo "=== Starting isolated Core test infrastructure ==="
docker compose --profile test up -d postgres-test redis-test
docker compose --profile test run --rm db-migrate-test

echo "=== Preparing independent Core dependencies ==="
docker compose --profile control-test build control-plane-test
docker compose --profile control-test run --rm --no-deps --user root \
  -v platform_cross_plane_backend_vendor:/backend/vendor \
  control-plane-test \
  sh -lc 'COMPOSER_VENDOR_DIR=/backend/vendor composer install --working-dir=/backend --no-interaction --prefer-dist --no-progress --no-scripts'
docker compose --profile control-test run --rm --no-deps control-plane-test \
  test -f /backend/vendor/autoload.php

echo "=== Starting isolated Commercial test infrastructure ==="
docker compose --profile control-test up -d control-postgres-test
docker compose --profile control-test run --rm control-plane-test php bin/console doctrine:migrations:migrate --no-interaction
core_ready=$(docker compose --profile test exec -T postgres-test psql -U "${POSTGRES_USER:-platform_app}" \
  -d "${POSTGRES_TEST_DB:-platform_test}" -tA \
  -c "SELECT to_regclass('platform_runtime.installations') IS NOT NULL AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL;")
test "${core_ready}" = "t"
commercial_ready=$(docker compose --profile control-test exec -T control-postgres-test psql \
  -U "${CONTROL_TEST_USER:-platform_control_test}" -d "${CONTROL_TEST_DB:-platform_commercial_test}" -tA \
  -c "SELECT to_regclass('commercial.deployments') IS NOT NULL AND to_regclass('commercial.service_credentials') IS NOT NULL;")
test "${commercial_ready}" = "t"

echo "Cross-plane prerequisites are ready: Core=platform_test, Commercial=platform_commercial_test."
