#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
if [[ "${CI:-}" != true || "${COMPOSE_PROJECT_NAME:-}" != platform-billing-ci ]]; then
  echo 'Periodic billing requires its dedicated CI project.' >&2; exit 2
fi
: "${BILLING_TEST_CLOCK_DIRECTORY:?}" "${E2E_ISOLATION_PROOF:?}"
[[ ${#E2E_ISOLATION_PROOF} -ge 32 ]] || exit 2
mkdir -p "$BILLING_TEST_CLOCK_DIRECTORY"
printf '+0s\n' > "$BILLING_TEST_CLOCK_DIRECTORY/time"
# Build the unmodified runtime before extending it with a test-only preload.
docker compose -f compose.yaml -f compose.ci.yaml -f compose.browser.yaml --profile control-test build control-postgres-test control-plane-test
export COMPOSE_FILE=compose.yaml:compose.ci.yaml:compose.browser.yaml:compose.billing-clock.yaml
docker compose --profile control-test build control-postgres-test control-plane-test
docker compose --profile control-test pull mailpit-test
docker compose --profile control-test up -d --no-build --pull never --wait control-postgres-test
docker compose --profile control-test run --rm --no-deps control-plane-test php bin/console doctrine:migrations:migrate --no-interaction
docker compose --profile control-test up -d --no-build --pull never --wait control-plane-test mailpit-test
for unsafe in APP_ENV=prod CONTROL_TEST_ISOLATED=0 CONTROL_E2E_ISOLATION_NONCE=short \
  'CONTROL_DATABASE_URL=postgresql://x:x@control-postgres:5432/platform_commercial?serverVersion=18'; do
  status=0
  docker compose --profile control-test exec -T -e "$unsafe" control-plane-test /opt/platform-test/entrypoint true || status=$?
  if [[ $status -ne 126 ]]; then echo 'Unsafe clock runtime was not refused.' >&2; exit 1; fi
done
docker compose --profile control-test exec -d control-plane-test /opt/platform-test/entrypoint php bin/console control:outbox:process-email --loop
for _attempt in $(seq 1 30); do
  if docker compose --profile control-test exec -T control-plane-test /opt/platform-test/entrypoint php bin/console control:ops:health control-outbox-worker >/dev/null 2>&1; then exit 0; fi
  sleep 1
done
echo 'Isolated clock outbox worker did not become ready.' >&2
exit 1
