<# Run manually in the isolated VM after taking a snapshot and proving separate administrator sign-in. #>
[CmdletBinding()]
param([Parameter(Mandatory)][switch]$ConfirmIsolatedVm, [Parameter(Mandatory)][string]$RecoveryAdministrator, [ValidateRange(1,24)][int]$Hours = 8)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'LegacyInstallation.ps1')
if (-not $ConfirmIsolatedVm -or -not [Environment]::Is64BitProcess) { throw 'Explicit isolated VM acknowledgement and x64 PowerShell are required.' }
$principal = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run as the independent VM administrator.' }
$bios = Get-ItemProperty -LiteralPath 'HKLM:\HARDWARE\DESCRIPTION\System\BIOS'
if ("$($bios.SystemManufacturer) $($bios.SystemProductName)" -notmatch '(?i)virtual machine|vmware|virtualbox|kvm|qemu|xen|parallels') { throw 'Virtual firmware evidence is missing. This script must never authorize the developer host.' }
$user = Get-LocalUser -Name $RecoveryAdministrator
if (-not $user.Enabled -or (Test-PlatformManagedAccountName -Name $user.Name)) { throw 'Select an enabled administrator account outside agent management.' }
$administrators = Get-LocalGroup -SID 'S-1-5-32-544'
if (-not (Get-LocalGroupMember -Group $administrators | Where-Object { $_.SID.Value -eq $user.SID.Value })) { throw 'Recovery account is not a local administrator.' }
$machine = (Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography').MachineGuid
if (-not $machine) { throw 'MachineGuid is unavailable.' }
$key = 'HKLM:\SOFTWARE\Platform\Agent\TestVm'
New-Item -Path $key -Force | Out-Null
$expiry = [DateTimeOffset]::UtcNow.AddHours($Hours).ToString('o')
New-ItemProperty -LiteralPath $key -Name MachineGuid -PropertyType String -Value $machine -Force | Out-Null
New-ItemProperty -LiteralPath $key -Name ExpiresAt -PropertyType String -Value $expiry -Force | Out-Null
Write-Host "Authorized this test VM until $expiry. No account, profile or Credential Provider was changed."
