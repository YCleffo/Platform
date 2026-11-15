<# Host-only certificate preparation. Does not bind ports, modify firewall or trust stores, or operate Windows profiles. #>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$AdapterName,
    [Parameter(Mandatory)][Security.SecureString]$PfxPassword,
    [string]$OutputDirectory = (Join-Path $PSScriptRoot 'runtime')
)
$ErrorActionPreference = 'Stop'
$adapter = Get-NetAdapter -Name $AdapterName
if ($adapter.InterfaceDescription -notmatch '(?i)VMware') { throw 'Select the VMware virtual adapter explicitly.' }
$addresses = @(Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '169.254.*' })
if ($addresses.Count -ne 1) { throw 'The selected VMware adapter must have exactly one usable IPv4 address.' }
$address = $addresses[0].IPAddress
$output = [IO.Path]::GetFullPath($OutputDirectory)
. (Join-Path $PSScriptRoot '..\..\apps\agent\TestVmGuard.ps1')
Assert-PlatformNoReparsePath -Path $output
New-Item -ItemType Directory -Path $output -Force | Out-Null
$security = [Security.AccessControl.DirectorySecurity]::new()
$security.SetAccessRuleProtection($true, $false)
foreach ($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value, 'S-1-5-18', 'S-1-5-32-544') | Select-Object -Unique) {
    $security.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new($sid), 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
}
Set-Acl -LiteralPath $output -AclObject $security
foreach ($file in 'vmware-root.cer', 'vmware-server.pfx') {
    if (Test-Path -LiteralPath (Join-Path $output $file)) { throw 'Certificate files already exist; choose a new output directory to preserve them.' }
}
$root = New-SelfSignedCertificate -Type Custom -Subject ('CN=Platform VMware Test CA ' + [guid]::NewGuid().ToString('N')) `
    -KeySpec Signature -KeyExportPolicy NonExportable -KeyLength 3072 -HashAlgorithm SHA256 `
    -KeyUsage CertSign,CRLSign,DigitalSignature -TextExtension @('2.5.29.19={critical}{text}ca=1&pathlength=0') `
    -CertStoreLocation 'Cert:\CurrentUser\My' -NotAfter ([DateTime]::Now.AddDays(14))
$server = New-SelfSignedCertificate -Type Custom -Subject "CN=$address" -Signer $root `
    -KeySpec KeyExchange -KeyExportPolicy Exportable -KeyLength 2048 -HashAlgorithm SHA256 `
    -KeyUsage DigitalSignature,KeyEncipherment -TextExtension @("2.5.29.17={text}IPAddress=$address", '2.5.29.37={text}1.3.6.1.5.5.7.3.1', '2.5.29.19={critical}{text}ca=0') `
    -CertStoreLocation 'Cert:\CurrentUser\My' -NotAfter ([DateTime]::Now.AddDays(7))
Export-Certificate -Cert $root -FilePath (Join-Path $output 'vmware-root.cer') | Out-Null
Export-PfxCertificate -Cert $server -Password $PfxPassword -FilePath (Join-Path $output 'vmware-server.pfx') -ChainOption BuildChain | Out-Null
Write-Host "VMware address: $address"
Write-Host "Root certificate thumbprint (compare independently in guest): $($root.Thumbprint)"
Write-Host "Public root: $(Join-Path $output 'vmware-root.cer')"
Write-Host "Server PFX: $(Join-Path $output 'vmware-server.pfx') (keep on host; never copy to guest)"
