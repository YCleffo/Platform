#!/usr/bin/env bash
set -euo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"


docker compose --profile control-test stop control-production-smoke control-plane-test control-postgres-test mailpit-test
docker compose --profile control-test rm -f control-production-smoke control-plane-test control-postgres-test mailpit-test

# Remove only explicit test resources; never the runtime volumes.
test_project="${COMPOSE_PROJECT_NAME:-platform}"
if docker volume inspect "${test_project}_control_postgres_test_data" >/dev/null 2>&1; then docker volume rm "${test_project}_control_postgres_test_data"; fi
if docker network inspect "${test_project}_control_test_net" >/dev/null 2>&1; then docker network rm "${test_project}_control_test_net"; fi
if docker network inspect "${test_project}_test_net" >/dev/null 2>&1; then docker network rm "${test_project}_test_net"; fi
