$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($env:CONTROL_TEST_PASSWORD)) { throw 'CONTROL_TEST_PASSWORD is required for the isolated Commercial test database.' }
$coreDatabaseUser = if ([string]::IsNullOrWhiteSpace($env:POSTGRES_USER)) { 'platform_app' } else { $env:POSTGRES_USER }
$coreDatabaseName = if ([string]::IsNullOrWhiteSpace($env:POSTGRES_TEST_DB)) { 'platform_test' } else { $env:POSTGRES_TEST_DB }
$commercialDatabaseUser = if ([string]::IsNullOrWhiteSpace($env:CONTROL_TEST_USER)) { 'platform_control_test' } else { $env:CONTROL_TEST_USER }
$commercialDatabaseName = if ([string]::IsNullOrWhiteSpace($env:CONTROL_TEST_DB)) { 'platform_commercial_test' } else { $env:CONTROL_TEST_DB }
$env:CROSS_PLANE_REQUIRED = '1'
docker compose --profile test up -d --wait postgres-test redis-test
if ($LASTEXITCODE) { throw 'Core test dependencies did not become healthy.' }
docker compose --profile test run --rm db-migrate-test
if ($LASTEXITCODE) { throw 'Core test migration failed.' }
docker compose --profile control-test build control-plane-test
if ($LASTEXITCODE) { throw 'Control Plane test image build failed.' }
docker compose --profile control-test build cross-plane-test
if ($LASTEXITCODE) { throw 'Cross-plane test image build failed.' }
docker compose --profile control-test run --rm --no-deps cross-plane-test test -f /backend/vendor/autoload.php
if ($LASTEXITCODE) { throw 'Cross-plane image does not contain the Core autoloader.' }
docker compose --profile control-test up -d --wait control-postgres-test
if ($LASTEXITCODE) { throw 'Commercial test database did not become healthy.' }
docker compose --profile control-test run --rm --no-deps cross-plane-test php bin/console doctrine:migrations:migrate --no-interaction
if ($LASTEXITCODE) { throw 'Commercial test migration failed.' }
$coreReady = docker compose --profile test exec -T postgres-test psql -U $coreDatabaseUser -d $coreDatabaseName -tA -c "SELECT to_regclass('platform_runtime.installations') IS NOT NULL AND to_regclass('platform_runtime.entitlement_documents') IS NOT NULL;"
if ($coreReady.Trim() -ne 't') { throw 'Required Core schema is missing.' }
$commercialReady = docker compose --profile control-test exec -T control-postgres-test psql -U $commercialDatabaseUser -d $commercialDatabaseName -tA -c "SELECT to_regclass('commercial.deployments') IS NOT NULL AND to_regclass('commercial.service_credentials') IS NOT NULL;"
if ($commercialReady.Trim() -ne 't') { throw 'Required Commercial schema is missing.' }
docker compose --profile control-test run --rm --no-deps -e CROSS_PLANE_REQUIRED=1 cross-plane-test vendor/bin/phpunit --configuration phpunit.cross-plane.xml --testdox
if ($LASTEXITCODE) { throw 'Strict cross-plane PHPUnit failed.' }
