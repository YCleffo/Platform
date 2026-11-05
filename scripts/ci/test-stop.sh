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

echo "=== Stopping Isolated Test Infrastructure ==="
docker compose --profile test rm --stop --force backend-production-smoke backend-test db-migrate-test postgres-test redis-test mailpit-core-test

# Remove only explicit test resources; never the runtime volumes.
test_project="${COMPOSE_PROJECT_NAME:-platform}"
if docker volume inspect "${test_project}_postgres_test_data" >/dev/null 2>&1; then docker volume rm "${test_project}_postgres_test_data"; fi
if docker volume inspect "${test_project}_redis_test_data" >/dev/null 2>&1; then docker volume rm "${test_project}_redis_test_data"; fi
if docker network inspect "${test_project}_test_net" >/dev/null 2>&1; then docker network rm "${test_project}_test_net"; fi
