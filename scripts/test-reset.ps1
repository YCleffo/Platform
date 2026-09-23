. (Join-Path $PSScriptRoot "common.ps1")
$ErrorActionPreference = "Continue"

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable

Write-Host "=== Safely Resetting Isolated Test Infrastructure ===" -ForegroundColor Yellow
& docker compose --profile test stop backend-test db-migrate-test postgres-test redis-test 2>$null
& docker compose --profile test rm -f -v backend-test db-migrate-test postgres-test redis-test 2>$null

# Explicitly remove only test volumes (DEV volumes are never touched)
& docker volume rm -f platform_postgres_test_data platform_redis_test_data 2>$null

& (Join-Path $PSScriptRoot "test-start.ps1")

Write-Host "Test reset complete." -ForegroundColor Green
