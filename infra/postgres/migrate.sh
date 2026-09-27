#!/usr/bin/env bash
set -Eeuo pipefail

# Tiny deterministic migration runner used only for the first database phase.
# It intentionally keeps migration history in PUBLIC, not CORE, so that
# core.* contains exactly 24 business/platform tables after migration 017.

export PGPASSWORD="${POSTGRES_PASSWORD}"

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
    'Technical migration history. Not part of the 24-table core schema.';
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

# Guardrail: Core must contain exactly 24 base tables.
core_count="$("${psql_base[@]}" --tuples-only --no-align --command="
SELECT count(*)
FROM information_schema.tables
WHERE table_schema = 'core' AND table_type = 'BASE TABLE';")"

echo "[db-migrate] core.* tables: $core_count"

if [ "$core_count" != "24" ]; then
  echo "[db-migrate] ERROR: expected exactly 24 core tables, got $core_count"
  exit 2
fi

# Runtime tables are checked separately and never enter the Core count.
runtime_count="$("${psql_base[@]}" --tuples-only --no-align --command="
SELECT count(*)
FROM information_schema.tables
WHERE table_schema = 'platform_runtime'
  AND table_type = 'BASE TABLE'
  AND table_name IN ('installations', 'entitlement_documents');")"

echo "[db-migrate] platform_runtime tables: $runtime_count"

if [ "$runtime_count" != "2" ]; then
  echo "[db-migrate] ERROR: expected both platform_runtime tables, got $runtime_count"
  exit 3
fi
