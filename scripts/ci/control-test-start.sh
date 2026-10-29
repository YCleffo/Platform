#!/usr/bin/env bash
set -euo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"


if [[ -z "${CONTROL_TEST_PASSWORD:-}" ]]; then
  echo 'CONTROL_TEST_PASSWORD is required for the isolated Commercial test database.' >&2
  exit 1
fi

docker compose --profile control-test up -d --wait control-postgres-test
docker compose --profile control-test build control-plane-test
docker compose --profile control-test run --rm control-plane-test php bin/console doctrine:migrations:migrate --no-interaction
