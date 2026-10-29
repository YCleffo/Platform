#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local disposable Docker stacks are disabled." >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"


ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT_DIR}"

if [[ "${POSTGRES_TEST_DB:-platform_test}" != 'platform_test' ]]; then
  echo 'Refusing E20 outside the isolated Core test database.' >&2
  exit 1
fi

db_user="${POSTGRES_USER:-platform_app}"
sql() {
  docker compose --profile test exec -T postgres-test \
    psql -U "${db_user}" -d platform_test -tA -c "$1"
}

backend_id="$(docker compose --profile test ps -q backend-test)"
postgres_id="$(docker compose --profile test ps -q postgres-test)"
test -n "${backend_id}" && test -n "${postgres_id}"
backend_started="$(docker inspect -f '{{.State.StartedAt}}' "${backend_id}")"
postgres_started="$(docker inspect -f '{{.State.StartedAt}}' "${postgres_id}")"

marker="e20_probe_$(date +%s)_${RANDOM}"
sql "INSERT INTO platform_runtime.background_job_status (job_code, last_result) VALUES ('${marker}', 'waiting')" >/dev/null
cleanup() { sql "DELETE FROM platform_runtime.background_job_status WHERE job_code='${marker}'" >/dev/null; }
trap cleanup EXIT
old_heartbeat="$(sql "SELECT COALESCE(EXTRACT(EPOCH FROM heartbeat_at),0) FROM platform_runtime.background_job_status WHERE job_code='__scheduler__'")"
old_heartbeat="${old_heartbeat:-0}"
old_auth_success="$(sql "SELECT COALESCE(EXTRACT(EPOCH FROM last_succeeded_at),0) FROM platform_runtime.background_job_status WHERE job_code='auth_cleanup'")"
old_auth_success="${old_auth_success:-0}"

docker compose --profile test restart backend-test
test "$(docker compose --profile test ps -q postgres-test)" = "${postgres_id}"
test "$(docker inspect -f '{{.State.StartedAt}}' "${postgres_id}")" = "${postgres_started}"
test "$(docker inspect -f '{{.State.StartedAt}}' "${backend_id}")" != "${backend_started}"

for _ in $(seq 1 30); do
  if [[ "$(docker inspect -f '{{.State.Health.Status}}' "${backend_id}")" == 'healthy' ]]; then break; fi
  sleep 1
done
test "$(docker inspect -f '{{.State.Health.Status}}' "${backend_id}")" = 'healthy'
test "$(sql "SELECT count(*) FROM platform_runtime.background_job_status WHERE job_code='${marker}' AND last_result='waiting'")" = '1'

set +e
docker compose --profile test exec -T \
  -e CORE_SCHEDULER_ENABLED=true \
  -e CORE_AUTH_CLEANUP_ENABLED=true \
  -e CORE_AUTH_CLEANUP_BATCH_LIMIT=1 \
  -e CORE_PRIVACY_RETENTION_ENABLED=false \
  -e CORE_PRIVACY_ERASURE_ENABLED=false \
  -e CORE_PRIVACY_CLEANUP_ENABLED=false \
  -e CORE_ENTITLEMENT_SYNC_ENABLED=false \
  backend-test timeout 5 php yii scheduler:run >/dev/null
worker_exit=$?
set -e
test "${worker_exit}" -eq 124
test "$(sql "SELECT count(*) FROM platform_runtime.background_job_status WHERE job_code='__scheduler__' AND EXTRACT(EPOCH FROM heartbeat_at) > ${old_heartbeat}")" = '1'
test "$(sql "SELECT count(*) FROM platform_runtime.background_job_status WHERE job_code='auth_cleanup' AND last_result='succeeded' AND EXTRACT(EPOCH FROM last_succeeded_at) > ${old_auth_success}")" = '1'
test "$(sql "SELECT count(*) FROM platform_runtime.background_job_status WHERE job_code='${marker}' AND last_result='waiting'")" = '1'

echo 'E20 PASS: stateless backend-test restarted, PostgreSQL stayed up, durable runtime row survived, auth cleanup job succeeded after restart.'
