<# Installs only in an independently authorized test VM. Existing state and profiles are preserved. #>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][switch]$ConfirmIsolatedVm,
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$ServiceBinPath,
    [Parameter(Mandatory)][uri]$BaseUri,
    [switch]$EnableNative,
    [string]$CredentialProviderPath,
    [switch]$MigrateLegacyState
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'TestVmGuard.ps1')
if (-not $ConfirmIsolatedVm) { throw 'Explicit isolated-VM acknowledgement is required.' }
$expiry = Assert-PlatformTestVm -RequireElevation
if ($BaseUri.Scheme -ne 'https' -or $BaseUri.UserInfo -or $BaseUri.Query -or $BaseUri.Fragment) { throw 'Use an HTTPS backend URL without credentials, query or fragment.' }
if ($CredentialProviderPath -and -not $EnableNative) { throw 'Credential Provider installation requires -EnableNative.' }
if ($EnableNative -and -not $CredentialProviderPath) { throw 'Native prelogin deployment requires the built x64 Credential Provider DLL.' }
$source = (Resolve-Path -LiteralPath $ServiceBinPath).Path
if ([IO.Path]::GetFileName($source) -ne 'platform-agent.exe') { throw 'Select the published platform-agent.exe (net10.0-windows, win-x64).' }
$sourceDirectory = Split-Path -Parent $source
if (-not (Test-Path -LiteralPath (Join-Path $sourceDirectory 'platform-agent.runtimeconfig.json'))) { throw 'Publish the service with its runtime files before installation.' }
$install = Join-Path ([Environment]::GetFolderPath('ProgramFiles')) 'Platform\Agent'
$target = Join-Path $install 'platform-agent.exe'
$state = Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'Platform\Agent'
Assert-PlatformNoReparsePath -Path $sourceDirectory
Assert-PlatformNoReparsePath -Path $install
Assert-PlatformNoReparsePath -Path $state
$legacy = Get-PlatformLegacyInstallation
$previous = Get-CimInstance Win32_Service -Filter ("Name='" + $legacy.ServiceName + "'")
$legacyData = (Test-Path -LiteralPath $legacy.StateRoot) -and @(Get-ChildItem -LiteralPath $legacy.StateRoot -Force).Count -gt 0
$migrationReceipt = Join-Path $state 'legacy-migration.json'
if ($previous -or $legacyData) {
    if (-not $MigrateLegacyState -and -not (Test-Path -LiteralPath $migrationReceipt)) {
        throw 'An earlier installation was found. Use -MigrateLegacyState to copy and verify its state with a retained backup.'
    }
    if ($previous) {
        $previousTarget = Join-Path $legacy.InstallRoot $legacy.Executable
        if ($previous.PathName.Trim('"') -ne $previousTarget) { throw 'Previous service points outside its managed installation path.' }
        if ($previous.State -ne 'Stopped') {
            if (-not $MigrateLegacyState) { throw 'Stop the previous service through the explicit upgrade before starting Platform Agent.' }
            Stop-Service -Name $legacy.ServiceName -ErrorAction Stop
        }
    }
    if ($legacyData -and -not (Test-Path -LiteralPath $migrationReceipt)) {
        & $source --migrate-legacy-state
        if ($LASTEXITCODE -ne 0) { throw 'Verified state migration failed. Existing files and recovery data were retained.' }
    }
    if ($previous -and $MigrateLegacyState) { Set-Service -Name $legacy.ServiceName -StartupType Disabled }
}
$existing = Get-CimInstance Win32_Service -Filter "Name='PlatformAgent'"
if ($existing -and $existing.PathName.Trim('"') -ne $target) { throw 'Existing PlatformAgent registration points elsewhere; refusing to replace an unrelated service.' }
if ($existing) {
    $journalPath = Join-Path $state 'agent-state.json'
    if (Test-Path -LiteralPath $journalPath) {
        $journal = Get-Content -LiteralPath $journalPath -Raw | ConvertFrom-Json
        if ($journal.SessionStatus -ne 'CLOSED' -or @($journal.RetryOperations).Count -gt 0) { throw 'Finish or recover the current session before updating service binaries.' }
    }
    Stop-Service -Name PlatformAgent -ErrorAction Stop
}
Set-PlatformPrivateDirectory -Path $install -AllowRead
Set-PlatformPrivateDirectory -Path $state
if ($sourceDirectory -ne $install) {
    foreach ($file in Get-ChildItem -LiteralPath $sourceDirectory -File) {
        if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Source binaries must not be reparse points.' }
        Copy-Item -LiteralPath $file.FullName -Destination $install -Force
    }
}
$binArgument = '"' + $target + '"'
if ($existing) { Invoke-PlatformSc -Arguments @('config', 'PlatformAgent', 'binPath=', $binArgument, 'start=', 'delayed-auto', 'obj=', 'LocalSystem') }
else { Invoke-PlatformSc -Arguments @('create', 'PlatformAgent', 'binPath=', $binArgument, 'start=', 'delayed-auto', 'obj=', 'LocalSystem', 'DisplayName=', 'Platform Classroom Agent') }
Invoke-PlatformSc -Arguments @('description', 'PlatformAgent', 'Managed classroom sessions; native operations require an expiring test VM permit.')
Invoke-PlatformSc -Arguments @('failure', 'PlatformAgent', 'reset=', '86400', 'actions=', 'restart/30000/restart/30000//')
$environment = @("PLATFORM_AGENT_BASE_URL=$($BaseUri.AbsoluteUri)", "PLATFORM_AGENT_STATE_DIR=$state", 'PLATFORM_AGENT_ALLOW_INSECURE_HTTP=0', 'PLATFORM_AGENT_ALLOW_CONSOLE_IPC=0')
if ($EnableNative) { $environment += 'PLATFORM_AGENT_DANGEROUS_WINDOWS_MODE=1', 'PLATFORM_AGENT_DANGEROUS_MODE=true' }
else { $environment += 'PLATFORM_AGENT_DANGEROUS_WINDOWS_MODE=0', 'PLATFORM_AGENT_DANGEROUS_MODE=false' }
New-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Services\PlatformAgent' -Name Environment -PropertyType MultiString -Value $environment -Force | Out-Null
if ($CredentialProviderPath) {
    $provider = (Resolve-Path -LiteralPath $CredentialProviderPath).Path
    $bytes = [IO.File]::ReadAllBytes($provider)
    if ($bytes.Length -lt 256 -or [BitConverter]::ToUInt16($bytes, 0) -ne 0x5a4d) { throw 'Credential Provider is not a PE DLL.' }
    $pe = [BitConverter]::ToInt32($bytes, 0x3c)
    if ($pe -lt 0 -or $pe + 24 -gt $bytes.Length -or [BitConverter]::ToUInt32($bytes, $pe) -ne 0x4550 -or [BitConverter]::ToUInt16($bytes, $pe + 4) -ne 0x8664) { throw 'Credential Provider must be an MSVC x64 DLL.' }
    $dll = Join-Path $install 'Platform.CredentialProvider.dll'
    if ($provider -ne $dll) { Copy-Item -LiteralPath $provider -Destination $dll -Force }
    $clsid = '{A14BF3C8-E945-47C4-83AB-38CF95BB7F02}'
    $com = "HKLM:\SOFTWARE\Classes\CLSID\$clsid\InprocServer32"
    $tile = "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Authentication\Credential Providers\$clsid"
    New-Item -Path $com -Force | Out-Null
    Set-Item -LiteralPath $com -Value $dll
    New-ItemProperty -LiteralPath $com -Name ThreadingModel -Value Apartment -PropertyType String -Force | Out-Null
    New-Item -Path $tile -Force | Out-Null
    Set-Item -LiteralPath $tile -Value 'Platform Classroom (test VM)'
}
Start-Service -Name PlatformAgent
Write-Host "Installed in test VM. Permit expires $($expiry.ToString('o')). State remains at $state."
Write-Host 'Native logon and prelogin remain NOT_VERIFIED until the manual VM acceptance matrix is executed.'
