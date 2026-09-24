$ErrorActionPreference = 'Stop'
docker compose --profile control-test stop control-plane-test control-postgres-test
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
docker compose --profile control-test rm -f control-plane-test control-postgres-test
exit $LASTEXITCODE
