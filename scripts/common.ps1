Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# -----------------------------------------------------------------------------
# Shared helpers for local Platform development scripts.
#
# IMPORTANT:
#   - .env is the single source of local configuration.
#   - Database/user names are NOT hardcoded in individual scripts.
#   - docker compose is always executed from the repository root.
# -----------------------------------------------------------------------------

function Get-PlatformRoot {
    return (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
}

function Assert-DockerAvailable {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw "Docker CLI was not found. Install/start Docker Desktop first."
    }

    docker info *> $null
    if ($LASTEXITCODE -ne 0) {
        throw "Docker daemon is not available. Start Docker Desktop and retry."
    }
}

function Ensure-PlatformEnv {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Root
    )

    $envFile = Join-Path $Root ".env"
    $exampleFile = Join-Path $Root ".env.example"

    if (-not (Test-Path -LiteralPath $envFile)) {
        if (-not (Test-Path -LiteralPath $exampleFile)) {
            throw ".env does not exist and .env.example was not found: $exampleFile"
        }

        Copy-Item -LiteralPath $exampleFile -Destination $envFile
        Write-Host "Created .env from .env.example" -ForegroundColor Yellow
    }

    return $envFile
}

function Import-PlatformEnv {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw ".env file not found: $Path"
    }

    $config = @{}

    foreach ($rawLine in Get-Content -LiteralPath $Path) {
        $line = $rawLine.Trim()

        # Empty lines and full-line comments are ignored.
        if ([string]::IsNullOrWhiteSpace($line) -or $line.StartsWith("#")) {
            continue
        }

        $separatorIndex = $line.IndexOf("=")
        if ($separatorIndex -lt 1) {
            continue
        }

        $name = $line.Substring(0, $separatorIndex).Trim()
        $value = $line.Substring($separatorIndex + 1).Trim()

        # Support optional single or double quotes around a value.
        if ($value.Length -ge 2) {
            $first = $value.Substring(0, 1)
            $last = $value.Substring($value.Length - 1, 1)

            if (($first -eq '"' -and $last -eq '"') -or ($first -eq "'" -and $last -eq "'")) {
                $value = $value.Substring(1, $value.Length - 2)
            }
        }

        $config[$name] = $value

        # Make the same values available to child processes started by PowerShell.
        [Environment]::SetEnvironmentVariable($name, $value, "Process")
    }

    $required = @(
        "POSTGRES_DB",
        "POSTGRES_USER",
        "POSTGRES_PASSWORD",
        "POSTGRES_PORT",
        "REDIS_PORT",
        "BACKEND_PORT"
    )

    foreach ($key in $required) {
        if (-not $config.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($config[$key])) {
            throw "Required setting '$key' is missing or empty in $Path"
        }
    }

    return $config
}

function Get-PlatformConfig {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Root
    )

    $envFile = Ensure-PlatformEnv -Root $Root
    return Import-PlatformEnv -Path $envFile
}

function Invoke-PlatformPsqlScalar {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Root,

        [Parameter(Mandatory = $true)]
        [hashtable]$Config,

        [Parameter(Mandatory = $true)]
        [string]$Sql
    )

    Push-Location $Root
    try {
        # Execute psql directly in the PostgreSQL container.
        # No nested `sh -lc` means no fragile shell/PowerShell quote escaping.
        $output = & docker compose exec -T postgres `
            psql `
            -X `
            -U $Config["POSTGRES_USER"] `
            -d $Config["POSTGRES_DB"] `
            -tA `
            -v ON_ERROR_STOP=1 `
            -c $Sql

        if ($LASTEXITCODE -ne 0) {
            throw "PostgreSQL query failed."
        }

        return (($output | Out-String).Trim())
    }
    finally {
        Pop-Location
    }
}

function Invoke-PlatformPsqlTable {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Root,

        [Parameter(Mandatory = $true)]
        [hashtable]$Config,

        [Parameter(Mandatory = $true)]
        [string]$Sql
    )

    Push-Location $Root
    try {
        & docker compose exec -T postgres `
            psql `
            -X `
            -U $Config["POSTGRES_USER"] `
            -d $Config["POSTGRES_DB"] `
            -P pager=off `
            -v ON_ERROR_STOP=1 `
            -c $Sql

        if ($LASTEXITCODE -ne 0) {
            throw "PostgreSQL query failed."
        }
    }
    finally {
        Pop-Location
    }
}
