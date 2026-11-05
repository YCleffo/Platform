[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')
Set-Location (Get-PlatformRoot)
Assert-DockerAvailable
& (Join-Path $PSScriptRoot 'init-env.ps1')
docker compose --profile control config --quiet
if ($LASTEXITCODE -ne 0) { throw 'Invalid runtime configuration.' }
docker compose --profile control build backend control-plane
if ($LASTEXITCODE -ne 0) { throw 'Runtime image build failed.' }
docker compose --profile control up -d --wait postgres control-postgres redis mailpit
if ($LASTEXITCODE -ne 0) { throw 'Infrastructure start failed.' }
python scripts/database/migrate.py core
if ($LASTEXITCODE -ne 0) { throw 'Core migration failed.' }
# Use permanent application containers for installation and migrations.
docker compose --profile control up -d backend control-plane
if ($LASTEXITCODE -ne 0) { throw 'Application start failed.' }
docker compose exec -T backend composer install --no-interaction --prefer-dist
if ($LASTEXITCODE -ne 0) { throw 'Core dependency installation failed.' }
docker compose exec -T backend composer yii-config-rebuild
if ($LASTEXITCODE -ne 0) { throw 'Core configuration rebuild failed.' }
python scripts/database/migrate.py commercial
if ($LASTEXITCODE -ne 0) { throw 'Commercial migration failed.' }
python scripts/database/provision-runtime-roles.py
if ($LASTEXITCODE -ne 0) { throw 'Runtime database role provisioning failed.' }
docker compose --profile control up -d --wait --wait-timeout 120
if ($LASTEXITCODE -ne 0) { throw 'Runtime readiness failed; inspect compose logs.' }
docker compose exec -T backend php yii db:check
if ($LASTEXITCODE -ne 0) { throw 'Core database connection failed.' }
Write-Host 'Runtime is ready. Inspect docker compose --profile control ps for service status.'
