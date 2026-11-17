# VM-only Credential Provider

This x64 COM DLL supplies one manually selected `CPUS_LOGON` tile. The title is visible when the tile is unselected. It never disables or filters built-in providers. `GetSerialization` requests a session-bound, expiring DPAPI envelope from the SYSTEM-only broker and returns Windows Negotiate serialization; the service separately verifies WTS SID before ACTIVE.

```powershell
msbuild .\KiberOne.CredentialProvider.vcxproj /p:Configuration=Release /p:Platform=x64
```

Requires MSVC v143 x64 and Windows SDK/C++ WinRT headers. The .NET solution does not build this native project. On this task's development host the explicit build failed with MSB4019 because `Microsoft.Cpp.Default.props` is absent; compilation and prelogin remain NOT_VERIFIED.

Use [the guarded installer](../Install-Service.ps1) with the built x64 DLL; `regsvr32` is not supported. Read [VM acceptance](../VM_ACCEPTANCE.md) before deployment. Installer/uninstaller touch only this component's exact COM/tile CLSID. Separate administrator sign-in and a snapshot remain the recovery path. No host registration was performed.