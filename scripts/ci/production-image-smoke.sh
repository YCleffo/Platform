#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo 'This entry point is CI-only and requires isolated test databases.' >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
case "${1:-}" in
  core) smoke_profile="test"; smoke_service="backend-production-smoke"; smoke_image="${CORE_SMOKE_IMAGE:-platform-core:ci}" ;;
  commercial) smoke_profile="control-test"; smoke_service="control-production-smoke"; smoke_image="${CONTROL_SMOKE_IMAGE:-platform-control-plane:ci}" ;;
  *) echo 'Usage: production-image-smoke.sh core|commercial' >&2; exit 2 ;;
esac

docker image inspect "$smoke_image" >/dev/null
docker compose --profile "$smoke_profile" run --rm --pull never --no-deps -T --entrypoint sh "$smoke_service" -se <<'SH'
test "$(id -u)" -ne 0
test ! -e /app/tests
test ! -e /app/.env
test ! -e /app/vendor/bin/phpunit
frankenphp run --config /etc/frankenphp/Caddyfile >/tmp/production-http.log 2>&1 &
http_pid=$!
cleanup() {
  kill "$http_pid" 2>/dev/null || true
  wait "$http_pid" 2>/dev/null || true
}
trap cleanup EXIT
for _attempt in $(seq 1 30); do
  if ! kill -0 "$http_pid" 2>/dev/null; then break; fi
  if curl -fsS --max-time 3 "$SMOKE_URL/health/live" >/tmp/live.json \
    && curl -fsS --max-time 3 "$SMOKE_URL/health/ready" >/tmp/ready.json; then
    test "$(cat /tmp/live.json)" = '{"status":"ok"}'
    test "$(cat /tmp/ready.json)" = '{"status":"ok"}'
    echo 'Production HTTP and database schema PASS; workers are verified in the dedicated readiness job.'
    exit 0
  fi
  sleep 1
done
cat /tmp/production-http.log
echo 'Production image failed HTTP boot or database readiness without checkout mounts.' >&2
exit 1
SH
