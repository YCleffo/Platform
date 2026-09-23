#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

echo "=== Safely Resetting Isolated Test Infrastructure ==="
bash "${SCRIPT_DIR}/test-stop.sh"

# Explicitly remove only test volumes (DEV volumes are never touched)
for volume in platform_postgres_test_data platform_redis_test_data; do
  if docker volume inspect "${volume}" >/dev/null 2>&1; then
    docker volume rm "${volume}"
  fi
done

bash "${SCRIPT_DIR}/test-start.sh"

echo "Test reset complete."
