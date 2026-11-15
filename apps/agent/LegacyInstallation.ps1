# Read-only names used to discover and safely retire an earlier installation.
function Get-PlatformLegacyInstallation {
    return @{
        ServiceName = 'KiberOneAgent'
        InstallRoot = Join-Path ([Environment]::GetFolderPath('ProgramFiles')) 'KiberOne\Agent'
        StateRoot = Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'KiberOne\Agent'
        Executable = 'kiberone-agent.exe'
        AccountPrefix = 'k1_std_'
        TestVmPermit = 'HKLM:\SOFTWARE\KiberOne\Agent\TestVm'
    }
}

function Test-PlatformManagedAccountName {
    param([Parameter(Mandatory)][string]$Name)
    $legacy = Get-PlatformLegacyInstallation
    return $Name.StartsWith('platform_', [StringComparison]::OrdinalIgnoreCase) -or $Name.StartsWith($legacy.AccountPrefix, [StringComparison]::OrdinalIgnoreCase)
}

function Get-PlatformLegacyVmPermit {
    $legacy = Get-PlatformLegacyInstallation
    return Get-ItemProperty -LiteralPath $legacy.TestVmPermit -ErrorAction SilentlyContinue
}
