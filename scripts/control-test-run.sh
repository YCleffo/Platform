#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${CONTROL_TEST_PASSWORD:-}" ]]; then
  echo 'CONTROL_TEST_PASSWORD is required.' >&2
  exit 1
fi

docker compose --profile control-test run --rm control-plane-test vendor/bin/phpunit
