#!/usr/bin/env bash
# Provision a least-privilege runtime role for one Platform database.
#
# Required environment:
#   PGHOST, PGPORT, PGADMIN_USER, PGDATABASE, SCHEMAS
#   RUNTIME_ROLE, RUNTIME_PASSWORD
#
# The migration/owner connection is a separate credential and is never used by
# web or worker processes. Run this script as the database administrator against
# a new installation, then point only the application at RUNTIME_ROLE.
set -Eeuo pipefail

: "${PGHOST:?PGHOST is required}"
: "${PGPORT:?PGPORT is required}"
: "${PGADMIN_USER:?PGADMIN_USER is required}"
: "${PGDATABASE:?PGDATABASE is required}"
: "${SCHEMAS:?SCHEMAS is required (comma-separated)}"
: "${RUNTIME_ROLE:?RUNTIME_ROLE is required}"
: "${RUNTIME_PASSWORD:?RUNTIME_PASSWORD is required}"
MIGRATION_ROLE="${MIGRATION_ROLE:-${PGADMIN_USER}}"

if [[ "${PGADMIN_USER}" == "${RUNTIME_ROLE}" ]]; then
  echo "RUNTIME_ROLE must differ from the administration role." >&2
  exit 1
fi
if [[ "${MIGRATION_ROLE}" == "${RUNTIME_ROLE}" ]]; then
  echo "RUNTIME_ROLE must differ from MIGRATION_ROLE." >&2
  exit 1
fi
if [[ "${RUNTIME_PASSWORD}" == "${PGADMIN_PASSWORD:-}" ]]; then
  echo "RUNTIME_PASSWORD must differ from the administration password." >&2
  exit 1
fi

psql_admin() {
  PGPASSWORD="${PGADMIN_PASSWORD:-}" psql \
    -X -v ON_ERROR_STOP=1 \
    -h "${PGHOST}" -p "${PGPORT}" -U "${PGADMIN_USER}" -d "${PGDATABASE}" "$@"
}

psql_admin -v runtime_role="${RUNTIME_ROLE}" <<'SQL'
\getenv runtime_password RUNTIME_PASSWORD

SELECT format(
  'REVOKE %I FROM %I', granted.rolname, :'runtime_role'
)
FROM pg_auth_members membership
JOIN pg_roles granted ON granted.oid = membership.roleid
JOIN pg_roles member ON member.oid = membership.member
WHERE member.rolname = :'runtime_role'
\gexec

SELECT format(
  'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION',
  :'runtime_role', :'runtime_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'runtime_role')
\gexec

SELECT format(
  'ALTER ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION',
  :'runtime_role', :'runtime_password'
)
\gexec

SELECT format('REVOKE ALL ON DATABASE %I FROM %I', current_database(), :'runtime_role')
\gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'runtime_role')
\gexec
SQL

IFS=',' read -r -a schema_list <<< "${SCHEMAS}"
for schema in "${schema_list[@]}"; do
  schema="$(echo "${schema}" | xargs)"
  [[ -z "${schema}" ]] && continue
  if [[ "${schema}" == "public" ]]; then
    # public holds migration history and must remain read-only for the runtime.
    psql_admin -v runtime_role="${RUNTIME_ROLE}" -v migration_role="${MIGRATION_ROLE}" -v schema="${schema}" <<'SQL'
SELECT format('REVOKE ALL ON SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format('GRANT USAGE ON SCHEMA %I TO %I', :'schema', :'runtime_role')
\gexec
SELECT format('REVOKE CREATE ON SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format('GRANT SELECT ON ALL TABLES IN SCHEMA %I TO %I', :'schema', :'runtime_role')
\gexec
SELECT format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format(
  'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I GRANT SELECT ON TABLES TO %I',
  :'migration_role', :'schema', :'runtime_role'
)
\gexec
SQL
    continue
  fi
  psql_admin -v runtime_role="${RUNTIME_ROLE}" -v migration_role="${MIGRATION_ROLE}" -v schema="${schema}" <<'SQL'
SELECT format('REVOKE ALL ON SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format('GRANT USAGE ON SCHEMA %I TO %I', :'schema', :'runtime_role')
\gexec
SELECT format('REVOKE CREATE ON SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %I TO %I', :'schema', :'runtime_role')
\gexec
SELECT format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA %I TO %I', :'schema', :'runtime_role')
\gexec
SELECT format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
SELECT format('GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA %I TO %I', :'schema', :'runtime_role')
\gexec
SELECT format(
  'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I',
  :'migration_role', :'schema', :'runtime_role'
)
\gexec
SELECT format(
  'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I GRANT USAGE, SELECT ON SEQUENCES TO %I',
   :'migration_role', :'schema', :'runtime_role'
)
\gexec
SELECT format(
  'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I GRANT EXECUTE ON FUNCTIONS TO %I',
   :'migration_role', :'schema', :'runtime_role'
)
\gexec
SQL
done

echo "Runtime role ${RUNTIME_ROLE} provisioned on ${PGDATABASE} for schemas ${SCHEMAS}."
