#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

# Concurrent integration cases commit fixtures; workers must start on a clean test schema.
env APP_ENV=test bash ./scripts/ci/control-test-reset.sh

# Liveness starts the HTTP process; readiness must still reject absent workers.
docker compose --profile control-test up -d --wait control-plane-test
docker compose --profile control-test exec -T control-plane-test sh -se <<'SH'
test "$(curl -sS -o /tmp/readiness.json -w '%{http_code}' http://127.0.0.1:8000/health/ready)" = 503
test "$(cat /tmp/readiness.json)" = '{"status":"not_ready"}'

php bin/console control:scheduler:run >/tmp/ci-scheduler.log 2>&1 &
scheduler_pid=$!
php bin/console control:outbox:process-email --loop >/tmp/ci-outbox.log 2>&1 &
outbox_pid=$!
cleanup() {
  kill "$scheduler_pid" "$outbox_pid" 2>/dev/null || true
  wait "$scheduler_pid" 2>/dev/null || true
  wait "$outbox_pid" 2>/dev/null || true
}
trap cleanup EXIT

for attempt in $(seq 1 45); do
  if ! kill -0 "$scheduler_pid" 2>/dev/null || ! kill -0 "$outbox_pid" 2>/dev/null; then
    cat /tmp/ci-scheduler.log /tmp/ci-outbox.log
    exit 1
  fi
  if curl -fsS http://127.0.0.1:8000/health/ready >/tmp/readiness.json; then
    test "$(cat /tmp/readiness.json)" = '{"status":"ok"}'
    php bin/console control:ops:health control-scheduler
    php bin/console control:ops:health control-outbox-worker
    echo 'Readiness PASS: absent workers rejected; actual scheduler and outbox heartbeats accepted.'
    exit 0
  fi
  sleep 1
done
cat /tmp/ci-scheduler.log /tmp/ci-outbox.log
echo 'Control Plane did not become ready with actual workers.' >&2
exit 1
SH
