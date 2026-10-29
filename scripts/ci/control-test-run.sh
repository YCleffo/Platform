#!/usr/bin/env bash
set -euo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"


if [[ -z "${CONTROL_TEST_PASSWORD:-}" ]]; then
  echo 'CONTROL_TEST_PASSWORD is required.' >&2
  exit 1
fi

docker compose --profile control-test run --rm control-plane-test vendor/bin/phpunit
