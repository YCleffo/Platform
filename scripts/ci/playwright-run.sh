#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo "This entry point is CI-only; local Playwright containers are disabled." >&2
  exit 2
fi

if [[ $# -eq 0 ]]; then
  echo "Usage: playwright-run.sh <command> [args...]" >&2
  exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="${GITHUB_WORKSPACE:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
WORKDIR="${PLAYWRIGHT_WORKDIR:-${ROOT_DIR}/apps/frontend}"
NETWORK="${PLAYWRIGHT_DOCKER_NETWORK:-host}"
IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.64.1-noble@sha256:2f05f8a734509a7b3f26a912639488ee08f58a7b292d14d62271bcff9038768f}"

if [[ ! -d "${WORKDIR}" ]]; then
  echo "Playwright work directory does not exist: ${WORKDIR}" >&2
  exit 2
fi

docker_args=(
  --rm
  --init
  --shm-size=1g
  --network "${NETWORK}"
  --add-host e2e.platform.test:127.0.0.1
  --user "$(id -u):$(id -g)"
  --env HOME=/tmp
  --volume "${ROOT_DIR}:${ROOT_DIR}"
  --workdir "${WORKDIR}"
)

for name in \
  CI \
  BASE_URL \
  APP_URL \
  CONTROL_API_URL \
  CORE_API_URL \
  MAILPIT_URL \
  E2E_RUNTIME \
  E2E_ISOLATED_DB \
  E2E_ALLOW_MUTATIONS \
  E2E_ALLOWED_HOSTS \
  E2E_PASSWORD \
  E2E_EMAIL_A \
  E2E_EMAIL_B \
  E2E_ISOLATION_PROOF \
  NODE_EXTRA_CA_CERTS \
  NODE_OPTIONS \
  LOAD_PROFILE \
  VERIFY_CONCURRENCY \
  VERIFY_TIMEOUT_MS \
  RESPONSIVE_CONCURRENCY; do
  if [[ -n "${!name:-}" ]]; then
    docker_args+=(--env "${name}")
  fi
done

if [[ -n "${NODE_EXTRA_CA_CERTS:-}" && -n "${RUNNER_TEMP:-}" ]]; then
  docker_args+=(--volume "${RUNNER_TEMP}:${RUNNER_TEMP}:ro")
fi

exec docker run "${docker_args[@]}" "${IMAGE}" "$@"
