# Dedicated Windows VM acceptance

This run prepared guarded native deployment and a real HTTPS bridge. It did not execute profile/COM operations on the developer host, and it did not have an independently authorized guest available for native acceptance. Keep VM_NATIVE and PRELOGIN at NOT_VERIFIED until the evidence below is collected in the guest.

## Recorded results, 2026-11-13

| Evidence class | Result and actual boundary |
| --- | --- |
| UNIT_VERIFIED | PASS: 28 Agent.Tests and 20 RegressionTests. Controlled HTTP/WTS doubles and isolated temporary Documents; includes A to B to A, offline retention, corrupt journal, missing Documents, confirmed cleanup, version conflicts and lost-logon-after-restart recovery. |
| API_VERIFIED | PASS: 6 Agent.HttpScenario checkpoints against production Device/Storage HTTP actions, credential middleware and separate PostgreSQL `classroom02_test`. Teacher setup/control is CLI-only; this is not the full app pipeline. |
| IPC_VERIFIED | PASS: actual disposable console-service named pipe, test adapter; status, unknown command, session binding, malformed JSON and identity injection. No native logon broker grant was issued. |
| VM_NATIVE_VERIFIED | NOT_VERIFIED: no authorized guest native execution. |
| PRELOGIN_VERIFIED | NOT_VERIFIED: no guest LogonUI/Winlogon evidence. |
| STORAGE_RECOVERY_VERIFIED | PASS at HTTP/PostgreSQL/test-profile layer: A to B to A restores persistent bytes and excludes other student files; keep-server conflict retains/restores both versions. Native Known Folders/Windows ACL/reboot acceptance remains NOT_VERIFIED. |
| Managed builds | PASS: targeted Service and UI .NET builds, zero warnings/errors. |
| MSVC v145 x64 | PASS: Release x64 build of `Platform.CredentialProvider.vcxproj` via Visual Studio 18 MSVC v145 succeeds cleanly; produces `Platform.CredentialProvider.dll`. |
| VMware HTTPS | Bridge .NET build PASS, zero warnings/errors; PowerShell syntax parsed. Live certificate creation, firewall rule, guest trust/SAN/TCP/readiness are NOT_VERIFIED. |
| VM deployment scripts | PowerShell parser PASS; no live SCM/COM installation/uninstallation was executed. |

Executor: `/root/windows_qa`; provided `CODEX_SESSION_ID=01a1d466-111f-7260-abd6-6d698fd777b2`, `CODEX_THREAD_ID=01a1d466-e3b2-7613-9703-b28c2862e5cb`.

The HTTP test created a new isolated `classroom02_test` database and `platform-classroom02-http` container on host loopback port 18762. It never dropped/recreated the working database or removed existing containers/volumes. Its container was stopped after successful checks; database and fixture runtime remain for inspection. Disposable successful test-profile directories and their generated credential environment file were the only test-created files cleaned by the existing harness. Fixture runtime: `C:\Users\Yuran\AppData\Local\Temp\platform-windows-http-20261113`. Do not publish the fixture JSON: it contains enrollment material.

## Prepare the guest

1. Use a dedicated Windows x64 VMware VM with a snapshot. Prove sign-in using an enabled local administrator outside the `platform_*` namespace. Keep its credentials outside the agent and verify the built-in password provider remains available before installing the classroom tile.
2. Install .NET 10 Windows Desktop Runtime, or publish self-contained packages. Build the separate provider with MSVC v143 x64/Windows SDK. A .NET solution build is insufficient for the provider.
3. Copy only published deployment artifacts and the public TLS root certificate into the guest. Never copy the host PFX/private key. Run x64 PowerShell elevated in the guest.
4. Grant a short-lived MachineGuid permit after proving the fallback administrator login. The script independently checks virtual firmware and administrator membership; it never authorizes a physical development host.

```powershell
# Build/publish on development machine, without installing anything:
dotnet publish .\apps\agent\Platform.Agent.Service\Platform.Agent.Service.csproj -c Release -r win-x64 --self-contained false -o .\runtime\vm-service
dotnet publish .\apps\agent\Platform.Agent.Ui\Platform.Agent.Ui.csproj -c Release -r win-x64 --self-contained false -o .\runtime\vm-ui
msbuild .\apps\agent\Platform.CredentialProvider\Platform.CredentialProvider.vcxproj /p:Configuration=Release /p:Platform=x64

# Guest only, from the copied agent scripts directory:
.\Authorize-TestVm.ps1 -ConfirmIsolatedVm -RecoveryAdministrator 'VmRecoveryAdmin' -Hours 8
```

Permit expiration blocks new native operations. Renew it only in the same dedicated guest. An interrupted/expired session retains its profile and Documents for recovery; expiration must never trigger cleanup.

## Reach the host through VMware HTTPS

VMware NAT does not expose host `127.0.0.1` as guest localhost. The existing backend remains on host loopback. `scripts/vmware/HostHttpsProxy` forwards HTTPS on the explicitly selected VMware adapter IPv4 address to that loopback HTTP origin. It rejects wildcard/physical/loopback listeners. It streams bytes, preserves API authorization/no-store responses, follows no redirects and logs no request URLs or credentials.

On the host, identify the actual VMware adapter and guest IP, then prepare a seven-day server certificate with the adapter IP SAN under a fourteen-day local CA. Certificate creation uses CurrentUser My; it does not add trust to any host Root store. These commands are preparation instructions and were not executed in this run.

```powershell
Get-NetAdapter | Where-Object InterfaceDescription -Match VMware
$pfxPassword = Read-Host 'Local test PFX password' -AsSecureString
.\scripts\vmware\New-HostHttpsCertificate.ps1 -AdapterName 'VMware Network Adapter VMnet8' -PfxPassword $pfxPassword
# Supply the actual guest IPv4. The optional firewall rule is limited to this
# adapter, local IP, TCP port and one guest IP; it requires host elevation.
.\scripts\vmware\Start-HostHttpsProxy.ps1 -AdapterName 'VMware Network Adapter VMnet8' -PfxPath .\scripts\vmware\runtime\vmware-server.pfx -PfxPassword $pfxPassword -ConfigureFirewall -GuestAddress $actualGuestIp
```

Copy `vmware-root.cer` only. Compare its root thumbprint with the host console through a separate trusted channel. In the authorized guest:

```powershell
# Use the actual host VMware adapter IP printed by certificate preparation:
$baseUri = 'https://' + $actualHostVmwareIp + ':8443/'
.\scripts\vmware\Test-GuestNetwork.ps1 -ConfirmIsolatedVm -BaseUri $baseUri -RootCertificate C:\VmTest\vmware-root.cer -ExpectedRootThumbprint $verifiedHostThumbprint -InstallTrust
```

The guest script imports only the matching public root into LocalMachine Root for the LocalSystem agent, then performs TCP and `/health/ready` HTTPS checks using ordinary Windows certificate chain, SAN/name and expiry validation. There is no validation callback, global TLS override, insecure remote HTTP exception, or `SkipCertificateCheck`. A failure remains a failure. Stop the foreground bridge with Ctrl+C. After testing, remove only the named firewall rule reported by the script and the exact test root from the dedicated guest trust store; retain unrelated rules/certificates.

## Install and inspect in guest

```powershell
.\Install-Service.ps1 -ConfirmIsolatedVm -ServiceBinPath C:\VmTest\service\platform-agent.exe -BaseUri $baseUri -EnableNative -CredentialProviderPath C:\VmTest\Platform.CredentialProvider.dll
.\Test-Service.ps1 -ConfirmIsolatedVm -ExpectNative -OutputPath C:\VmTest\service-evidence.json
```

The service is registered as LocalSystem under the fixed `PlatformAgent` name. State is in actual ProgramData `Platform\Agent`; binaries are copied under actual Program Files `Platform\Agent`. Existing owned service registration is updated in place. An unfinished session blocks binary replacement. Standard providers are never filtered or disabled. The status script explicitly leaves native/prelogin evidence NOT_VERIFIED even when service/COM checks pass.

For an earlier installation, append `-MigrateLegacyState` to the install command. Verify that its source journal, recovery records and Documents remain present, the retained backup matches the copied files, and the new service resumes the same unfinished session. A missing migration receipt must block default startup. Confirm that account SID ownership survives recovery and that the earlier service remains stopped and disabled. These native upgrade observations still require the dedicated VM run.

Open the published UI in the guest. Enroll with a real one-time code from the organization's Devices page using an administrator. Check device online status in the teacher UI. Prepare real students A and B and record their actual IDs. Preserve screenshots/evidence with device, session and manifest IDs; keep credentials, password envelopes and enrollment codes out of logs/screenshots.

## Native manual matrix

Every row below remains NOT_VERIFIED for native execution in this run. Record guest machine identity, date, device/session IDs, observed result, and an artifact for each row. Mark the two accounts A/B in evidence rather than relying on display names.

| Scenario | Required evidence and acceptance |
| --- | --- |
| Registration | Administrator enrollment succeeds through the actual HTTPS API; teacher sees the correct device. Ordinary guest user cannot enroll or read the protected journal. |
| Assignment A | Teacher assigns A. Service reaches SYNCING_IN/awaiting Windows logon with restored Documents. ACTIVE is not reported before interactive WTS token evidence. |
| Prelogin A | Sign out the administrator, select the classroom tile manually, and complete a real Windows sign-in. `whoami /user` in A matches the exact Windows-resolved managed SID. Capture LogonUI selection plus WTS/API ACTIVE evidence. |
| Documents A | In A, `[Environment]::GetFolderPath('MyDocuments')` identifies the actual user Known Folder; seeded A file content/hash matches Storage. Create a unique A file there. No ProgramData imitation counts as Known Folder proof. |
| Exit and confirmed sync | End through the UI or teacher's actual end action. Observe WTS sign-out, upload/confirmed manifest and only then cleanup/CLOSED. Verify server hash/version. A local success toast alone is insufficient. |
| B isolation | Assign and really sign in B. SID differs; B sees B files and cannot open A's Documents using its old absolute path. B creates a unique file, then exits with confirmed sync. |
| A again | New authorized A session restores A's previous edit/unique file from Storage, excludes B's unique file, and ends with confirmed cleanup. Account reuse based on a name prefix is forbidden. |
| Conflict | Change the same path through the application and A's Documents. Ending must retain local data and require the teacher's actual conflict resolution. Keep-server preserves the unique local bytes as a conflict copy; both versions restore on the next A session. |
| Offline/recovery | Disconnect only guest networking while A edits. End/upload failure retains local Documents and durable retry state and blocks B assignment. Reconnect, retry using the supported teacher action, and verify confirmed Storage before cleanup. |
| Erroneous cleanup | With pending/failed manifest or missing Documents root, verify cleanup is blocked and existing server files are not interpreted as local deletions. Use snapshot-backed test files only. |
| Unmanaged profiles | Put a sentinel file in the separate recovery administrator's profile before the A/B cycle. Its SID/account/profile/sentinel hash remain unchanged. Agent scripts contain no broad profile or user deletion. |
| Reboot | Reboot the dedicated VM with an interrupted session. Retain journal/Documents. Stale WindowsLogonConfirmed must not keep ACTIVE without a current matching WTS token; recovery blocks cleanup until confirmed sync. Repeat during upload/conflict recovery and validate saved bytes. |
| Provider failure | With a guest snapshot and separate administrator verified, stop the agent so the classroom tile cannot obtain a grant. It must fail closed while the built-in administrator password provider still signs in. Restore service and repeat. |
| Uninstall/recovery | Finish session first, then remove only this provider/service registration. Verify built-in login remains usable and existing accounts, profiles, Documents, journals and binaries were retained. |

## Remove registration without losing recovery data

```powershell
# Guest only, after normal session completion:
.\Uninstall-Service.ps1 -ConfirmIsolatedVm
# If the provider/service is faulty, sign in with the separate administrator.
# Emergency registration removal keeps unfinished profiles and journals, even
# with an expired matching guest permit. It does not delete users or data:
.\Uninstall-Service.ps1 -ConfirmIsolatedVm -EmergencyRecovery
```

If ordinary logon cannot be reached, use the guest snapshot or Windows Recovery offline registry tools to remove only CLSID `{A14BF3C8-E945-47C4-83AB-38CF95BB7F02}` under `SOFTWARE\Microsoft\Windows\CurrentVersion\Authentication\Credential Providers` and its own `SOFTWARE\Classes\CLSID` registration. Do not disable/delete any built-in provider, delete profile directories, reset the Storage manifest, or discard the journal. Resume recovery from the retained state after restoring administrator access.
