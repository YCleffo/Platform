#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

echo "=== Safely Resetting Isolated Test Infrastructure ==="
docker compose --profile test stop backend-test db-migrate-test postgres-test redis-test || true
docker compose --profile test rm -f -v backend-test db-migrate-test postgres-test redis-test || true

# Explicitly remove only test volumes (DEV volumes are never touched)
docker volume rm -f platform_postgres_test_data platform_redis_test_data || true

"${SCRIPT_DIR}/test-start.sh"

echo "Test reset complete."
