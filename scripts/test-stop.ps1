. (Join-Path $PSScriptRoot "common.ps1")
$ErrorActionPreference = "Stop"

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable

Write-Host "=== Stopping Isolated Test Infrastructure ===" -ForegroundColor Cyan
& docker compose --profile test rm --stop --force backend-test db-migrate-test postgres-test redis-test
if ($LASTEXITCODE -ne 0) { throw "Failed to remove isolated test containers" }
