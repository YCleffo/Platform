# Platform Windows Agent and prelogin boundary

The native source implements the dedicated test-VM path, but its C++ compilation, native execution and prelogin are **NOT_VERIFIED**. A successful .NET build or the test adapter never changes that status.

## Executed boundary

`Platform.Agent.Service` runs as LocalSystem through SCM or in explicit `--console` development mode. `Platform.Agent.Ui` is a Windows Forms application with an optional JSON console client. The ordinary `platform-agent` pipe carries versioned status, enrollment and end-session requests; OS-authenticated SID/administrator checks determine authorization. It never returns Windows passwords. A device end request reaches the authenticated backend before local sign-out can start.

`Platform.CredentialProvider` is a separate x64 COM DLL for `CPUS_LOGON`. It adds one classroom tile and does not filter, disable, replace or auto-select any built-in provider. The title is visible in both selected and unselected tiles. Activation uses CLSID `{A14BF3C8-E945-47C4-83AB-38CF95BB7F02}` and the `DllGetClassObject`/`DllCanUnloadNow` exports; there is no `DllRegisterServer` or `regsvr32` contract.

The provider first verifies that its pipe server runs as SYSTEM, then reads the current assigned session through `\\.\pipe\platform-agent`. It asks the SYSTEM-only `\\.\pipe\platform-agent-logon` broker for a 30-second DPAPI-protected grant. The broker requires confirmed enrollment, a reachable backend, `SYNCING_IN`, restored Documents, no outstanding retry, no ending request and no outstanding unexpired grant. The binary envelope contains magic `PLG1`, UTC FILETIME expiry and length-prefixed UTF-16 session ID, nonce, SID, account name and password. Session/SID/expiry checks precede Windows Negotiate serialization. A grant is not proof of interactive logon: the service independently inspects WTS token SID before reporting ACTIVE, and rechecks it on subsequent ACTIVE ticks.

## Native profile invariants

Native calls require all of: Windows, explicitly enabled native configuration, `PLATFORM_AGENT_DANGEROUS_MODE=true`, a nonexpired HKLM MachineGuid permit, and independent virtual BIOS evidence. These conditions are checked before each native operation. The installer provisions only service-local environment variables; it never enables native operations globally on the development host.

Account names hash the entire DeviceSession identity. Creation intent and protected credentials are journaled before account creation. Existing accounts must have both the exact per-session ownership marker and the Windows-resolved SID. Managed accounts must be standard users. The profile is loaded using Windows APIs; Documents is resolved with the user's token through `SHGetKnownFolderPath(FOLDERID_Documents)`, rather than guessing `C:\Users\name\Documents`. Unsupported redirection and reparse points fail closed.

Program data is rooted at `[Environment]::GetFolderPath(CommonApplicationData)\Platform\Agent`, not a relative current-working-directory path. Journals, account records and device credentials are restricted to SYSTEM/administrators. Installation binaries live under Program Files with read-only user access.

Sign-out addresses only WTS sessions with the exact managed SID. Cleanup requires ended user session, durable Storage confirmation and zero outstanding manifest/retry work. Windows account ownership/SID are checked again before profile/account removal. Tombstones preserve idempotent recovery. Lost WTS logon after a reboot enters recovery while preserving local Documents. Journal corruption is preserved; it is never replaced with an empty session.

## Build and deployment

```powershell
dotnet build .\apps\agent\Platform.Agent.Service\Platform.Agent.Service.csproj
dotnet build .\apps\agent\Platform.Agent.Ui\Platform.Agent.Ui.csproj
# C++ requires VS C++ v143 x64 tools plus Windows SDK:
msbuild .\apps\agent\Platform.CredentialProvider\Platform.CredentialProvider.vcxproj /p:Configuration=Release /p:Platform=x64
```

`Install-Service.ps1` copies a published service into Program Files, sets private ProgramData ACLs, creates/updates only the owned `PlatformAgent` service, sets its LocalSystem environment, and optionally registers the x64 provider's COM/tile keys. `Test-Service.ps1` checks the actual service IPC and configuration. `Uninstall-Service.ps1` removes only the owned SCM/COM registrations and preserves binaries, accounts, profiles, Documents and journals. Emergency registration removal may use an expired matching VM permit, but still requires virtual firmware and MachineGuid identity.

See [VM_ACCEPTANCE.md](VM_ACCEPTANCE.md) for deployment, network trust, fallback administrator access, native evidence and the complete A to B to A matrix. No installer, COM registration, profile operation, certificate/trust change or firewall modification was executed on the developer host during this task.

Microsoft contracts: [credential enumeration](https://learn.microsoft.com/en-us/windows/win32/api/credentialprovider/nf-credentialprovider-icredentialprovider-getcredentialcount), [SCM lifecycle](https://learn.microsoft.com/en-us/windows/win32/services/service-servicemain-function).
