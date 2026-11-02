#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

docker compose --profile control exec -T control-postgres \
  psql -U platform_control -d platform_commercial -v ON_ERROR_STOP=1 \
  < scripts/dev/seed-demo-catalog.sql
echo 'Demo catalog seeded. Open /settings or the account Billing tab.'
