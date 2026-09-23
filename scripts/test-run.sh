#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

bash "${SCRIPT_DIR}/test-start.sh"

echo "=== Running Codeception tests inside backend-test ==="
docker compose --profile test exec -T backend-test composer test "$@"
