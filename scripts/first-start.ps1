$ErrorActionPreference = "Stop"

# Resolve repository root regardless of where PowerShell was opened.
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $Root

Write-Host ""
Write-Host "=== Platform Phase 1: first start ===" -ForegroundColor Cyan
Write-Host "Root: $Root"
Write-Host ""

# Docker Desktop must already be installed and running.
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw "Docker CLI not found. Install/start Docker Desktop first."
}

docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker daemon is not available. Start Docker Desktop and retry."
}

# Keep secrets out of Git. For local development we copy the example once.
if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Host "Created .env from .env.example" -ForegroundColor Yellow
    Write-Host "You may change POSTGRES_PASSWORD before using this beyond local development."
}

Write-Host "Pulling PostgreSQL/Redis images..." -ForegroundColor Cyan
docker compose pull postgres redis db-migrate
if ($LASTEXITCODE -ne 0) { throw "docker compose pull failed" }

Write-Host "Starting PostgreSQL and Redis..." -ForegroundColor Cyan
docker compose up -d postgres redis
if ($LASTEXITCODE -ne 0) { throw "docker compose up failed" }

Write-Host "Applying Core v1 migrations..." -ForegroundColor Cyan
docker compose run --rm db-migrate
if ($LASTEXITCODE -ne 0) { throw "Core migration failed" }

Write-Host ""
Write-Host "Container status:" -ForegroundColor Cyan
docker compose ps

Write-Host ""
Write-Host "Checking Core table count..." -ForegroundColor Cyan
$count = docker compose exec -T postgres sh -lc `
    'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema=''core'' AND table_type=''BASE TABLE'';"'

$count = $count.Trim()
if ($count -ne "23") {
    throw "Expected 23 core tables, got: $count"
}

Write-Host "OK: core.* contains exactly 23 tables." -ForegroundColor Green
Write-Host ""
Write-Host "DBeaver:" -ForegroundColor Green
Write-Host "  Host:     localhost"
Write-Host "  Port:     value of POSTGRES_PORT in C:\Platform\.env (default 5432)"
Write-Host "  Database: value of POSTGRES_DB in C:\Platform\.env"
Write-Host "  User:     value of POSTGRES_USER in C:\Platform\.env"
Write-Host "  Password: value of POSTGRES_PASSWORD in C:\Platform\.env"
Write-Host ""
Write-Host "Next: open DBeaver -> Schemas -> core and inspect tables, columns, FKs, indexes and comments."
