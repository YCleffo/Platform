. (Join-Path $PSScriptRoot "common.ps1")
$ErrorActionPreference = "Continue"

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable

Write-Host "=== Starting Isolated Test Infrastructure ===" -ForegroundColor Cyan
& docker compose --profile test up -d postgres-test redis-test
if ($LASTEXITCODE -ne 0) { throw "Failed to start postgres-test and redis-test" }

Write-Host "Applying migrations to platform_test..." -ForegroundColor Cyan
& docker compose --profile test run --rm db-migrate-test
if ($LASTEXITCODE -ne 0) { throw "Failed to run db-migrate-test" }

Write-Host "Starting backend-test..." -ForegroundColor Cyan
& docker compose --profile test up -d backend-test
if ($LASTEXITCODE -ne 0) { throw "Failed to start backend-test" }

Write-Host "Test infrastructure is ready." -ForegroundColor Green
