param(
    [switch]$Yes
)

$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "common.ps1")

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable
Get-PlatformConfig -Root $Root | Out-Null

Write-Host "WARNING: this removes LOCAL PostgreSQL and Redis Docker volumes." -ForegroundColor Red
Write-Host "All local development data will be destroyed." -ForegroundColor Red
Write-Host ""

if (-not $Yes) {
    $answer = Read-Host "Type RESET to continue"

    if ($answer -ne "RESET") {
        Write-Host "Cancelled." -ForegroundColor Yellow
        exit 0
    }
}

Write-Host "Removing local containers, network and volumes..." -ForegroundColor Cyan
docker compose down -v --remove-orphans
if ($LASTEXITCODE -ne 0) {
    throw "docker compose down -v failed"
}

Write-Host ""
Write-Host "Recreating Platform Phase 1 from zero..." -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "first-start.ps1")

if ($LASTEXITCODE -ne 0) {
    throw "first-start.ps1 failed after database reset"
}
