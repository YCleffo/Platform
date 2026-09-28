[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ------------------------------------------------------------
# Resolve project root
# ------------------------------------------------------------

$Root = Split-Path $PSScriptRoot -Parent

$ExamplePath = Join-Path $Root '.env.example'
$EnvPath = Join-Path $Root '.env'

if (-not (Test-Path -LiteralPath $ExamplePath -PathType Leaf)) {
    throw ".env.example not found: $ExamplePath"
}

Write-Host ''
Write-Host '============================================================' -ForegroundColor Cyan
Write-Host ' PLATFORM ENV INITIALIZER' -ForegroundColor Cyan
Write-Host '============================================================' -ForegroundColor Cyan
Write-Host ''

# ------------------------------------------------------------
# Helpers
# ------------------------------------------------------------

function New-RandomBytes {
    param(
        [Parameter(Mandatory)]
        [int]$Count
    )

    $bytes = New-Object byte[] $Count
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()

    try {
        $rng.GetBytes($bytes)
    }
    finally {
        $rng.Dispose()
    }

    return $bytes
}

function New-HexSecret {
    param(
        [int]$Bytes = 32
    )

    $data = New-RandomBytes -Count $Bytes

    return -join (
        $data | ForEach-Object {
            $_.ToString('x2')
        }
    )
}

function New-Base64Secret {
    param(
        [int]$Bytes = 32
    )

    $data = New-RandomBytes -Count $Bytes
    return [Convert]::ToBase64String($data)
}

function Read-EnvValues {
    param(
        [Parameter(Mandatory)]
        [string]$Path
    )

    $result = @{}

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        return $result
    }

    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            $key = $matches[1]
            $value = $matches[2]

            if (-not $result.ContainsKey($key) -or -not [string]::IsNullOrWhiteSpace($value)) {
                $result[$key] = $value
            }
        }
    }

    return $result
}

function Write-Utf8NoBom {
    param(
        [Parameter(Mandatory)]
        [string]$Path,

        [AllowEmptyString()]
        [AllowEmptyCollection()]
        [string[]]$Lines
    )

    $encoding = New-Object System.Text.UTF8Encoding($false)
    $tempPath = "$Path.tmp-$([guid]::NewGuid().ToString('N'))"
    try {
        [IO.File]::WriteAllLines($tempPath, $Lines, $encoding)
        Move-Item -LiteralPath $tempPath -Destination $Path -Force
    }
    finally {
        if (Test-Path -LiteralPath $tempPath) {
            Remove-Item -LiteralPath $tempPath -Force
        }
    }
}

# ------------------------------------------------------------
# Backup current .env
# ------------------------------------------------------------

if (Test-Path -LiteralPath $EnvPath -PathType Leaf) {
    $timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $backupPath = "$EnvPath.backup-$timestamp-$([guid]::NewGuid().ToString('N').Substring(0,8))"

    Copy-Item `
        -LiteralPath $EnvPath `
        -Destination $backupPath

    Write-Host "[OK] Backup created: $backupPath" -ForegroundColor DarkGray
}

# ------------------------------------------------------------
# Read current environment
# ------------------------------------------------------------

$currentValues = Read-EnvValues -Path $EnvPath

# ------------------------------------------------------------
# Values which may safely be generated locally
# ------------------------------------------------------------

$generatedValues = @{
    'POSTGRES_PASSWORD'            = New-HexSecret -Bytes 24
    'CONTROL_POSTGRES_PASSWORD'    = New-HexSecret -Bytes 24
    'CONTROL_TEST_PASSWORD'        = New-HexSecret -Bytes 24

    'CONTROL_APP_SECRET'           = New-HexSecret -Bytes 32
    'CONTROL_TOKEN_SECRET'         = New-HexSecret -Bytes 32

    'DEVICE_FINGERPRINT_KEY'       = New-HexSecret -Bytes 32
    'AUTH_IDENTIFIER_HMAC_KEY'     = New-HexSecret -Bytes 32

    # Project expects a Base64-encoded 32-byte key.
    'AUTH_TOTP_ENCRYPTION_KEY'     = New-Base64Secret -Bytes 32
}

# ------------------------------------------------------------
# Build new .env using .env.example as source of truth
# ------------------------------------------------------------

$exampleLines = Get-Content -LiteralPath $ExamplePath

$output = New-Object System.Collections.Generic.List[string]
$exampleKeys = @{}

$added = New-Object System.Collections.Generic.List[string]
$generated = New-Object System.Collections.Generic.List[string]
$preserved = New-Object System.Collections.Generic.List[string]

foreach ($line in $exampleLines) {

    if ($line -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
        $output.Add($line)
        continue
    }

    $key = $matches[1]
    $exampleValue = $matches[2]

    $exampleKeys[$key] = $true

    $existingValue = $null
    $hasExisting = $currentValues.ContainsKey($key)

    if ($hasExisting) {
        $existingValue = [string]$currentValues[$key]
    }

    # --------------------------------------------------------
    # Existing non-empty value wins by default
    # --------------------------------------------------------

    if (
        $hasExisting -and
        -not [string]::IsNullOrWhiteSpace($existingValue)
    ) {
        $value = $existingValue
        $preserved.Add($key)
    }

    # --------------------------------------------------------
    # Missing/empty locally-generated secret
    # --------------------------------------------------------

    elseif ($generatedValues.ContainsKey($key)) {
        $value = $generatedValues[$key]
        $generated.Add($key)
    }

    # --------------------------------------------------------
    # Otherwise use default from .env.example
    # --------------------------------------------------------

    else {
        $value = $exampleValue

        if (-not $hasExisting) {
            $added.Add($key)
        }
    }

    $output.Add("$key=$value")
}

# ------------------------------------------------------------
# Preserve custom variables that exist in old .env but are not
# present in current .env.example.
# ------------------------------------------------------------

$extraKeys = @(
    $currentValues.Keys |
        Where-Object {
            -not $exampleKeys.ContainsKey($_)
        } |
        Sort-Object
)

if ($extraKeys.Count -gt 0) {
    $output.Add('')
    $output.Add('# ================================================================')
    $output.Add('# Preserved legacy/custom variables from previous .env')
    $output.Add('# ================================================================')

    foreach ($key in $extraKeys) {
        $output.Add("$key=$($currentValues[$key])")
    }
}

# ------------------------------------------------------------
# Write .env
# ------------------------------------------------------------

Write-Utf8NoBom `
    -Path $EnvPath `
    -Lines ([string[]]$output.ToArray())

Write-Host ''
Write-Host '[OK] .env synchronized with .env.example' -ForegroundColor Green
Write-Host "     $EnvPath"
Write-Host ''

# ------------------------------------------------------------
# Important warning
# ------------------------------------------------------------

Write-Host 'Generated local values:' -ForegroundColor Cyan

foreach ($key in ($generated | Sort-Object -Unique)) {
    Write-Host "  + $key" -ForegroundColor Green
}

Write-Host ''

Write-Host 'IMPORTANT:' -ForegroundColor Yellow
Write-Host 'Database passwords written into .env do NOT automatically change'
Write-Host 'passwords inside an already initialized PostgreSQL volume.'
Write-Host ''
Write-Host 'Run afterwards:'
Write-Host ''
Write-Host '  .\scripts\change-platform-db-password.ps1'
Write-Host '  .\scripts\change-control-db-password.ps1'
Write-Host ''

Write-Host 'External credentials intentionally remain manual:' -ForegroundColor Yellow
Write-Host '  YOOKASSA_SECRET_KEY'
Write-Host '  AUTH_GOOGLE_CLIENT_ID'
Write-Host '  AUTH_GOOGLE_CLIENT_SECRET'
Write-Host '  AUTH_TELEGRAM_CLIENT_ID'
Write-Host '  AUTH_TELEGRAM_CLIENT_SECRET'
Write-Host '  SMTP credentials if external SMTP is used'
Write-Host ''

Write-Host 'Done.' -ForegroundColor Green
