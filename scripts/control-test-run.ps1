$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($env:CONTROL_TEST_PASSWORD)) { throw 'CONTROL_TEST_PASSWORD is required.' }
docker compose --profile control-test run --rm control-plane-test vendor/bin/phpunit
exit $LASTEXITCODE
