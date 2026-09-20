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

Write-Host "Building Yii3 backend..." -ForegroundColor Cyan
docker compose build backend
if ($LASTEXITCODE -ne 0) {
    throw "Backend Docker build failed"
}

Write-Host "Installing backend Composer dependencies..." -ForegroundColor Cyan
docker compose run --rm --no-deps backend `
    composer install --no-interaction --prefer-dist

if ($LASTEXITCODE -ne 0) {
    throw "Backend composer install failed"
}

Write-Host "Rebuilding Yii configuration..." -ForegroundColor Cyan
docker compose run --rm --no-deps backend `
    composer yii-config-rebuild

if ($LASTEXITCODE -ne 0) {
    throw "Yii config rebuild failed"
}

Write-Host "Starting Yii3 backend..." -ForegroundColor Cyan
docker compose up -d backend

if ($LASTEXITCODE -ne 0) {
    throw "Backend start failed"
}

Write-Host "Checking Yii -> PostgreSQL..." -ForegroundColor Cyan
docker compose exec -T backend php yii db:check

if ($LASTEXITCODE -ne 0) {
    throw "Backend database check failed"
}

$healthUrl = "http://127.0.0.1:$($Config['BACKEND_PORT'])/health"
$deadline = (Get-Date).AddSeconds(60)
$healthy = $false

while ((Get-Date) -lt $deadline) {
    try {
        $health = Invoke-RestMethod `
            -Uri $healthUrl `
            -TimeoutSec 5

        if ($health.status -eq "success" -and $health.data.status -eq "ok") {
            $healthy = $true
            break
        }
    }
    catch {
        Start-Sleep -Seconds 2
    }
}

if (-not $healthy) {
    docker compose logs --tail=100 backend
    throw "Backend health check failed: $healthUrl"
}

Write-Host "Backend health: OK" -ForegroundColor Green

Write-Host ""
Write-Host "=== DBeaver ===" -ForegroundColor Cyan
Write-Host "Host:     localhost"
Write-Host "Port:     $($Config['POSTGRES_PORT'])"
Write-Host "Database: $($Config['POSTGRES_DB'])"
Write-Host "User:     $($Config['POSTGRES_USER'])"
Write-Host "Password: read POSTGRES_PASSWORD from $(Join-Path $Root '.env')"
Write-Host ""
Write-Host "Platform backend is ready." -ForegroundColor Green
Write-Host "Health: $healthUrl" -ForegroundColor Green
