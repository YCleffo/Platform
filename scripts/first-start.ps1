$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "common.ps1")

$Root = Get-PlatformRoot
Set-Location $Root

Write-Host ""
Write-Host "=== Platform Phase 1: first start ===" -ForegroundColor Cyan
Write-Host "Root: $Root"
Write-Host ""

Assert-DockerAvailable
$Config = Get-PlatformConfig -Root $Root

Write-Host "Pulling PostgreSQL/Redis images..." -ForegroundColor Cyan
docker compose pull postgres redis db-migrate
if ($LASTEXITCODE -ne 0) {
    throw "docker compose pull failed"
}

Write-Host "Starting PostgreSQL and Redis..." -ForegroundColor Cyan
docker compose up -d postgres redis
if ($LASTEXITCODE -ne 0) {
    throw "docker compose up failed"
}

Write-Host "Applying Core v1 migrations..." -ForegroundColor Cyan
docker compose run --rm db-migrate
if ($LASTEXITCODE -ne 0) {
    throw "Core migration failed"
}

Write-Host ""
Write-Host "=== Container status ===" -ForegroundColor Cyan
docker compose ps
if ($LASTEXITCODE -ne 0) {
    throw "docker compose ps failed"
}

Write-Host ""
Write-Host "=== Core validation ===" -ForegroundColor Cyan

$coreTableCount = Invoke-PlatformPsqlScalar `
    -Root $Root `
    -Config $Config `
    -Sql "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'core' AND table_type = 'BASE TABLE';"

if ([string]::IsNullOrWhiteSpace($coreTableCount)) {
    throw "PostgreSQL returned an empty Core table count."
}

if ($coreTableCount -ne "23") {
    throw "Core validation failed. Expected 23 tables, found $coreTableCount."
}

$migrationCount = Invoke-PlatformPsqlScalar `
    -Root $Root `
    -Config $Config `
    -Sql "SELECT count(*) FROM public.platform_migrations;"

Write-Host "OK: core.* contains exactly 23 tables." -ForegroundColor Green
Write-Host "Applied Phase 1 migrations: $migrationCount" -ForegroundColor Green

Write-Host ""
Write-Host "=== DBeaver ===" -ForegroundColor Cyan
Write-Host "Host:     localhost"
Write-Host "Port:     $($Config['POSTGRES_PORT'])"
Write-Host "Database: $($Config['POSTGRES_DB'])"
Write-Host "User:     $($Config['POSTGRES_USER'])"
Write-Host "Password: read POSTGRES_PASSWORD from C:\Platform\.env"
Write-Host ""
Write-Host "Phase 1 database is ready." -ForegroundColor Green
