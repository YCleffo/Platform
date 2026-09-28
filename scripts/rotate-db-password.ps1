[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$DbService,
    [Parameter(Mandatory)][string]$DbKey,
    [Parameter(Mandatory)][string]$UserKey,
    [Parameter(Mandatory)][string]$PasswordKey,
    [Parameter(Mandatory)][string]$AppService,
    [Parameter(Mandatory)][string[]]$CheckCommand,
    [string[]]$AdditionalAppServices = @(),
    [string]$LinkedDbService = '',
    [string]$LinkedVolume = '',
    [string]$LinkedAppService = '',
    [switch]$NoRestart
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$envPath = Join-Path $root '.env'
if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
    throw '.env is missing. Run scripts/init-env.ps1 first.'
}
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI is unavailable.'
}

function Get-LocalSetting([string]$key) {
    $found = @(
        Get-Content -LiteralPath $envPath -Encoding UTF8 |
            Where-Object { $_ -match ('^' + [regex]::Escape($key) + '=') }
    )
    if ($found.Count -ne 1) {
        throw "Expected exactly one $key entry in .env."
    }
    $value = $found[0].Substring($key.Length + 1).Trim()
    if ($value.Length -ge 2 -and
        (($value[0] -eq "'" -and $value[$value.Length - 1] -eq "'") -or
         ($value[0] -eq '"' -and $value[$value.Length - 1] -eq '"'))) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "$key is empty in .env."
    }
    return $value
}

$db = Get-LocalSetting $DbKey
$role = Get-LocalSetting $UserKey
if ($role -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
    throw "Unsafe PostgreSQL role name in $UserKey."
}

$bytes = New-Object byte[] 32
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
$newPassword = [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
$encoding = New-Object System.Text.UTF8Encoding($false)
$original = [IO.File]::ReadAllText($envPath, $encoding)
$backup = "$envPath.backup-$(Get-Date -Format 'yyyyMMdd-HHmmss')-$([guid]::NewGuid().ToString('N').Substring(0,8))"
$temp = "$envPath.tmp-$([guid]::NewGuid().ToString('N'))"
$oldHash = $null
$changed = $false
$linkedOldHash = $null
$linkedChanged = $false
$linkedPresent = $false
$linkedAppPresent = $false
$envUpdated = $false

Push-Location $root
try {
    # An initialized volume can have a password different from .env. Local
    # socket superuser access repairs that state without knowing the old one.
    docker compose up -d $DbService | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Cannot start $DbService." }

    $oldHash = (& docker compose exec -T -u postgres $DbService `
        psql -X -At -v ON_ERROR_STOP=1 -U $role -d postgres `
        -c "SELECT rolpassword FROM pg_authid WHERE rolname = '$role';") -join ''
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($oldHash)) {
        throw "Cannot read existing role verifier for $role."
    }

    # Send the password through stdin, not a psql command-line argument.
    "ALTER ROLE `"$role`" WITH PASSWORD '$newPassword';" |
        docker compose exec -T -u postgres $DbService `
            psql -X -q -v ON_ERROR_STOP=1 -U $role -d postgres *> $null
    if ($LASTEXITCODE -ne 0) { throw 'ALTER ROLE failed; .env was not changed.' }
    $changed = $true

    docker compose exec -T -e "PGPASSWORD=$newPassword" $DbService `
        psql -X -h 127.0.0.1 -U $role -d $db -At `
        -v ON_ERROR_STOP=1 -c 'SELECT 1' *> $null
    if ($LASTEXITCODE -ne 0) { throw 'New PostgreSQL password did not authenticate.' }

    # The isolated Yii test database uses POSTGRES_PASSWORD too. If its
    # persisted volume exists, rotate that role before changing the shared env.
    if ($LinkedDbService -ne '' -and $LinkedVolume -ne '') {
        docker volume inspect $LinkedVolume *> $null
        $linkedPresent = $LASTEXITCODE -eq 0
    }
    if ($linkedPresent) {
        if ($LinkedAppService -ne '') {
            $linkedAppId = (& docker compose --profile test ps -a -q $LinkedAppService) -join ''
            $linkedAppPresent = -not [string]::IsNullOrWhiteSpace($linkedAppId)
        }
        docker compose --profile test up -d $LinkedDbService | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Cannot start $LinkedDbService." }
        $linkedOldHash = (& docker compose --profile test exec -T -u postgres $LinkedDbService `
            psql -X -At -v ON_ERROR_STOP=1 -U $role -d postgres `
            -c "SELECT rolpassword FROM pg_authid WHERE rolname = '$role';") -join ''
        if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($linkedOldHash)) {
            throw "Cannot read existing role verifier in $LinkedDbService."
        }
        "ALTER ROLE `"$role`" WITH PASSWORD '$newPassword';" |
            docker compose --profile test exec -T -u postgres $LinkedDbService `
                psql -X -q -v ON_ERROR_STOP=1 -U $role -d postgres *> $null
        if ($LASTEXITCODE -ne 0) { throw "ALTER ROLE failed in $LinkedDbService." }
        $linkedChanged = $true
        docker compose --profile test exec -T -e "PGPASSWORD=$newPassword" $LinkedDbService `
            psql -X -h 127.0.0.1 -U $role -d postgres -At `
            -v ON_ERROR_STOP=1 -c 'SELECT 1' *> $null
        if ($LASTEXITCODE -ne 0) { throw "New password did not authenticate in $LinkedDbService." }
    }

    $lines = @($original -split "`r?`n")
    if ($lines.Count -gt 0 -and $lines[$lines.Count - 1] -eq '') {
        $lines = @($lines | Select-Object -SkipLast 1)
    }
    $updated = New-Object System.Collections.Generic.List[string]
    $replacements = 0
    foreach ($line in $lines) {
        if ($line -match ('^' + [regex]::Escape($PasswordKey) + '=')) {
            $updated.Add("$PasswordKey=$newPassword")
            $replacements++
        } else { $updated.Add($line) }
    }
    if ($replacements -ne 1) { throw "Expected exactly one $PasswordKey entry in .env." }
    [IO.File]::WriteAllText($temp, ($updated -join "`n") + "`n", $encoding)
    [IO.File]::WriteAllText($backup, $original, $encoding)
    Move-Item -LiteralPath $temp -Destination $envPath -Force
    $envUpdated = $true
    [Environment]::SetEnvironmentVariable($PasswordKey, $newPassword, 'Process')

    if (-not $NoRestart) {
        $services = @($AppService) + $AdditionalAppServices
        docker compose up -d --no-deps --force-recreate @services | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "Dependent applications could not restart. Database and .env are synchronized; retry the service start."
        }
        & docker compose exec -T $AppService @CheckCommand
        if ($LASTEXITCODE -ne 0) {
            throw "$AppService database check failed. Database and .env are synchronized."
        }
        if ($linkedAppPresent) {
            docker compose --profile test up -d --no-deps --force-recreate $LinkedAppService | Out-Null
            if ($LASTEXITCODE -ne 0) {
                throw "$LinkedAppService could not restart. Database and .env are synchronized."
            }
        }
    }
    Write-Host "$DbService`: password rotated in PostgreSQL and .env; TCP authentication verified."
    Write-Host "Previous .env backup: $backup"
}
catch {
    if (-not $envUpdated -and $linkedChanged -and $linkedOldHash) {
        "ALTER ROLE `"$role`" WITH PASSWORD '$linkedOldHash';" |
            docker compose --profile test exec -T -u postgres $LinkedDbService `
                psql -X -q -v ON_ERROR_STOP=1 -U $role -d postgres *> $null
        if ($LASTEXITCODE -ne 0) {
            Write-Warning 'Automatic linked PostgreSQL password rollback failed; manual recovery is required.'
        }
    }
    if (-not $envUpdated -and $changed -and $oldHash) {
        "ALTER ROLE `"$role`" WITH PASSWORD '$oldHash';" |
            docker compose exec -T -u postgres $DbService `
                psql -X -q -v ON_ERROR_STOP=1 -U $role -d postgres *> $null
        if ($LASTEXITCODE -ne 0) {
            Write-Warning 'Automatic PostgreSQL password rollback failed; manual recovery is required.'
        }
    }
    throw
}
finally {
    if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Force }
    $oldHash = $null
    $linkedOldHash = $null
    $newPassword = $null
    Pop-Location
}
