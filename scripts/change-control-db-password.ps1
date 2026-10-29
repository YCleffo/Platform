[CmdletBinding()]
param([switch]$NoRestart)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

& (Join-Path $PSScriptRoot 'rotate-db-password.ps1') `
    -DbService control-postgres `
    -DbKey CONTROL_POSTGRES_DB `
    -UserKey CONTROL_POSTGRES_USER `
    -PasswordKey CONTROL_POSTGRES_PASSWORD `
    -AppService control-plane `
    -CheckCommand @('php', 'bin/console', 'doctrine:migrations:status', '--no-interaction') `
    -AdditionalAppServices @('control-outbox-worker', 'control-scheduler') `
    -NoRestart:$NoRestart
