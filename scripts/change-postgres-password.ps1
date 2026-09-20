$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "common.ps1")

$Root = Get-PlatformRoot
Set-Location $Root

Assert-DockerAvailable
$Config = Get-PlatformConfig -Root $Root

$Role = $Config["POSTGRES_USER"]
$Database = $Config["POSTGRES_DB"]
$EnvFile = Join-Path $Root ".env"

if ($Role -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
    throw "Unsupported PostgreSQL role name: $Role"
}

function Convert-SecureStringToPlainText {
    param(
        [Parameter(Mandatory = $true)]
        [Security.SecureString]$SecureString
    )

    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR(
        $SecureString
    )

    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR(
            $pointer
        )
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

Write-Host ""
Write-Host "=== PostgreSQL password rotation ===" -ForegroundColor Cyan
Write-Host "Database: $Database"
Write-Host "Role:     $Role"
Write-Host ""

$SecurePassword1 = Read-Host `
    "Enter new PostgreSQL password" `
    -AsSecureString

$SecurePassword2 = Read-Host `
    "Repeat new PostgreSQL password" `
    -AsSecureString

$NewPassword = Convert-SecureStringToPlainText $SecurePassword1
$ConfirmPassword = Convert-SecureStringToPlainText $SecurePassword2

if ($NewPassword -ne $ConfirmPassword) {
    throw "Passwords do not match."
}

if (
    $NewPassword.Length -lt 16
    -or $NewPassword.Length -gt 128
) {
    throw "Password must contain between 16 and 128 characters."
}

# Allow printable ASCII characters except:
#   '  single quote
#   spaces
#   line breaks
#
# Single quote is intentionally excluded because .env uses
# single-quoted values to prevent Docker Compose interpolation.
if ($NewPassword -notmatch '^[\x21-\x26\x28-\x7E]{16,128}$') {
    throw @"
Password contains unsupported characters.

Allowed:
printable ASCII characters except:
- single quote '
- spaces
- line breaks
"@
}

Write-Host ""
Write-Host "Checking current PostgreSQL credentials..." `
    -ForegroundColor Cyan

& docker compose exec `
    -T `
    -e "PGPASSWORD=$($Config['POSTGRES_PASSWORD'])" `
    postgres `
    psql `
    -X `
    -h 127.0.0.1 `
    -U $Role `
    -d $Database `
    -v ON_ERROR_STOP=1 `
    -c "SELECT 1;" *> $null

if ($LASTEXITCODE -ne 0) {
    throw "Current POSTGRES_PASSWORD does not match PostgreSQL."
}

Write-Host "Changing PostgreSQL role password..." `
    -ForegroundColor Cyan

& docker compose exec `
    -T `
    -e "PGPASSWORD=$($Config['POSTGRES_PASSWORD'])" `
    postgres `
    psql `
    -X `
    -h 127.0.0.1 `
    -U $Role `
    -d $Database `
    -v ON_ERROR_STOP=1 `
    -v "target_role=$Role" `
    -v "new_password=$NewPassword" `
    -c 'ALTER ROLE :"target_role" WITH PASSWORD :''new_password'';'

if ($LASTEXITCODE -ne 0) {
    throw "ALTER ROLE failed."
}

Write-Host "Verifying new PostgreSQL password..." `
    -ForegroundColor Cyan

& docker compose exec `
    -T `
    -e "PGPASSWORD=$NewPassword" `
    postgres `
    psql `
    -X `
    -h 127.0.0.1 `
    -U $Role `
    -d $Database `
    -v ON_ERROR_STOP=1 `
    -c "SELECT 1;" *> $null

if ($LASTEXITCODE -ne 0) {
    throw "New PostgreSQL password verification failed."
}

Write-Host "Updating root .env..." -ForegroundColor Cyan

$Found = $false

$UpdatedLines = foreach (
    $Line in Get-Content -LiteralPath $EnvFile
) {
    if ($Line -match '^\s*POSTGRES_PASSWORD=') {
        $Found = $true
        "POSTGRES_PASSWORD='$NewPassword'"
    }
    else {
        $Line
    }
}

if (-not $Found) {
    throw "POSTGRES_PASSWORD was not found in $EnvFile"
}

$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

[IO.File]::WriteAllText(
    $EnvFile,
    (($UpdatedLines -join "`n") + "`n"),
    $Utf8NoBom
)

# common.ps1 imported the old value into this PowerShell process.
# Update it before calling Docker Compose again.
[Environment]::SetEnvironmentVariable(
    "POSTGRES_PASSWORD",
    $NewPassword,
    "Process"
)

$Config["POSTGRES_PASSWORD"] = $NewPassword

Write-Host "Recreating PostgreSQL/backend containers..." `
    -ForegroundColor Cyan

docker compose up `
    -d `
    --force-recreate `
    postgres `
    backend

if ($LASTEXITCODE -ne 0) {
    throw "Container recreation failed."
}

Write-Host "Checking Yii -> PostgreSQL..." `
    -ForegroundColor Cyan

docker compose exec -T backend php yii db:check

if ($LASTEXITCODE -ne 0) {
    throw "Yii cannot connect with the new PostgreSQL password."
}

$HealthUrl = "http://127.0.0.1:$($Config['BACKEND_PORT'])/health"

$Deadline = (Get-Date).AddSeconds(60)
$Healthy = $false

while ((Get-Date) -lt $Deadline) {
    try {
        $Health = Invoke-RestMethod `
            -Uri $HealthUrl `
            -TimeoutSec 5

        if (
            $Health.status -eq "success"
            -and $Health.data.status -eq "ok"
        ) {
            $Healthy = $true
            break
        }
    }
    catch {
        Start-Sleep -Seconds 2
    }
}

if (-not $Healthy) {
    throw "Backend health check failed after password rotation."
}

$NewPassword = $null
$ConfirmPassword = $null

Write-Host ""
Write-Host "PostgreSQL password changed successfully." `
    -ForegroundColor Green
Write-Host "Yii database connection: OK" `
    -ForegroundColor Green
Write-Host "Backend health: OK" `
    -ForegroundColor Green
