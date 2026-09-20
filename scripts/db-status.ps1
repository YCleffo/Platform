$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $Root

Write-Host "=== Docker services ===" -ForegroundColor Cyan
docker compose ps

Write-Host ""
Write-Host "=== Core tables ===" -ForegroundColor Cyan
docker compose exec -T postgres sh -lc `
  'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT table_name FROM information_schema.tables WHERE table_schema=''core'' AND table_type=''BASE TABLE'' ORDER BY table_name;"'

Write-Host ""
Write-Host "=== Applied migrations ===" -ForegroundColor Cyan
docker compose exec -T postgres sh -lc `
  'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT migration_name, applied_at FROM public.platform_migrations ORDER BY migration_name;"'
