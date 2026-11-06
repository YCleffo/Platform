[CmdletBinding()]
param([switch]$NoBuild)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$secrets = Join-Path $root 'infra\observability\secrets'
$envFile = Join-Path $root '.env'
if (-not (Test-Path -LiteralPath $envFile)) {
    throw 'Create .env from .env.example before starting the observability stack.'
}
New-Item -ItemType Directory -Force -Path $secrets | Out-Null

function New-RandomHex {
    return ([guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N'))
}

$metricsFile = Join-Path $secrets 'metrics-token'
if (-not (Test-Path -LiteralPath $metricsFile) -or (Get-Content -LiteralPath $metricsFile -Raw).Trim().Length -lt 32) {
    Set-Content -LiteralPath $metricsFile -Value (New-RandomHex) -NoNewline -Encoding ASCII
}
$webhookFile = Join-Path $secrets 'alert-webhook-url'
if (-not (Test-Path -LiteralPath $webhookFile)) {
    Set-Content -LiteralPath $webhookFile -Value 'http://alert-sink:8080/alerts' -NoNewline -Encoding ASCII
}
$grafanaFile = Join-Path $secrets 'grafana-admin-password'
if (-not (Test-Path -LiteralPath $grafanaFile)) {
    Set-Content -LiteralPath $grafanaFile -Value (New-RandomHex) -NoNewline -Encoding ASCII
}

$env:METRICS_TOKEN = (Get-Content -LiteralPath $metricsFile -Raw).Trim()
$env:GRAFANA_ADMIN_PASSWORD = (Get-Content -LiteralPath $grafanaFile -Raw).Trim()

Push-Location $root
try {
    $arguments = @('-f', 'compose.yaml', '-f', 'compose.observability.yaml', '--profile', 'control', 'up', '-d')
    if (-not $NoBuild) { $arguments += '--build' }
    & docker compose @arguments
    if ($LASTEXITCODE -ne 0) { throw "docker compose exited with $LASTEXITCODE" }
    Write-Output 'Observability stack started:'
    Write-Output '  Prometheus  http://127.0.0.1:9090'
    Write-Output '  Alertmanager http://127.0.0.1:9093'
    Write-Output '  Grafana     http://127.0.0.1:3000'
    Write-Output 'The local alert sink is http://127.0.0.1:8085 and is not a production receiver.'
} finally {
    Pop-Location
}
