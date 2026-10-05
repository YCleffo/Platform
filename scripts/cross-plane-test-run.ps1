$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($env:CONTROL_TEST_PASSWORD)) { throw 'CONTROL_TEST_PASSWORD is required for the isolated Commercial test database.' }
$env:CROSS_PLANE_REQUIRED = '1'
docker compose --profile test up -d postgres-test redis-test
docker compose --profile test run --rm db-migrate-test
docker compose --profile control-test build control-plane-test
if ($LASTEXITCODE) { throw 'Control Plane test image build failed.' }
docker compose --profile control-test build cross-plane-test
if ($LASTEXITCODE) { throw 'Cross-plane test image build failed.' }
docker compose --profile control-test run --rm --no-deps cross-plane-test test -f /backend/vendor/autoload.php
docker compose --profile control-test up -d control-postgres-test
docker compose --profile control-test run --rm --no-deps cross-plane-test php bin/console doctrine:migrations:migrate --no-interaction
$coreReady = docker compose --profile test exec -T postgres-test psql -U $env:POSTGRES_USER -d $env:POSTGRES_TEST_DB -tA -c "SELECT to_regclass('platform_runtime.installations') IS NOT NULL AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL;"
if ($coreReady.Trim() -ne 't') { throw 'Required Core schema is missing.' }
$commercialReady = docker compose --profile control-test exec -T control-postgres-test psql -U $env:CONTROL_TEST_USER -d $env:CONTROL_TEST_DB -tA -c "SELECT to_regclass('commercial.deployments') IS NOT NULL AND to_regclass('commercial.service_credentials') IS NOT NULL;"
if ($commercialReady.Trim() -ne 't') { throw 'Required Commercial schema is missing.' }
docker compose --profile control-test run --rm --no-deps -e CROSS_PLANE_REQUIRED=1 cross-plane-test vendor/bin/phpunit --configuration phpunit.cross-plane.xml --testdox
