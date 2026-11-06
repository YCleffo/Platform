[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$sql = Join-Path $root 'scripts\dev\seed-demo-catalog.sql'

Push-Location $root
try {
    $command = 'docker compose --profile control exec -T control-postgres psql ' +
        '-U platform_control -d platform_commercial -v ON_ERROR_STOP=1 < "' + $sql + '"'
    & cmd.exe /d /c $command
    if ($LASTEXITCODE -ne 0) { throw "Demo catalog seed exited with $LASTEXITCODE" }
    Write-Output 'Demo catalog seeded. Open /plans or the account Billing tab.'
} finally {
    Pop-Location
}
