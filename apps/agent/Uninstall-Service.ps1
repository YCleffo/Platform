<# Removes only this service and its COM registration. Retains all accounts, profiles, documents, journals and binaries. #>
[CmdletBinding()]
param([Parameter(Mandatory)][switch]$ConfirmIsolatedVm, [switch]$EmergencyRecovery)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'TestVmGuard.ps1')
if (-not $ConfirmIsolatedVm) { throw 'Explicit isolated-VM acknowledgement is required.' }
$null = Assert-PlatformTestVm -RequireElevation -AllowExpiredPermit:$EmergencyRecovery
$install = Join-Path ([Environment]::GetFolderPath('ProgramFiles')) 'Platform\Agent'
$target = Join-Path $install 'platform-agent.exe'
$state = Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'Platform\Agent'
Assert-PlatformNoReparsePath -Path $install
Assert-PlatformNoReparsePath -Path $state
$journalPath = Join-Path $state 'agent-state.json'
if ((Test-Path -LiteralPath $journalPath) -and -not $EmergencyRecovery) {
    $journal = Get-Content -LiteralPath $journalPath -Raw | ConvertFrom-Json
    if ($journal.SessionStatus -ne 'CLOSED' -or @($journal.RetryOperations).Count -gt 0) { throw 'Unfinished session: recover it first, or use -EmergencyRecovery to remove registration while retaining all local data.' }
}
$existing = Get-CimInstance Win32_Service -Filter "Name='PlatformAgent'"
if ($existing) {
    if ($existing.PathName.Trim('"') -ne $target) { throw 'Service points outside the managed install path.' }
    Stop-Service -Name PlatformAgent -ErrorAction Stop
    Invoke-PlatformSc -Arguments @('delete', 'PlatformAgent')
}
$clsid = '{A14BF3C8-E945-47C4-83AB-38CF95BB7F02}'
$com = "HKLM:\SOFTWARE\Classes\CLSID\$clsid"
$server = "$com\InprocServer32"
$tile = "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Authentication\Credential Providers\$clsid"
if (Test-Path -LiteralPath $server) {
    if ((Get-Item -LiteralPath $server).GetValue('') -ne (Join-Path $install 'Platform.CredentialProvider.dll')) { throw 'COM registration points outside the managed path; refusing to remove it.' }
    if (Test-Path -LiteralPath $tile) { Remove-Item -LiteralPath $tile -Recurse }
    Remove-Item -LiteralPath $com -Recurse
}
Write-Host "Removed managed service/COM registration only. All recovery state, Windows users, profiles, binaries and Documents remain at their original paths. State: $state"
