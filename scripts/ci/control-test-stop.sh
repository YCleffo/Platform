#!/usr/bin/env bash
set -euo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"


docker compose --profile control-test stop control-plane-test control-postgres-test
docker compose --profile control-test rm -f control-plane-test control-postgres-test

# Remove only explicit test resources; never the runtime volumes.
if docker volume inspect platform_control_postgres_test_data >/dev/null 2>&1; then docker volume rm platform_control_postgres_test_data; fi
if docker volume inspect platform_cross_plane_backend_vendor >/dev/null 2>&1; then docker volume rm platform_cross_plane_backend_vendor; fi
if docker network inspect platform_control_test_net >/dev/null 2>&1; then docker network rm platform_control_test_net; fi
if docker network inspect platform_test_net >/dev/null 2>&1; then docker network rm platform_test_net; fi
