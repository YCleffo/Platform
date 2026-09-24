$ErrorActionPreference = 'Stop'
if ($env:APP_ENV -ne 'test' -or [string]::IsNullOrWhiteSpace($env:CONTROL_TEST_PASSWORD)) { throw 'Reset requires APP_ENV=test and CONTROL_TEST_PASSWORD.' }
docker compose --profile control-test run --rm control-plane-test php bin/console control:test:reset
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
docker compose --profile control-test run --rm control-plane-test php bin/console doctrine:migrations:migrate --no-interaction
exit $LASTEXITCODE
