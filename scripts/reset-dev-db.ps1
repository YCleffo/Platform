param(
    [switch]$Yes
)

$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $Root

Write-Host "WARNING: this removes the LOCAL PostgreSQL and Redis Docker volumes." -ForegroundColor Red
Write-Host "All local development data will be destroyed." -ForegroundColor Red

if (-not $Yes) {
    $answer = Read-Host "Type RESET to continue"
    if ($answer -ne "RESET") {
        Write-Host "Cancelled."
        exit 0
    }
}

docker compose down -v
if ($LASTEXITCODE -ne 0) { throw "docker compose down -v failed" }

& (Join-Path $PSScriptRoot "first-start.ps1")
