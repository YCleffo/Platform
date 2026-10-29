[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$Template = Join-Path $Root '.env.example'
$Target = Join-Path $Root '.env'
$Values = @{}
if (Test-Path -LiteralPath $Target) {
    foreach ($Line in [IO.File]::ReadAllLines($Target)) {
        if ($Line -match '^([A-Z0-9_]+)=(.*)$') { $Values[$Matches[1]] = $Matches[2] }
    }
}
function New-Secret([switch]$Base64) {
    $Bytes = New-Object byte[] 32
    $Rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $Rng.GetBytes($Bytes) } finally { $Rng.Dispose() }
    if ($Base64) { return [Convert]::ToBase64String($Bytes) }
    return -join ($Bytes | ForEach-Object { $_.ToString('x2') })
}
$Changed = New-Object 'System.Collections.Generic.List[string]'
foreach ($Key in @('POSTGRES_PASSWORD','CONTROL_POSTGRES_PASSWORD','CONTROL_APP_SECRET','CONTROL_TOKEN_SECRET','DEVICE_FINGERPRINT_KEY','AUTH_IDENTIFIER_HMAC_KEY')) {
    if (-not $Values.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace($Values[$Key])) {
        $Values[$Key] = New-Secret
        $Changed.Add($Key)
    }
}
foreach ($Key in @('AUTH_TOTP_ENCRYPTION_KEY','CONTROL_TOTP_ENCRYPTION_KEY')) {
    if (-not $Values.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace($Values[$Key])) {
        $Values[$Key] = New-Secret -Base64
        $Changed.Add($Key)
    }
}
$Derived = @{
    DB_HOST = 'postgres'; DB_PORT = '5432'
    DB_NAME = $(if ($Values.ContainsKey('POSTGRES_DB')) { $Values['POSTGRES_DB'] } else { 'platform' })
    DB_USER = $(if ($Values.ContainsKey('POSTGRES_USER')) { $Values['POSTGRES_USER'] } else { 'platform_app' })
    DB_PASSWORD = $Values['POSTGRES_PASSWORD']
}
foreach ($Key in $Derived.Keys) {
    if (-not $Values.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace($Values[$Key])) { $Values[$Key] = $Derived[$Key] }
}
# Local backup and disposable test services are not part of the runtime.
$Values['BACKUP_ENABLED'] = 'false'
$Output = New-Object 'System.Collections.Generic.List[string]'
$Seen = @{}
foreach ($Line in [IO.File]::ReadAllLines($Template)) {
    if ($Line -match '^([A-Z0-9_]+)=(.*)$') {
        $Key = $Matches[1]; $Default = $Matches[2]; $Seen[$Key] = $true
        $Value = if ($Values.ContainsKey($Key) -and -not [string]::IsNullOrWhiteSpace($Values[$Key])) { $Values[$Key] } else { $Default }
        $Output.Add("$Key=$Value")
    } else { $Output.Add($Line) }
}
foreach ($Key in ($Values.Keys | Sort-Object)) {
    if (-not $Seen.ContainsKey($Key) -and $Key -notmatch '^(CONTROL_TEST_|POSTGRES_TEST_)') { $Output.Add("$Key=$($Values[$Key])") }
}
[IO.File]::WriteAllLines($Target, $Output, (New-Object Text.UTF8Encoding($false)))
$Account = [Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls $Target /inheritance:r /grant:r "${Account}:F" '*S-1-5-18:F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Failed to restrict .env permissions.' }
Write-Host ('Environment updated; generated secret fields: ' + ($Changed -join ', '))
