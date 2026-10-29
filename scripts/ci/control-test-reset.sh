#!/usr/bin/env bash
set -euo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"


if [[ "${APP_ENV:-}" != 'test' || -z "${CONTROL_TEST_PASSWORD:-}" ]]; then
  echo 'Reset requires APP_ENV=test and CONTROL_TEST_PASSWORD.' >&2
  exit 1
fi

docker compose --profile control-test run --rm control-plane-test php bin/console control:test:reset
docker compose --profile control-test run --rm control-plane-test php bin/console doctrine:migrations:migrate --no-interaction
