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
MAINTENANCE_ROLE="${MAINTENANCE_ROLE:-}"
MAINTENANCE_PASSWORD="${MAINTENANCE_PASSWORD:-}"
if [[ -n "${MAINTENANCE_ROLE}" || -n "${MAINTENANCE_PASSWORD}" ]]; then
  : "${MAINTENANCE_ROLE:?Both maintenance role and password are required}"
  : "${MAINTENANCE_PASSWORD:?Both maintenance role and password are required}"
  if [[ "${MAINTENANCE_ROLE}" == "${RUNTIME_ROLE}" || "${MAINTENANCE_ROLE}" == "${MIGRATION_ROLE}" || "${MAINTENANCE_ROLE}" == "${PGADMIN_USER}" || "${MAINTENANCE_PASSWORD}" == "${RUNTIME_PASSWORD}" || "${MAINTENANCE_PASSWORD}" == "${PGADMIN_PASSWORD:-}" ]]; then
    echo 'Maintenance, runtime and owner credentials must be distinct.' >&2; exit 1
  fi
fi

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

for candidate_role in "${RUNTIME_ROLE}" "${MAINTENANCE_ROLE}"; do
  [[ -z "${candidate_role}" ]] && continue
  owned="$(psql_admin -tA -v candidate_role="${candidate_role}" <<'SQL'
SELECT EXISTS (
  SELECT 1 FROM pg_roles r WHERE r.rolname = :'candidate_role' AND (
    EXISTS (SELECT 1 FROM pg_database d WHERE d.datname = current_database() AND d.datdba = r.oid)
    OR EXISTS (SELECT 1 FROM pg_namespace n WHERE n.nspowner = r.oid)
    OR EXISTS (SELECT 1 FROM pg_class c WHERE c.relowner = r.oid)
    OR EXISTS (SELECT 1 FROM pg_proc p WHERE p.proowner = r.oid)
    OR EXISTS (SELECT 1 FROM pg_type t WHERE t.typowner = r.oid)
  )
);
SQL
)"
  if [[ "${owned}" != "f" ]]; then
    echo "Refusing an application role that already owns database objects: ${candidate_role}" >&2; exit 1
  fi
done

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

# PUBLIC privileges are effective privileges too; revoking a direct grant alone is insufficient.
psql_admin -v migration_role="${MIGRATION_ROLE}" -v runtime_role="${RUNTIME_ROLE}" -v maintenance_role="${MAINTENANCE_ROLE}" <<'SQL'
SELECT format('REVOKE CREATE ON DATABASE %I FROM PUBLIC', current_database())
\gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC', :'migration_role')
\gexec
-- Clear legacy global grants before adding the reviewed per-schema defaults below.
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I REVOKE ALL ON %s FROM %I', :'migration_role', object_type, grantee)
FROM (VALUES ('TABLES'), ('SEQUENCES'), ('FUNCTIONS')) objects(object_type)
CROSS JOIN (VALUES (:'runtime_role'), (:'maintenance_role')) roles(grantee)
WHERE grantee <> '' AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname=grantee)
\gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I REVOKE ALL ON %s FROM PUBLIC', :'migration_role', object_type)
FROM (VALUES ('TABLES'), ('SEQUENCES')) objects(object_type)
\gexec
SQL

IFS=',' read -r -a schema_list <<< "${SCHEMAS}"
for schema in "${schema_list[@]}"; do
  schema="$(echo "${schema}" | xargs)"
  [[ -z "${schema}" ]] && continue
  psql_admin -v schema="${schema}" -v migration_role="${MIGRATION_ROLE}" -v runtime_role="${RUNTIME_ROLE}" -v maintenance_role="${MAINTENANCE_ROLE}" <<'SQL'
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I REVOKE ALL ON %s FROM %I', :'migration_role', :'schema', object_type, grantee)
FROM (VALUES ('TABLES'), ('SEQUENCES'), ('FUNCTIONS')) objects(object_type)
CROSS JOIN (VALUES (:'runtime_role'), (:'maintenance_role')) roles(grantee)
WHERE grantee <> '' AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname=grantee)
\gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I REVOKE ALL ON %s FROM PUBLIC', :'migration_role', :'schema', object_type)
FROM (VALUES ('TABLES'), ('SEQUENCES'), ('FUNCTIONS')) objects(object_type)
\gexec
SQL
  psql_admin -v schema="${schema}" -v runtime_role="${RUNTIME_ROLE}" <<'SQL'
SELECT format('REVOKE CREATE ON SCHEMA %I FROM PUBLIC', :'schema')
\gexec
SELECT format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM PUBLIC', :'schema')
\gexec
SELECT format('REVOKE SELECT (%1$s), INSERT (%1$s), UPDATE (%1$s), REFERENCES (%1$s) ON TABLE %2$s FROM %3$I, PUBLIC',
  string_agg(format('%I', a.attname), ', '), c.oid::regclass, :'runtime_role')
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_attribute a ON a.attrelid = c.oid
WHERE n.nspname = :'schema' AND c.relkind IN ('r','p','v','m','f') AND a.attnum > 0 AND NOT a.attisdropped
GROUP BY c.oid
\gexec
SELECT format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA %I FROM PUBLIC', :'schema')
\gexec
SELECT format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA %I FROM %I', :'schema', :'runtime_role')
\gexec
-- Never grant unreviewed SECURITY DEFINER functions to application processes.
SELECT format('GRANT EXECUTE ON FUNCTION %s TO %I', p.oid::regprocedure, :'runtime_role')
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = :'schema' AND NOT p.prosecdef AND p.prokind = 'f'
\gexec
SQL
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
SELECT format('GRANT EXECUTE ON FUNCTION %s TO %I', p.oid::regprocedure, :'runtime_role')
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = :'schema' AND NOT p.prosecdef AND p.prokind = 'f'
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
  'ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I REVOKE EXECUTE ON FUNCTIONS FROM %I',
   :'migration_role', :'schema', :'runtime_role'
)
\gexec
SQL
done

# Audit retention is a separate scheduler capability. The HTTP role cannot enable it with a GUC.
for schema in "${schema_list[@]}"; do
  schema="$(echo "${schema}" | xargs)"
  [[ "${schema}" != "core" && "${schema}" != "commercial" ]] && continue
  psql_admin -v schema="${schema}" -v runtime_role="${RUNTIME_ROLE}" <<'SQL'
SELECT format('REVOKE UPDATE, DELETE, TRUNCATE ON %I.audit_events FROM %I, PUBLIC', :'schema', :'runtime_role')
\gexec
SELECT format('REVOKE UPDATE (%s) ON %I.audit_events FROM %I, PUBLIC',
  string_agg(format('%I', a.attname), ', '), :'schema', :'runtime_role')
FROM pg_attribute a
WHERE a.attrelid = to_regclass(format('%I.audit_events', :'schema')) AND a.attnum > 0 AND NOT a.attisdropped
\gexec
SQL
done

if [[ -n "${MAINTENANCE_ROLE}" ]]; then
  psql_admin -v maintenance_role="${MAINTENANCE_ROLE}" -v runtime_role="${RUNTIME_ROLE}" <<'SQL'
\getenv maintenance_password MAINTENANCE_PASSWORD
SELECT format('REVOKE %I FROM %I', granted.rolname, :'maintenance_role')
FROM pg_auth_members m JOIN pg_roles granted ON granted.oid = m.roleid JOIN pg_roles member ON member.oid = m.member
WHERE member.rolname = :'maintenance_role'
\gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION INHERIT', :'maintenance_role', :'maintenance_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'maintenance_role')
\gexec
SELECT format('ALTER ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION INHERIT', :'maintenance_role', :'maintenance_password')
\gexec
SELECT format('GRANT %I TO %I', :'runtime_role', :'maintenance_role')
\gexec
SQL
  for schema in "${schema_list[@]}"; do
    schema="$(echo "${schema}" | xargs)"
    [[ -z "${schema}" ]] && continue
    psql_admin -v schema="${schema}" -v maintenance_role="${MAINTENANCE_ROLE}" <<'SQL'
SELECT format('REVOKE ALL ON DATABASE %I FROM %I', current_database(), :'maintenance_role')
\gexec
SELECT format('REVOKE ALL ON SCHEMA %I FROM %I', :'schema', :'maintenance_role')
\gexec
SELECT format('REVOKE ALL ON ALL TABLES IN SCHEMA %I FROM %I', :'schema', :'maintenance_role')
\gexec
SELECT format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA %I FROM %I', :'schema', :'maintenance_role')
\gexec
SELECT format('REVOKE ALL ON ALL FUNCTIONS IN SCHEMA %I FROM %I', :'schema', :'maintenance_role')
\gexec
SELECT format('REVOKE SELECT (%1$s), INSERT (%1$s), UPDATE (%1$s), REFERENCES (%1$s) ON TABLE %2$s FROM %3$I',
  string_agg(format('%I', a.attname), ', '), c.oid::regclass, :'maintenance_role')
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_attribute a ON a.attrelid = c.oid
WHERE n.nspname = :'schema' AND c.relkind IN ('r','p','v','m','f') AND a.attnum > 0 AND NOT a.attisdropped
GROUP BY c.oid
\gexec
-- SELECT FOR UPDATE used by the existing retention batches requires UPDATE on a column.
-- The append-only trigger still rejects actual updates, including id=id.
SELECT format('GRANT DELETE, UPDATE(id) ON %I.audit_events TO %I', :'schema', :'maintenance_role')
WHERE :'schema' IN ('core','commercial') AND to_regclass(format('%I.audit_events', :'schema')) IS NOT NULL
\gexec
SQL
  done
fi

echo "Runtime role ${RUNTIME_ROLE} provisioned on ${PGDATABASE} for schemas ${SCHEMAS}."
