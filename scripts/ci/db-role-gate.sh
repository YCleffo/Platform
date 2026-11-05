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
    maintenance_role="${CORE_MAINTENANCE_ROLE:-platform_core_maintenance}"
    maintenance_password="${CORE_MAINTENANCE_PASSWORD:?CORE_MAINTENANCE_PASSWORD is required}"
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
    maintenance_role="${CONTROL_MAINTENANCE_ROLE:-platform_commercial_maintenance}"
    maintenance_password="${CONTROL_MAINTENANCE_PASSWORD:?CONTROL_MAINTENANCE_PASSWORD is required}"
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
  -e MAINTENANCE_ROLE="${maintenance_role}" \
  -e MAINTENANCE_PASSWORD="${maintenance_password}" \
  -e MIGRATION_ROLE="${admin_user}" \
  "${service}" bash -s < scripts/operations/provision-db-role.sh

runtime_psql() {
  docker compose --profile "${profile}" exec -T \
    -e PGPASSWORD="${runtime_password}" \
    "${service}" psql -X -v ON_ERROR_STOP=1 -v VERBOSITY=verbose \
    -h 127.0.0.1 -U "${runtime_role}" -d "${database}" "$@"
}

echo "Verifying runtime role attributes..."
attributes="$(runtime_psql -tA -c "SELECT rolsuper::int || '|' || rolcreatedb::int || '|' || rolcreaterole::int || '|' || rolbypassrls::int FROM pg_roles WHERE rolname = current_user;")"
if [[ "${attributes}" != "0|0|0|0" ]]; then
  echo "Runtime role has elevated attributes: ${attributes}" >&2
  exit 1
fi

schema="${denied_table%%.*}"
check_privilege() {
  local label="$1" expression="$2" expected="$3" actual
  actual="$(runtime_psql -tA -c "SELECT (${expression})::text;")"
  if [[ "${actual}" != "${expected}" ]]; then
    echo "Privilege check failed for ${label}: expected ${expected}, got ${actual}" >&2
    exit 1
  fi
  echo "Privilege ${label}: ${actual}"
}

echo "Verifying effective privileges..."
check_privilege "schema usage" "has_schema_privilege(current_user, '${schema}', 'USAGE')" "true"
check_privilege "schema create" "has_schema_privilege(current_user, '${schema}', 'CREATE')" "false"
check_privilege "database create" "has_database_privilege(current_user, current_database(), 'CREATE')" "false"
check_privilege "application select" "has_table_privilege(current_user, '${positive_table}', 'SELECT')" "true"
check_privilege "application truncate" "has_table_privilege(current_user, '${denied_table}', 'TRUNCATE')" "false"
check_privilege "migration delete" "has_table_privilege(current_user, '${migration_table}', 'DELETE')" "false"
check_privilege "migration truncate" "has_table_privilege(current_user, '${migration_table}', 'TRUNCATE')" "false"

echo "Verifying allowed read access..."
runtime_psql -tA -c "SELECT 1 FROM ${positive_table} LIMIT 1;" >/dev/null

expect_denied() {
  local statement="$1" output
  if output="$(runtime_psql -c 'BEGIN' -c "${statement}" -c 'ROLLBACK' 2>&1)"; then
    echo "Runtime role unexpectedly allowed: ${statement}" >&2
    exit 1
  fi
  if ! grep -Eq 'ERROR:[[:space:]]+42501:' <<< "${output}"; then
    echo "Expected SQLSTATE 42501 for '${statement}', got:" >&2
    printf '%s\n' "${output}" >&2
    exit 1
  fi
  echo "Denied with SQLSTATE 42501: ${statement}"
}

expect_denied "CREATE TABLE ${denied_table%%.*}.runtime_role_should_fail (id int);"
expect_denied "TRUNCATE ${denied_table};"
expect_denied "ALTER TABLE ${denied_table} ADD COLUMN runtime_role_should_fail int;"
expect_denied "DELETE FROM ${migration_table};"
expect_denied "SELECT pg_read_file('/etc/passwd');"
expect_denied "SET LOCAL app.privacy_lifecycle = 'on'; DELETE FROM ${schema}.audit_events WHERE false;"
expect_denied "UPDATE ${schema}.audit_events SET id=DEFAULT WHERE false;"
expect_denied "CREATE TABLE public.runtime_role_should_fail(id int);"
expect_denied "SET ROLE ${admin_user};"
expect_denied "SET ROLE ${maintenance_role};"
expect_denied "CREATE ROLE runtime_role_should_fail;"
check_privilege "audit insert" "has_table_privilege(current_user, '${schema}.audit_events', 'INSERT')" "true"
check_privilege "audit delete" "has_table_privilege(current_user, '${schema}.audit_events', 'DELETE')" "false"
check_privilege "audit any column update" "has_any_column_privilege(current_user, '${schema}.audit_events', 'UPDATE')" "false"
docker compose --profile "${profile}" exec -T -e PGPASSWORD="${maintenance_password}" "${service}" \
  psql -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -U "${maintenance_role}" -d "${database}" \
  -c "BEGIN; SELECT id FROM ${schema}.audit_events WHERE false FOR UPDATE SKIP LOCKED; SET LOCAL app.privacy_lifecycle='on'; DELETE FROM ${schema}.audit_events WHERE false; ROLLBACK;"
docker compose --profile "${profile}" exec -T -e PGPASSWORD="${maintenance_password}" "${service}" \
  psql -X -v ON_ERROR_STOP=1 -v schema="${schema}" -h 127.0.0.1 -U "${maintenance_role}" -d "${database}" \
  < scripts/ci/db-maintenance-probe.sql

echo "${plane} least-privilege role gate PASS."
