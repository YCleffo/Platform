<# Starts a foreground HTTPS bridge on one VMware adapter. Firewall changes require an explicit switch and a single guest IP. #>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$AdapterName,
    [Parameter(Mandatory)][string]$PfxPath,
    [Parameter(Mandatory)][Security.SecureString]$PfxPassword,
    [uri]$BackendUri = 'http://127.0.0.1:8080',
    [ValidateRange(1024,65535)][int]$Port = 8443,
    [switch]$ConfigureFirewall,
    [ipaddress]$GuestAddress
)
$ErrorActionPreference = 'Stop'
$adapter = Get-NetAdapter -Name $AdapterName
if ($adapter.InterfaceDescription -notmatch '(?i)VMware') { throw 'Select a VMware virtual adapter.' }
$addresses = @(Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '169.254.*' })
if ($addresses.Count -ne 1) { throw 'The selected VMware adapter must have one usable IPv4 address.' }
$address = $addresses[0].IPAddress
if ($BackendUri.Scheme -ne 'http' -or -not $BackendUri.IsLoopback -or $BackendUri.AbsolutePath -ne '/' -or $BackendUri.Query -or $BackendUri.Fragment -or $BackendUri.UserInfo) { throw 'Backend must be a loopback HTTP origin.' }
if ($ConfigureFirewall) {
    if (-not $GuestAddress -or $GuestAddress.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork -or [ipaddress]::IsLoopback($GuestAddress)) { throw '-ConfigureFirewall requires a specific non-loopback guest IPv4 address.' }
    $name = "PlatformVmHttps-$Port-$($GuestAddress.ToString().Replace('.', '-'))"
    $existing = Get-NetFirewallRule -Name $name -ErrorAction SilentlyContinue
    if ($existing -and $existing.Group -ne 'Platform VMware test bridge') { throw 'An unrelated firewall rule has the requested name.' }
    if (-not $existing) {
        New-NetFirewallRule -Name $name -DisplayName $name -Group 'Platform VMware test bridge' -Direction Inbound -Action Allow `
            -Protocol TCP -LocalAddress $address -LocalPort $Port -RemoteAddress $GuestAddress.ToString() -InterfaceAlias $AdapterName -Profile Any | Out-Null
    }
    else {
        Set-NetFirewallRule -Name $name -Direction Inbound -Action Allow -Enabled True -Protocol TCP `
            -LocalAddress $address -LocalPort $Port -RemoteAddress $GuestAddress.ToString() -InterfaceAlias $AdapterName -Profile Any | Out-Null
    }
    Write-Host "Scoped firewall rule: $name (remove explicitly after VM testing)."
}
$env:PLATFORM_PROXY_LISTEN = $address
$env:PLATFORM_PROXY_ADAPTER = $AdapterName
$env:PLATFORM_PROXY_BACKEND = $BackendUri.AbsoluteUri
$env:PLATFORM_PROXY_PORT = $Port.ToString()
$env:PLATFORM_PROXY_PFX = (Resolve-Path -LiteralPath $PfxPath).Path
$secret = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($PfxPassword)
try {
    $env:PLATFORM_PROXY_PFX_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($secret)
    Write-Host "Serving https://${address}:$Port -> $($BackendUri.AbsoluteUri); Ctrl+C stops the bridge."
    & dotnet run --project (Join-Path $PSScriptRoot 'HostHttpsProxy\HostHttpsProxy.csproj') --no-launch-profile
    if ($LASTEXITCODE -ne 0) { throw "HTTPS proxy exited with $LASTEXITCODE." }
}
finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($secret)
    foreach ($name in 'PLATFORM_PROXY_LISTEN','PLATFORM_PROXY_ADAPTER','PLATFORM_PROXY_BACKEND','PLATFORM_PROXY_PORT','PLATFORM_PROXY_PFX','PLATFORM_PROXY_PFX_PASSWORD') { [Environment]::SetEnvironmentVariable($name, $null, 'Process') }
}
