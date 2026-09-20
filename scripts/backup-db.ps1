$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $Root

$BackupDir = Join-Path $Root "backups"
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
$Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$File = Join-Path $BackupDir "platform-$Stamp.dump"

Write-Host "Creating PostgreSQL custom-format backup: $File" -ForegroundColor Cyan

# Binary output must not be piped through PowerShell text processing.
docker compose exec -T postgres sh -lc `
  'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -f /tmp/platform.dump'

if ($LASTEXITCODE -ne 0) { throw "pg_dump failed" }

docker compose cp "postgres:/tmp/platform.dump" "$File"
docker compose exec -T postgres rm -f /tmp/platform.dump

Write-Host "Backup created: $File" -ForegroundColor Green
