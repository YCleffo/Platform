. (Join-Path $PSScriptRoot "common.ps1")
$ErrorActionPreference = "Continue"

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable

Write-Host "=== Stopping Isolated Test Infrastructure ===" -ForegroundColor Cyan
& docker compose --profile test stop backend-test db-migrate-test postgres-test redis-test
