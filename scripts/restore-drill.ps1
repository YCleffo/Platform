[CmdletBinding()]
param([string]$ManifestPath, [string]$DeploymentId)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$root = Get-PlatformRoot
Set-Location $root
Assert-DockerAvailable
[void](Get-PlatformConfig -Root $root)

$arguments = @('compose', '--profile', 'control', '--profile', 'backup',
    'run', '--rm', '--no-deps', 'backup-worker')
if ($DeploymentId) {
    if ($DeploymentId -cnotmatch '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$') { throw 'Invalid DeploymentId.' }
    $arguments += @('drill-deployment', $DeploymentId)
} else { $arguments += 'drill' }
if (-not [string]::IsNullOrWhiteSpace($ManifestPath)) {
    $name = [IO.Path]::GetFileName($ManifestPath)
    if ($name -ne $ManifestPath -and -not (Test-Path -LiteralPath $ManifestPath -PathType Leaf)) {
        throw 'Manifest file was not found.'
    }
    if ($name -notmatch '^[0-9a-f-]{36}\.json$') { throw 'Invalid manifest file name.' }
    if ($DeploymentId) { $arguments += $name } else { $arguments += @('--manifest', $name) }
}
& docker @arguments
if ($LASTEXITCODE -ne 0) { throw 'Restore drill failed.' }
