#!/usr/bin/env bash
set -Eeuo pipefail

# Deterministic migration runner. Migration history lives in PUBLIC, not CORE.

if [[ -n "${POSTGRES_PASSWORD_FILE:-}" ]]; then
  [[ "${POSTGRES_PASSWORD_FILE}" == /run/provisioning-secrets/db_password && -r "${POSTGRES_PASSWORD_FILE}" ]] || exit 1
  export PGPASSWORD="$(cat "${POSTGRES_PASSWORD_FILE}")"
else
  export PGPASSWORD="${POSTGRES_PASSWORD}"
fi

psql_base=(
  psql
  --host="${PGHOST:-postgres}"
  --port="${PGPORT:-5432}"
  --username="${POSTGRES_USER}"
  --dbname="${POSTGRES_DB}"
  --set=ON_ERROR_STOP=1
)

echo "[db-migrate] Waiting for PostgreSQL..."
until pg_isready \
  --host="${PGHOST:-postgres}" \
  --port="${PGPORT:-5432}" \
  --username="${POSTGRES_USER}" \
  --dbname="${POSTGRES_DB}" >/dev/null 2>&1; do
  sleep 1
done

"${psql_base[@]}" <<'SQL'
CREATE TABLE IF NOT EXISTS public.platform_migrations (
    migration_name text PRIMARY KEY,
    applied_at timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE public.platform_migrations IS
    'Technical migration history. Not part of the core schema.';
SQL

shopt -s nullglob
files=(/migrations/*.sql)

if [ ${#files[@]} -eq 0 ]; then
  echo "[db-migrate] ERROR: no migration files found in /migrations"
  exit 1
fi

for file in "${files[@]}"; do
  name="$(basename "$file")"

  applied="$("${psql_base[@]}" --tuples-only --no-align \
    --command="SELECT 1 FROM public.platform_migrations WHERE migration_name = '$name' LIMIT 1;")"

  if [ "$applied" = "1" ]; then
    echo "[db-migrate] SKIP  $name"
    continue
  fi

  echo "[db-migrate] APPLY $name"

  # Apply the migration AND write its history row in one psql transaction.
  # If anything fails, both schema changes and the history insert are rolled back.
  "${psql_base[@]}" \
    --single-transaction \
    --file="$file" \
    --command="INSERT INTO public.platform_migrations(migration_name) VALUES ('$name');"
done

echo "[db-migrate] Done."

core_count="$("${psql_base[@]}" --tuples-only --no-align --command="
SELECT count(*)
FROM information_schema.tables
WHERE table_schema = 'core' AND table_type = 'BASE TABLE';")"

echo "[db-migrate] core.* tables: $core_count"

schema_ready="$("${psql_base[@]}" --tuples-only --no-align --command="
SELECT to_regclass('core.accounts') IS NOT NULL
   AND to_regclass('core.privacy_consents') IS NOT NULL
   AND to_regclass('core.legal_representations') IS NOT NULL
   AND to_regclass('core.legal_representation_scopes') IS NOT NULL
   AND to_regclass('platform_runtime.installations') IS NOT NULL
   AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL;")"
if [ "$schema_ready" != "t" ]; then
  echo "[db-migrate] ERROR: required Core or runtime objects are missing"
  exit 3
fi
echo "[db-migrate] required Core and runtime objects: OK"
