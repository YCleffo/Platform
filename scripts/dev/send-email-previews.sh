#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

preview_to="${PREVIEW_TO:-preview@platform.test}"
mailpit_url="${MAILPIT_URL:-http://127.0.0.1:8025}"

curl -fsS -X DELETE "${mailpit_url}/api/v1/messages" >/dev/null || true
docker exec platform-backend-email-worker-1 \
  php yii communication:preview-emails --to="${preview_to}" --locale=all
docker exec platform-control-outbox-worker-1 \
  php bin/console control:preview-emails --to="${preview_to}"

echo "All Core and Commercial templates sent to ${mailpit_url}"
