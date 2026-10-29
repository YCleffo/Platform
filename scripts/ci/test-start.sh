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

echo "=== Starting Isolated Test Infrastructure ==="
docker compose --profile test up -d postgres-test redis-test

echo "Applying migrations to platform_test..."
docker compose --profile test run --rm db-migrate-test

echo "Starting backend-test..."
docker compose --profile test up -d backend-test

echo "Test infrastructure is ready."
