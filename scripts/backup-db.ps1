[CmdletBinding()]
param([ValidateSet('Core', 'Control', 'All')][string]$Target = 'All')

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$root = Get-PlatformRoot
Set-Location $root
Assert-DockerAvailable
[void](Get-PlatformConfig -Root $root)

# The same shell entrypoint runs the automatic worker and manual backup.
& docker compose --profile control --profile backup run --rm --no-deps `
    backup-worker once --target $Target.ToLowerInvariant()
if ($LASTEXITCODE -ne 0) { throw 'Backup set creation failed.' }
