[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
$Root = Get-PlatformRoot
Set-Location $Root
Assert-DockerAvailable
$Config = Get-PlatformConfig -Root $Root

docker compose --profile control ps
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect runtime services.' }

python scripts/database/export_schema.py --plane core --database $Config['POSTGRES_DB'] --user $Config['POSTGRES_USER'] --check
if ($LASTEXITCODE -ne 0) { throw 'Core schema or migration history differs from the repository.' }
python scripts/database/export_schema.py --plane commercial --database $Config['CONTROL_POSTGRES_DB'] --user $Config['CONTROL_POSTGRES_USER'] --check
if ($LASTEXITCODE -ne 0) { throw 'Commercial schema or migration history differs from the repository.' }
Write-Host 'Both PostgreSQL catalogs, migration histories and DBML match the repository.'
