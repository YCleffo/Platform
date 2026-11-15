Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'LegacyInstallation.ps1')

function Assert-PlatformTestVm {
    param([switch]$RequireElevation, [switch]$AllowExpiredPermit)
    if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT -or -not [Environment]::Is64BitProcess) {
        throw 'Run x64 PowerShell inside the dedicated Windows test VM.'
    }
    if ($RequireElevation) {
        $principal = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
        if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Administrator elevation is required in the test VM.' }
    }
    $bios = Get-ItemProperty -LiteralPath 'HKLM:\HARDWARE\DESCRIPTION\System\BIOS'
    $description = "$($bios.SystemManufacturer) $($bios.SystemProductName)"
    if ($description -notmatch '(?i)virtual machine|vmware|virtualbox|kvm|qemu|xen|parallels') {
        throw 'Independent virtual firmware evidence is missing. Native operations are forbidden on this machine.'
    }
    $machine = (Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography').MachineGuid
    $permit = Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Platform\Agent\TestVm' -ErrorAction SilentlyContinue
    if (-not $permit) { $permit = Get-PlatformLegacyVmPermit }
    if (-not $permit) { throw 'An administrator-provisioned test VM permit is required.' }
    $expires = [DateTimeOffset]::Parse($permit.ExpiresAt, [Globalization.CultureInfo]::InvariantCulture)
    if (-not $machine -or $machine -ne $permit.MachineGuid -or (-not $AllowExpiredPermit -and $expires -le [DateTimeOffset]::UtcNow)) {
        throw 'A valid administrator-provisioned MachineGuid/ExpiresAt test VM permit is required.'
    }
    return $expires
}

function Assert-PlatformNoReparsePath {
    param([Parameter(Mandatory)][string]$Path)
    $current = [IO.DirectoryInfo]::new([IO.Path]::GetFullPath($Path))
    while ($null -ne $current) {
        if ((Test-Path -LiteralPath $current.FullName) -and ((Get-Item -LiteralPath $current.FullName -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw "Reparse points are forbidden in deployment paths: $($current.FullName)"
        }
        $current = $current.Parent
    }
}

function Set-PlatformPrivateDirectory {
    param([Parameter(Mandatory)][string]$Path, [switch]$AllowRead)
    Assert-PlatformNoReparsePath -Path $Path
    New-Item -ItemType Directory -Path $Path -Force | Out-Null
    $security = [Security.AccessControl.DirectorySecurity]::new()
    $security.SetAccessRuleProtection($true, $false)
    foreach ($sid in 'S-1-5-18', 'S-1-5-32-544') {
        $security.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new($sid), 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
    }
    if ($AllowRead) {
        $security.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new('S-1-5-32-545'), 'ReadAndExecute', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
    }
    Set-Acl -LiteralPath $Path -AclObject $security
}

function Invoke-PlatformSc {
    param([Parameter(Mandatory)][string[]]$Arguments)
    & "$env:SystemRoot\System32\sc.exe" @Arguments | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "SCM command failed with exit code $LASTEXITCODE." }
}
