[CmdletBinding()]
param(
    [string]$DumpPath,
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "common.ps1")

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable
$Config = Get-PlatformConfig -Root $Root

$DatabaseName = $Config["POSTGRES_DB"]
$DatabaseUser = $Config["POSTGRES_USER"]

# ------------------------------------------------------------
# Resolve dump file
# ------------------------------------------------------------
if ([string]::IsNullOrWhiteSpace($DumpPath)) {
    $backupDir = Join-Path $Root "backups"
    if (-not (Test-Path -LiteralPath $backupDir)) {
        throw "Backups directory does not exist: $backupDir"
    }

    $latestDump = Get-ChildItem -LiteralPath $backupDir -Filter "*.dump" -File |
        Sort-Object -Property LastWriteTime -Descending |
        Select-Object -First 1

    if ($null -eq $latestDump) {
        throw "No dump files found in $backupDir. Specify -DumpPath explicitly."
    }

    $ResolvedDumpPath = $latestDump.FullName
}
else {
    if (-not [IO.Path]::IsPathRooted($DumpPath)) {
        $DumpPath = Join-Path $Root $DumpPath
    }
    $ResolvedDumpPath = [IO.Path]::GetFullPath($DumpPath)
}

if (-not (Test-Path -LiteralPath $ResolvedDumpPath -PathType Leaf)) {
    throw "Backup dump file was not found: $ResolvedDumpPath"
}

# ------------------------------------------------------------
# Confirmation before destructive restore
# ------------------------------------------------------------
Write-Host ""
Write-Host "PostgreSQL Database Restore" -ForegroundColor Cyan
Write-Host "Database: $DatabaseName"
Write-Host "User:     $DatabaseUser"
Write-Host "Dump:     $ResolvedDumpPath"
Write-Host ""

if (-not $Force) {
    Write-Host "WARNING: Restoring will clean and overwrite existing objects in '$DatabaseName'." -ForegroundColor Yellow
    $confirmation = Read-Host "Type 'yes' to confirm database restore"
    if ($confirmation -ne "yes") {
        Write-Host "Restore cancelled by user." -ForegroundColor Gray
        exit 0
    }
}

# ------------------------------------------------------------
# Perform restore inside container
# ------------------------------------------------------------
$ContainerFile = "/tmp/platform-restore-" + [guid]::NewGuid().ToString("N") + ".dump"

try {
    Write-Host "Copying dump file to postgres container..." -ForegroundColor Cyan
    docker compose cp "$ResolvedDumpPath" "postgres:$ContainerFile"
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to copy dump file into postgres container."
    }

    Write-Host "Restoring database with pg_restore..." -ForegroundColor Cyan
    # Pass credentials from config without printing password to console
    docker compose exec -T `
        -e "PGPASSWORD=$($Config['POSTGRES_PASSWORD'])" `
        postgres `
        pg_restore `
        -U $DatabaseUser `
        -d $DatabaseName `
        -Fc `
        --clean `
        --if-exists `
        $ContainerFile

    if ($LASTEXITCODE -ne 0) {
        throw "pg_restore failed with exit code $LASTEXITCODE"
    }

    Write-Host "Database restore completed successfully." -ForegroundColor Green
}
finally {
    docker compose exec -T postgres rm -f $ContainerFile *> $null
}
