#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo 'This entry point is CI-only.' >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

docker compose --profile control-test exec -d control-plane-test php bin/console control:outbox:process-email --loop
for _attempt in $(seq 1 30); do
  if docker compose --profile control-test exec -T control-plane-test php bin/console control:ops:health control-outbox-worker >/dev/null 2>&1; then
    echo 'Commercial outbox worker heartbeat is ready.'
    exit 0
  fi
  sleep 1
done
docker compose --profile control-test exec -T control-plane-test php bin/console control:ops:health control-outbox-worker
exit 1
