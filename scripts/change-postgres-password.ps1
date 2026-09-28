# Backward-compatible entrypoint. The platform role and .env are rotated together.
[CmdletBinding()]
param([switch]$NoRestart)

& (Join-Path $PSScriptRoot 'change-platform-db-password.ps1') -NoRestart:$NoRestart
