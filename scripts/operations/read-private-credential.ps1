[CmdletBinding()]
param([Parameter(Mandatory = $true)][string]$Path)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security
$Bytes = [Security.Cryptography.ProtectedData]::Unprotect(
    [IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $Path).Path),
    $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)
try {
    $Value = [Text.Encoding]::UTF8.GetString($Bytes) | ConvertFrom-Json
    if (-not $Value.email -or -not $Value.password) { throw 'This file is not a Platform credential.' }
    [PSCredential]::new($Value.email, (ConvertTo-SecureString $Value.password -AsPlainText -Force))
} finally {
    [Array]::Clear($Bytes, 0, $Bytes.Length)
}
