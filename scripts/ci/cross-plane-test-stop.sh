#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"


echo "=== Stopping isolated cross-plane test resources ==="
docker compose --profile control-test rm --stop --force control-plane-test control-postgres-test
docker compose --profile test rm --stop --force db-migrate-test postgres-test redis-test

# Remove only explicit test resources; never the runtime volumes.
if docker volume inspect platform_control_postgres_test_data >/dev/null 2>&1; then docker volume rm platform_control_postgres_test_data; fi
if docker volume inspect platform_postgres_test_data >/dev/null 2>&1; then docker volume rm platform_postgres_test_data; fi
if docker volume inspect platform_redis_test_data >/dev/null 2>&1; then docker volume rm platform_redis_test_data; fi
if docker network inspect platform_control_test_net >/dev/null 2>&1; then docker network rm platform_control_test_net; fi
if docker network inspect platform_test_net >/dev/null 2>&1; then docker network rm platform_test_net; fi
