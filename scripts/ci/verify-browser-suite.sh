#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local browser verification is disabled." >&2
  exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$(cd "${SCRIPT_DIR}/../../apps/frontend" && pwd)"
cd "${FRONTEND_DIR}"

BASE_URL="${BASE_URL:-http://127.0.0.1:4174}"
export BASE_URL

mkdir -p screenshots
node node_modules/vite/bin/vite.js preview --host 127.0.0.1 --port 4174 --strictPort > /tmp/vite-verify.log 2>&1 &
preview_pid=$!

finish() {
  status=$?
  cp /tmp/vite-verify.log screenshots/vite-verify.log 2>/dev/null || true
  kill "${preview_pid}" 2>/dev/null || true
  wait "${preview_pid}" 2>/dev/null || true
  exit "${status}"
}
trap finish EXIT

for _attempt in $(seq 1 60); do
  if curl -fsS "${BASE_URL}/settings" >/dev/null; then break; fi
  sleep 1
done
curl -fsS "${BASE_URL}/settings" >/dev/null

node ../../scripts/ci/run-verify-suite.mjs
