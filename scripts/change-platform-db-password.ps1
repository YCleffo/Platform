[CmdletBinding()]
param([switch]$NoRestart)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

& (Join-Path $PSScriptRoot 'rotate-db-password.ps1') `
    -DbService postgres `
    -DbKey POSTGRES_DB `
    -UserKey POSTGRES_USER `
    -PasswordKey POSTGRES_PASSWORD `
    -AppService backend `
    -CheckCommand @('php', 'yii', 'db:check') `
    -LinkedDbService postgres-test `
    -LinkedVolume platform_postgres_test_data `
    -LinkedAppService backend-test `
    -NoRestart:$NoRestart
