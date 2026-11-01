#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${CI:-}" != "true" ]]; then
  echo 'This entry point is CI-only and requires isolated test databases.' >&2
  exit 2
fi
export COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml:compose.ci.yaml}"
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

plane="${1:-}"
case "${plane}" in
  core)
    profile="test"
    service="postgres-test"
    admin_user="${POSTGRES_USER:?POSTGRES_USER is required}"
    admin_password="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is required}"
    database="${POSTGRES_TEST_DB:?POSTGRES_TEST_DB is required}"
    schemas="core,platform_runtime,public"
    runtime_role="${CORE_RUNTIME_ROLE:-platform_core_runtime}"
    runtime_password="${CORE_RUNTIME_PASSWORD:?CORE_RUNTIME_PASSWORD is required}"
    positive_table="core.accounts"
    denied_table="core.accounts"
    migration_table="public.platform_migrations"
    ;;
  commercial)
    profile="control-test"
    service="control-postgres-test"
    admin_user="${CONTROL_TEST_USER:?CONTROL_TEST_USER is required}"
    admin_password="${CONTROL_TEST_PASSWORD:?CONTROL_TEST_PASSWORD is required}"
    database="${CONTROL_TEST_DB:?CONTROL_TEST_DB is required}"
    schemas="commercial,public"
    runtime_role="${CONTROL_RUNTIME_ROLE:-platform_commercial_runtime}"
    runtime_password="${CONTROL_RUNTIME_PASSWORD:?CONTROL_RUNTIME_PASSWORD is required}"
    positive_table="commercial.commercial_accounts"
    denied_table="commercial.commercial_accounts"
    migration_table="public.doctrine_migration_versions"
    ;;
  *)
    echo 'Usage: db-role-gate.sh core|commercial' >&2
    exit 2
    ;;
esac

if [[ "${runtime_role}" == "${admin_user}" ]]; then
  echo 'Runtime role must differ from the migration/admin role.' >&2
  exit 1
fi

echo "Provisioning ${plane} runtime role ${runtime_role}..."
docker compose --profile "${profile}" exec -T \
  -e PGHOST=127.0.0.1 \
  -e PGPORT=5432 \
  -e PGADMIN_USER="${admin_user}" \
  -e PGADMIN_PASSWORD="${admin_password}" \
  -e PGDATABASE="${database}" \
  -e SCHEMAS="${schemas}" \
  -e RUNTIME_ROLE="${runtime_role}" \
  -e RUNTIME_PASSWORD="${runtime_password}" \
  -e MIGRATION_ROLE="${admin_user}" \
  "${service}" bash -s < scripts/operations/provision-db-role.sh

runtime_psql() {
  docker compose --profile "${profile}" exec -T \
    -e PGPASSWORD="${runtime_password}" \
    "${service}" psql -X -v ON_ERROR_STOP=1 \
    -h 127.0.0.1 -U "${runtime_role}" -d "${database}" "$@"
}

echo "Verifying runtime role attributes..."
attributes="$(runtime_psql -tA -c "SELECT rolsuper::int || '|' || rolcreatedb::int || '|' || rolcreaterole::int || '|' || rolbypassrls::int FROM pg_roles WHERE rolname = current_user;")"
if [[ "${attributes}" != "0|0|0|0" ]]; then
  echo "Runtime role has elevated attributes: ${attributes}" >&2
  exit 1
fi

echo "Verifying allowed read access..."
runtime_psql -tA -c "SELECT 1 FROM ${positive_table} LIMIT 1;" >/dev/null

expect_denied() {
  local statement="$1"
  if runtime_psql -c "${statement}" >/dev/null 2>&1; then
    echo "Runtime role unexpectedly allowed: ${statement}" >&2
    exit 1
  fi
  echo "Denied as expected: ${statement}"
}

expect_denied "CREATE TABLE ${denied_table%%.*}.runtime_role_should_fail (id int);"
expect_denied "TRUNCATE ${denied_table};"
expect_denied "ALTER TABLE ${denied_table} ADD COLUMN runtime_role_should_fail int;"
expect_denied "DELETE FROM ${migration_table};"
expect_denied "SELECT pg_read_file('/etc/passwd');"

echo "${plane} least-privilege role gate PASS."
