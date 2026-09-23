. (Join-Path $PSScriptRoot "common.ps1")
$ErrorActionPreference = "Stop"

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable

Write-Host "=== Safely Resetting Isolated Test Infrastructure ===" -ForegroundColor Yellow
& (Join-Path $PSScriptRoot "test-stop.ps1")

# Explicitly remove only test volumes (DEV volumes are never touched)
foreach ($volume in @("platform_postgres_test_data", "platform_redis_test_data")) {
    & docker volume inspect $volume *> $null
    if ($LASTEXITCODE -eq 0) {
        & docker volume rm $volume
        if ($LASTEXITCODE -ne 0) { throw "Failed to remove test volume: $volume" }
    }
}

& (Join-Path $PSScriptRoot "test-start.ps1")

Write-Host "Test reset complete." -ForegroundColor Green
