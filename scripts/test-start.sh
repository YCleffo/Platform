#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

echo "=== Starting Isolated Test Infrastructure ==="
docker compose --profile test up -d postgres-test redis-test

echo "Applying migrations to platform_test..."
docker compose --profile test run --rm db-migrate-test

echo "Starting backend-test..."
docker compose --profile test up -d backend-test

echo "Test infrastructure is ready."
