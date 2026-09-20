$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "common.ps1")

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable
$Config = Get-PlatformConfig -Root $Root

$BackupDir = Join-Path $Root "backups"
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

$Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$DatabaseName = $Config["POSTGRES_DB"]
$File = Join-Path $BackupDir "$DatabaseName-$Stamp.dump"
$ContainerFile = "/tmp/platform-backup.dump"

Write-Host "Creating PostgreSQL custom-format backup..." -ForegroundColor Cyan
Write-Host "Database: $DatabaseName"
Write-Host "Target:   $File"

# The dump is written inside the container first because a PostgreSQL custom
# format dump is binary and must not pass through PowerShell's text pipeline.
docker compose exec -T postgres `
    pg_dump `
    -U $Config["POSTGRES_USER"] `
    -d $DatabaseName `
    -Fc `
    -f $ContainerFile

if ($LASTEXITCODE -ne 0) {
    throw "pg_dump failed"
}

try {
    docker compose cp "postgres:$ContainerFile" "$File"
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose cp failed"
    }
}
finally {
    # Cleanup is best-effort; the local backup has already been copied by this point.
    docker compose exec -T postgres rm -f $ContainerFile *> $null
}

if (-not (Test-Path -LiteralPath $File)) {
    throw "Backup file was not created: $File"
}

Write-Host "Backup created: $File" -ForegroundColor Green
