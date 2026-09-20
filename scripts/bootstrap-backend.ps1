$ErrorActionPreference = "Stop"

# STEP 2 helper. It is included now so the repository already has the intended
# bootstrap path, but you do NOT need to run it merely to inspect Core in DBeaver.

$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$Backend = Join-Path $Root "apps\backend"
$Temp = Join-Path $Root ".bootstrap-yii3"

Set-Location $Root

if (Test-Path (Join-Path $Backend "composer.json")) {
    Write-Host "Yii3 backend is already bootstrapped." -ForegroundColor Yellow
    exit 0
}

if (Test-Path $Temp) {
    Remove-Item -Recurse -Force $Temp
}

Write-Host "Creating current official Yii3 API template in a temporary folder..." -ForegroundColor Cyan

docker run --rm `
    -v "${Root}:/workspace" `
    -w /workspace `
    composer:2 `
    create-project yiisoft/app-api .bootstrap-yii3

if ($LASTEXITCODE -ne 0) { throw "Yii3 create-project failed" }

Write-Host "Installing PostgreSQL and migration packages..." -ForegroundColor Cyan

docker run --rm `
    -v "${Temp}:/app" `
    -w /app `
    composer:2 `
    require yiisoft/db-pgsql yiisoft/db-migration

if ($LASTEXITCODE -ne 0) { throw "Composer require failed" }

# Preserve phase-1 SQL migrations while copying the official template around them.
$SqlMigrations = Join-Path $Backend "migrations\sql"
$SavedMigrations = Join-Path $Root ".phase1-sql-migrations"
if (Test-Path $SavedMigrations) { Remove-Item -Recurse -Force $SavedMigrations }
Copy-Item -Recurse -Force $SqlMigrations $SavedMigrations

Get-ChildItem -Force $Temp | ForEach-Object {
    Copy-Item -Recurse -Force $_.FullName $Backend
}

New-Item -ItemType Directory -Force -Path (Join-Path $Backend "migrations") | Out-Null
Copy-Item -Recurse -Force $SavedMigrations (Join-Path $Backend "migrations\sql")

Remove-Item -Recurse -Force $SavedMigrations
Remove-Item -Recurse -Force $Temp

Write-Host "Yii3 backend template installed in apps/backend." -ForegroundColor Green
Write-Host "Do not wire API/auth yet; next step is configuring Yii3 -> PostgreSQL and translating migrations." -ForegroundColor Yellow
