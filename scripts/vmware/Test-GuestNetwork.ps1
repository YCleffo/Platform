<# Guest-only trust and HTTPS check. Windows performs normal chain, hostname/SAN and expiry validation. #>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][switch]$ConfirmIsolatedVm,
    [Parameter(Mandatory)][uri]$BaseUri,
    [Parameter(Mandatory)][string]$RootCertificate,
    [Parameter(Mandatory)][ValidatePattern('^[a-fA-F0-9]{40}$')][string]$ExpectedRootThumbprint,
    [switch]$InstallTrust
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\..\apps\agent\TestVmGuard.ps1')
if (-not $ConfirmIsolatedVm) { throw 'Explicit isolated-VM acknowledgement is required.' }
$null = Assert-PlatformTestVm -RequireElevation:$InstallTrust
if ($BaseUri.Scheme -ne 'https' -or $BaseUri.IsLoopback -or $BaseUri.UserInfo -or $BaseUri.Query -or $BaseUri.Fragment) { throw 'Use the host VMware adapter HTTPS address, never guest localhost.' }
$certificate = [Security.Cryptography.X509Certificates.X509Certificate2]::new((Resolve-Path -LiteralPath $RootCertificate).Path)
try {
    if ($certificate.Thumbprint -ne $ExpectedRootThumbprint) { throw 'Root fingerprint differs from the independent host output. Refusing to trust it.' }
    if ($certificate.HasPrivateKey) { throw 'Copy only the public root certificate into the guest.' }
    if ($InstallTrust) { Import-Certificate -FilePath $RootCertificate -CertStoreLocation 'Cert:\LocalMachine\Root' | Out-Null }
    if (-not (Test-Path -LiteralPath "Cert:\LocalMachine\Root\$ExpectedRootThumbprint")) { throw 'The public test CA must be installed in LocalMachine Root for the LocalSystem agent. Use -InstallTrust in the authorized guest.' }
    $connection = Test-NetConnection -ComputerName $BaseUri.DnsSafeHost -Port $BaseUri.Port -WarningAction SilentlyContinue
    if (-not $connection.TcpTestSucceeded) { throw 'VMware adapter TCP path is unavailable; check adapter IP, guest network and scoped firewall rule.' }
    $response = Invoke-WebRequest -Uri ([uri]::new($BaseUri, '/health/ready')) -UseBasicParsing -TimeoutSec 15
    if ($response.StatusCode -ne 200) { throw "Backend readiness returned $($response.StatusCode)." }
    Write-Host 'PASS: guest TCP reachability, trusted TLS chain, IP SAN/hostname, expiry and backend readiness. No TLS validation was bypassed.'
}
finally { $certificate.Dispose() }
