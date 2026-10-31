#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"


SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${ROOT_DIR}"

: "${CONTROL_TEST_PASSWORD:?CONTROL_TEST_PASSWORD is required for the isolated Commercial test database.}"
export CROSS_PLANE_REQUIRED=1

echo "=== Starting isolated Core test infrastructure ==="
docker compose --profile test up -d --wait postgres-test redis-test
docker compose --profile test run --rm db-migrate-test

echo "=== Building deterministic cross-plane test image ==="
docker compose --profile control-test build control-plane-test
docker compose --profile control-test build cross-plane-test
docker compose --profile control-test run --rm --no-deps cross-plane-test sh -ec \
  'test -f /backend/vendor/autoload.php && test ! -e /backend/.env && test ! -e /backend/.git && test ! -e /backend/c3.php'

echo "=== Starting isolated Commercial test infrastructure ==="
docker compose --profile control-test up -d --wait control-postgres-test
docker compose --profile control-test run --rm --no-deps cross-plane-test php bin/console doctrine:migrations:migrate --no-interaction
core_ready=$(docker compose --profile test exec -T postgres-test psql -U "${POSTGRES_USER:-platform_app}" \
  -d "${POSTGRES_TEST_DB:-platform_test}" -tA \
  -c "SELECT to_regclass('platform_runtime.installations') IS NOT NULL AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL;")
test "${core_ready}" = "t"
commercial_ready=$(docker compose --profile control-test exec -T control-postgres-test psql \
  -U "${CONTROL_TEST_USER:-platform_control_test}" -d "${CONTROL_TEST_DB:-platform_commercial_test}" -tA \
  -c "SELECT to_regclass('commercial.deployments') IS NOT NULL AND to_regclass('commercial.service_credentials') IS NOT NULL;")
test "${commercial_ready}" = "t"

echo "Cross-plane prerequisites are ready: Core=platform_test, Commercial=platform_commercial_test."
