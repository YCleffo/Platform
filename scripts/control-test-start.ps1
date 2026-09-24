$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($env:CONTROL_TEST_PASSWORD)) { throw 'CONTROL_TEST_PASSWORD is required.' }
docker compose --profile control-test up -d control-postgres-test
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
docker compose --profile control-test build control-plane-test
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
docker compose --profile control-test run --rm control-plane-test php bin/console doctrine:migrations:migrate --no-interaction
exit $LASTEXITCODE
