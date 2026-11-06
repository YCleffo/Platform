[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$secrets = Join-Path $root 'infra\observability\secrets'
if (Test-Path -LiteralPath (Join-Path $secrets 'metrics-token')) {
    $env:METRICS_TOKEN = (Get-Content -LiteralPath (Join-Path $secrets 'metrics-token') -Raw).Trim()
}
if (Test-Path -LiteralPath (Join-Path $secrets 'grafana-admin-password')) {
    $env:GRAFANA_ADMIN_PASSWORD = (Get-Content -LiteralPath (Join-Path $secrets 'grafana-admin-password') -Raw).Trim()
}
if (-not $env:METRICS_TOKEN) { $env:METRICS_TOKEN = '0' * 64 }
if (-not $env:GRAFANA_ADMIN_PASSWORD) { $env:GRAFANA_ADMIN_PASSWORD = '0' * 32 }

Push-Location $root
try {
    & docker compose -f compose.yaml -f compose.observability.yaml --profile control `
        rm -sf prometheus alertmanager grafana postgres-exporter-core `
        postgres-exporter-control redis-exporter node-exporter alert-sink
    if ($LASTEXITCODE -ne 0) { throw "docker compose rm exited with $LASTEXITCODE" }
    foreach ($volume in @('platform_prometheus_data', 'platform_alertmanager_data', 'platform_grafana_data')) {
        & docker volume rm $volume 2>$null | Out-Null
    }
    & docker network rm platform_observability_net 2>$null | Out-Null
    Write-Output 'Observability containers and local data volumes removed.'
} finally {
    Pop-Location
}
