<# Read-only checks in the authorized VM. Does not mark native logon as verified. #>
[CmdletBinding()]
param([Parameter(Mandatory)][switch]$ConfirmIsolatedVm, [switch]$ExpectNative, [string]$OutputPath)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'TestVmGuard.ps1')
if (-not $ConfirmIsolatedVm) { throw 'Explicit isolated-VM acknowledgement is required.' }
$expiry = Assert-PlatformTestVm -RequireElevation
$service = Get-CimInstance Win32_Service -Filter "Name='PlatformAgent'"
if (-not $service -or $service.State -ne 'Running' -or $service.StartName -ne 'LocalSystem') { throw 'The managed service must be running as LocalSystem.' }
$pipe = [IO.Pipes.NamedPipeClientStream]::new('.', 'platform-agent', [IO.Pipes.PipeDirection]::InOut,
    [IO.Pipes.PipeOptions]::Asynchronous, [Security.Principal.TokenImpersonationLevel]::Impersonation)
try {
    $pipe.Connect(3000)
    if ($PSVersionTable.PSEdition -eq 'Core') {
        Add-Type -AssemblyName System.IO.Pipes.AccessControl
        $pipeSecurity = [IO.Pipes.PipesAclExtensions]::GetAccessControl($pipe)
    }
    else { $pipeSecurity = $pipe.GetAccessControl() }
    $owner = $pipeSecurity.GetOwner([Security.Principal.SecurityIdentifier])
    if ($owner.Value -ne 'S-1-5-18') { throw 'The service pipe is not owned by SYSTEM.' }
    $writer = [IO.StreamWriter]::new($pipe)
    $writer.AutoFlush = $true
    $reader = [IO.StreamReader]::new($pipe)
    $requestId = [guid]::NewGuid().ToString()
    $writer.WriteLine((@{ version = 1; requestId = $requestId; command = 'status' } | ConvertTo-Json -Compress))
    $read = $reader.ReadLineAsync()
    if (-not $read.Wait(5000)) { throw 'The service IPC response timed out.' }
    $text = $read.Result
    if (-not $text -or $text.Length -gt 16384) { throw 'Invalid IPC response size.' }
    $reply = $text | ConvertFrom-Json
    if ($reply.version -ne 1 -or $reply.requestId -ne $requestId -or -not $reply.ok) { throw 'The service rejected or mismatched the status request.' }
    if ($ExpectNative -and $reply.state.testAdapter) { throw 'The service is using the test adapter; native logon is not enabled.' }
    if (-not $ExpectNative -and -not $reply.state.testAdapter) { throw 'Unexpected native mode.' }
    $state = Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'Platform\Agent'
    $acl = Get-Acl -LiteralPath $state
    if (-not $acl.AreAccessRulesProtected) { throw 'Private state directory inherits access permissions.' }
    foreach ($rule in $acl.Access) {
        $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
        if ($rule.AccessControlType -eq 'Allow' -and $sid -notin 'S-1-5-18', 'S-1-5-32-544') { throw "Unexpected state ACL principal: $sid" }
    }
    if ($ExpectNative) {
        $clsid = '{A14BF3C8-E945-47C4-83AB-38CF95BB7F02}'
        $dll = (Get-Item -LiteralPath "HKLM:\SOFTWARE\Classes\CLSID\$clsid\InprocServer32").GetValue('')
        $expected = Join-Path ([Environment]::GetFolderPath('ProgramFiles')) 'Platform\Agent\Platform.CredentialProvider.dll'
        if ($dll -ne $expected -or -not (Test-Path -LiteralPath $dll)) { throw 'Native COM registration does not point to the managed DLL.' }
        if (-not (Test-Path -LiteralPath "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Authentication\Credential Providers\$clsid")) { throw 'Credential Provider tile registration is missing.' }
    }
    $evidence = [ordered]@{ at = [DateTimeOffset]::UtcNow.ToString('o'); machine = $env:COMPUTERNAME; permitExpires = $expiry.ToString('o');
        IPC_VERIFIED = $true; VM_NATIVE_VERIFIED = 'NOT_VERIFIED'; PRELOGIN_VERIFIED = 'NOT_VERIFIED';
        serviceState = $service.State; testAdapter = $reply.state.testAdapter; sessionStatus = $reply.state.sessionStatus;
        serverConnected = $reply.state.serverConnected; recoveryPending = $reply.state.recoveryPending }
    $json = $evidence | ConvertTo-Json
    if ($OutputPath) { [IO.File]::WriteAllText([IO.Path]::GetFullPath($OutputPath), $json, [Text.UTF8Encoding]::new($false)) }
    Write-Output $json
}
finally { $pipe.Dispose() }
