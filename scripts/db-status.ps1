$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "common.ps1")

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable
$Config = Get-PlatformConfig -Root $Root

Write-Host "=== Docker services ===" -ForegroundColor Cyan
docker compose ps
if ($LASTEXITCODE -ne 0) {
    throw "docker compose ps failed"
}

Write-Host ""
Write-Host "=== Core tables ===" -ForegroundColor Cyan

Invoke-PlatformPsqlTable `
    -Root $Root `
    -Config $Config `
    -Sql "SELECT table_name FROM information_schema.tables WHERE table_schema = 'core' AND table_type = 'BASE TABLE' ORDER BY table_name;"

$coreTableCount = Invoke-PlatformPsqlScalar `
    -Root $Root `
    -Config $Config `
    -Sql "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'core' AND table_type = 'BASE TABLE';"

$requiredObjects = Invoke-PlatformPsqlScalar `
    -Root $Root `
    -Config $Config `
    -Sql "SELECT to_regclass('core.accounts') IS NOT NULL AND to_regclass('core.privacy_consents') IS NOT NULL AND to_regclass('core.legal_representations') IS NOT NULL AND to_regclass('platform_runtime.installations') IS NOT NULL AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL;"

if ($requiredObjects -eq 't') {
    Write-Host "Core tables: $coreTableCount; required objects OK" -ForegroundColor Green
}
else {
    Write-Host "Core tables: $coreTableCount; required objects MISSING" -ForegroundColor Red
}

Write-Host ""
Write-Host "=== Applied migrations ===" -ForegroundColor Cyan

$migrationTable = Invoke-PlatformPsqlScalar `
    -Root $Root `
    -Config $Config `
    -Sql "SELECT CASE WHEN to_regclass('public.platform_migrations') IS NULL THEN 'missing' ELSE 'present' END;"

if ($migrationTable -eq "present") {
    Invoke-PlatformPsqlTable `
        -Root $Root `
        -Config $Config `
        -Sql "SELECT migration_name, applied_at FROM public.platform_migrations ORDER BY migration_name;"

    $migrationCount = Invoke-PlatformPsqlScalar `
        -Root $Root `
        -Config $Config `
        -Sql "SELECT count(*) FROM public.platform_migrations;"

    Write-Host "Applied migrations: $migrationCount" -ForegroundColor Green
}
else {
    Write-Host "Migration history table does not exist yet." -ForegroundColor Yellow
}
