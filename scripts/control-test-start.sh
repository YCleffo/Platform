#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${CONTROL_TEST_PASSWORD:-}" ]]; then
  echo 'CONTROL_TEST_PASSWORD is required for the isolated Commercial test database.' >&2
  exit 1
fi

docker compose --profile control-test up -d control-postgres-test
docker compose --profile control-test build control-plane-test
docker compose --profile control-test run --rm control-plane-test php bin/console doctrine:migrations:migrate --no-interaction
