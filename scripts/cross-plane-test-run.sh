#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

bash "${SCRIPT_DIR}/cross-plane-test-start.sh"
echo "=== Running required CrossPlaneBootstrapTest ==="
docker compose --profile control-test run --rm -e CROSS_PLANE_REQUIRED=1 control-plane-test \
  vendor/bin/phpunit tests/Integration/CrossPlaneBootstrapTest.php --testdox
