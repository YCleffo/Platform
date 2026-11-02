#!/usr/bin/env bash
set -Eeuo pipefail

[[ "${CI:-}" == "true" ]] || { echo 'This entry point is CI-only.' >&2; exit 2; }
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"
[[ "${CONTROL_TEST_DB:-}" == "platform_commercial_test" ]] || {
  echo 'Catalog fixtures require the disposable platform_commercial_test database.' >&2
  exit 2
}
: "${CONTROL_TEST_USER:?CONTROL_TEST_USER is required}"
: "${CONTROL_TEST_PASSWORD:?CONTROL_TEST_PASSWORD is required}"
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

docker compose --profile control-test exec -T \
  -e PGPASSWORD="${CONTROL_TEST_PASSWORD}" \
  control-postgres-test psql -h 127.0.0.1 -U "${CONTROL_TEST_USER}" \
  -d "${CONTROL_TEST_DB}" -v ON_ERROR_STOP=1 < scripts/dev/seed-demo-catalog.sql
echo 'Demo catalog seeded.'
