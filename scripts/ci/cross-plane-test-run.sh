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

bash "${SCRIPT_DIR}/cross-plane-test-start.sh"
echo "=== Listing required cross-plane group ==="
docker compose --profile control-test run --rm --no-deps -e CROSS_PLANE_REQUIRED=1 cross-plane-test \
  vendor/bin/phpunit --configuration phpunit.cross-plane.xml --list-tests
echo "=== Running required cross-plane group ==="
docker compose --profile control-test run --rm --no-deps -e CROSS_PLANE_REQUIRED=1 cross-plane-test \
  vendor/bin/phpunit --configuration phpunit.cross-plane.xml --testdox
