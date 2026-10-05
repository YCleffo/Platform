#!/usr/bin/env bash
set -Eeuo pipefail

echo "=== Stopping isolated cross-plane test resources ==="
docker compose --profile control-test rm --stop --force control-plane-test control-postgres-test
docker compose --profile test rm --stop --force db-migrate-test postgres-test redis-test
docker volume rm platform_cross_plane_backend_vendor 2>/dev/null || true
