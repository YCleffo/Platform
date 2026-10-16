#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

bash "${SCRIPT_DIR}/cross-plane-test-start.sh"
echo "=== Listing required cross-plane group ==="
docker compose --profile control-test run --rm --no-deps -e CROSS_PLANE_REQUIRED=1 cross-plane-test \
  vendor/bin/phpunit --configuration phpunit.cross-plane.xml --list-tests
echo "=== Running required cross-plane group ==="
docker compose --profile control-test run --rm --no-deps -e CROSS_PLANE_REQUIRED=1 cross-plane-test \
  vendor/bin/phpunit --configuration phpunit.cross-plane.xml --testdox
