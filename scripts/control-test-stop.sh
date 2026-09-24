#!/usr/bin/env bash
set -euo pipefail

docker compose --profile control-test stop control-plane-test control-postgres-test
docker compose --profile control-test rm -f control-plane-test control-postgres-test
