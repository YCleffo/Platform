[CmdletBinding()]
param([ValidateSet('Core', 'Control', 'All', 'Deployment', 'Deployments')][string]$Target = 'All',
    [string]$DeploymentId)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$root = Get-PlatformRoot
Set-Location $root
Assert-DockerAvailable
[void](Get-PlatformConfig -Root $root)

# The same shell entrypoint runs the automatic worker and manual backup.
if ($Target -eq 'Deployment') {
    if ($DeploymentId -cnotmatch '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$') { throw 'Valid DeploymentId required.' }
    & docker compose --profile control --profile backup run --rm --no-deps backup-worker deployment $DeploymentId
} elseif ($Target -eq 'Deployments') {
    & docker compose --profile control --profile backup run --rm --no-deps backup-worker deployments
} else {
    & docker compose --profile control --profile backup run --rm --no-deps `
        backup-worker once --target $Target.ToLowerInvariant()
}
if ($LASTEXITCODE -ne 0) { throw 'Backup set creation failed.' }
