[CmdletBinding()]
param(
    [ValidateSet('Core', 'Control')][string]$Target,
    [string]$DumpPath,
    [switch]$Force,
    [switch]$AllowLiveRestore,
    [switch]$ConfirmProductionRestore
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Refuse before probing Docker or selecting any implicit backup.
if (-not $PSBoundParameters.ContainsKey('Target') -or
    -not $PSBoundParameters.ContainsKey('DumpPath') -or
    [string]::IsNullOrWhiteSpace($DumpPath) -or
    -not $Force -or -not $AllowLiveRestore) {
    throw 'Live restore requires explicit -Target, -DumpPath, -Force and -AllowLiveRestore.'
}

. (Join-Path $PSScriptRoot 'common.ps1')
$root = Get-PlatformRoot
Set-Location $root
$config = Get-PlatformConfig -Root $root

if ($Target -eq 'Core') {
    $service = 'postgres'
    $database = $config['POSTGRES_DB']
    $user = $config['POSTGRES_USER']
    $environment = $config['BACKEND_APP_ENV']
} else {
    $service = 'control-postgres'
    $database = $config['CONTROL_POSTGRES_DB']
    $user = $config['CONTROL_POSTGRES_USER']
    $environment = $config['CONTROL_APP_ENV']
}
if ([string]::IsNullOrWhiteSpace($database) -or [string]::IsNullOrWhiteSpace($user)) {
    throw 'Database target is not configured.'
}
if ($environment -in @('prod', 'production') -and -not $ConfirmProductionRestore) {
    throw 'Production live restore requires -ConfirmProductionRestore.'
}

if (-not [IO.Path]::IsPathRooted($DumpPath)) { $DumpPath = Join-Path $root $DumpPath }
$dump = [IO.Path]::GetFullPath($DumpPath)
if ([IO.Path]::GetExtension($dump) -ne '.dump' -or
    -not (Test-Path -LiteralPath $dump -PathType Leaf) -or
    (Get-Item -LiteralPath $dump).Length -le 0) {
    throw 'Explicit custom-format dump is missing or empty.'
}

Assert-DockerAvailable
$containerFile = '/tmp/platform-live-restore-' + [guid]::NewGuid().ToString('N') + '.dump'
$restoreAttempted = $false
try {
    & docker compose --profile control cp $dump "${service}:$containerFile"
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy dump to PostgreSQL container.' }
    & docker compose --profile control exec -T $service pg_restore --list $containerFile *> $null
    if ($LASTEXITCODE -ne 0) { throw 'Custom-format dump verification failed.' }

    $restoreAttempted = $true
    & docker compose --profile control exec -T $service pg_restore `
        -U $user -d $database -Fc --clean --if-exists --exit-on-error $containerFile
    if ($LASTEXITCODE -ne 0) { throw 'Live restore failed.' }
    Write-Host 'Live database restore completed.'
}
finally {
    & docker compose --profile control exec -T $service rm -f $containerFile *> $null
    if ($restoreAttempted) {
        Write-Warning 'PRIVACY RECONCILIATION REQUIRED BEFORE SERVING TRAFFIC: restored data may include records erased after the backup. Operational state is blocked until reviewed.'
    }
}
