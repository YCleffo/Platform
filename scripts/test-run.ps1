$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable

& (Join-Path $PSScriptRoot "test-start.ps1")

Write-Host "=== Running Codeception tests inside backend-test ===" -ForegroundColor Cyan
& docker compose --profile test exec -T backend-test composer test $args
if ($LASTEXITCODE -ne 0) { throw "Tests failed" }
