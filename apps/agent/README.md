# Platform Agent

An existing installation upgrades through `Install-Service.ps1 -MigrateLegacyState`. The installer stops its earlier owned service, invokes the published service's `--migrate-legacy-state` operation and disables the earlier registration after successful migration. The operation verifies a retained backup and a staged copy before publishing `ProgramData\Platform\Agent`. It preserves unfinished sessions, Documents, managed account SIDs and protected credentials. Conflicting journals and orphaned account records stop the migration for recovery. Service startup checks the migration receipt before using the default state location. Historical names appear in the explicit `LegacyInstallation` compatibility files to locate and read earlier data.

Windows service and desktop UI for managed classroom sessions and Documents synchronization through the existing Platform DeviceManagement and Storage APIs.

The production Core accepts `ACTIVE` only from the enrolled Agent with a prepared profile and confirmed Windows logon. The synthetic adapter sends `testAdapter: true` with no Windows logon confirmation. Core enables it through isolated test configuration with a browser nonce and the existing runtime isolation guard, or through the dedicated Classroom HTTP harness after it verifies the `classroom02_test` database.

An authorized end command and confirmation that the managed user session ended precede `SYNCING_OUT`. If there are no local changes, the Agent posts its last confirmed manifest version and verified snapshot to `POST /api/device-agent/storage/sync`. With no existing Storage space and an empty Documents folder, it posts `{"version":0,"entries":[]}`. Storage initializes the space and verifies the empty snapshot. Core requires a confirmed manifest for the device and student dated from that session’s first `SYNCING_OUT` transition. An earlier sync-in confirmation, an unresolved pending manifest, or a newer archived recovery manifest cannot authorize cleanup. Repeating confirmation of the same settled snapshot returns the existing checkpoint.

## S3 document transfers

The platform agent exchanges authenticated metadata with DeviceManagement and Storage, then uses the returned signed requests for S3. Uploads keep a read-sharing file lock and use bounded streams. Multipart requests run within the configured concurrency and share one bandwidth budget. The agent hashes each part, checks the complete source again before confirmation and validates the server's version, size and SHA-256 receipt.

The existing durable journal stores transfer IDs, expected object identity and completed part hashes. Download checkpoints contain hashes of flushed 8 MiB chunks. On restart the agent verifies these chunks, discards the uncheckpointed tail and requests the remaining range. Upgrade recovery copies any earlier staging partial to the current protected staging directory before checkpoint verification. Signed URLs stay transient. The device credential is attached only to platform metadata requests.

Download partials live in an ACL-protected `.platform-transfer-staging` directory beside Documents. Their location keeps the final rename on the same filesystem and excludes partial files from the Documents manifest. The agent verifies the whole size and SHA-256 before placing a document. Unique existing local bytes receive a verified conflict copy before replacement. SyncIn remains required before ACTIVE. Pending uploads block confirmation, profile cleanup and adoption of another session.

| Variable | Default | Meaning |
| --- | --- | --- |
| `PLATFORM_AGENT_TRANSFER_MAX_BYTES` | 10 GiB | Configured file bound. Server quotas and limits also apply |
| `PLATFORM_AGENT_TRANSFER_CONCURRENCY` | 3 | Maximum parallel multipart requests, bounded to 1–16 |
| `PLATFORM_AGENT_TRANSFER_BYTES_PER_SECOND` | 0 | Shared upload/download bandwidth limit. Zero disables rate limiting |
| `PLATFORM_AGENT_TRANSFER_RETRY_ATTEMPTS` | 4 | Bounded retry budget with exponential delay and jitter |
| `PLATFORM_AGENT_TRANSFER_TIMEOUT_SECONDS` | 900 | Deadline for one transfer attempt. Metadata requests use 30 seconds |

The targeted lifecycle and IPC regressions use a controlled HTTP implementation. Native Windows service restart, reboot, logon, real S3 multipart and large-file fault acceptance require their corresponding isolated integration scenarios. Existing confirmed file hashes avoid repeated downloads. Full manifest scans still re-hash local content, so timestamp-only matching does not establish integrity.

- `Platform.Agent`: session state machine, durable journal, manifests, HTTP/IPC contracts, native/test session adapters.
- `Platform.Agent.Service`: LocalSystem SCM host and OS-authenticated named-pipe IPC; `--console` is available for safe development.
- `Platform.Agent.Ui`: unprivileged Windows Forms UI and optional JSON `--console` status client. Enrollment requires an administrator; a managed student can request the end of their own session.
- `Platform.CredentialProvider`: separate VM-only x64 COM logon tile. C++ v143/Windows SDK build and native VM validation are still required.
- `Platform.Agent.Tests`, `Platform.Agent.RegressionTests`: targeted autonomous tests using isolated temporary Documents and a controlled HTTP double.
- `Platform.Agent.HttpScenario`: opt-in integration against production HTTP Device/Storage actions in the separate classroom test database. It uses the test profile adapter and does not verify native Windows logon.

## Targeted local verification

```powershell
dotnet build .\apps\agent\Platform.Agent.Service\Platform.Agent.Service.csproj
dotnet build .\apps\agent\Platform.Agent.Ui\Platform.Agent.Ui.csproj
dotnet run --project .\apps\agent\Platform.Agent.Tests
dotnet run --project .\apps\agent\Platform.Agent.RegressionTests
python .\apps\agent\tests\ipc_smoke.py
```

The IPC smoke explicitly disables both native-mode switches. It starts a disposable console process, checks the actual named pipe, then removes only its own temporary test directory. It never registers a service or COM provider and never changes Windows accounts/profiles.

## Configuration

| Variable | Meaning |
| --- | --- |
| `PLATFORM_AGENT_BASE_URL` | HTTPS Platform URL; default local development URL `http://127.0.0.1:8080` requires explicit insecure-local opt-in |
| `PLATFORM_AGENT_STATE_DIR` | Private journal/credential directory; default actual ProgramData `Platform\Agent` |
| `PLATFORM_AGENT_DOCUMENTS_ROOT` | Development test Documents root |
| `PLATFORM_AGENT_ALLOW_INSECURE_HTTP=1` | Allows HTTP only for loopback; remote addresses always require HTTPS |
| `PLATFORM_AGENT_DANGEROUS_WINDOWS_MODE=1` | Selects the native profile adapter; additionally requires the next guard and an expiring authorized VM permit |
| `PLATFORM_AGENT_DANGEROUS_MODE=true` | Separate native-operation guard; insufficient without virtual firmware and matching MachineGuid permit |
| `PLATFORM_AGENT_ALLOW_CONSOLE_IPC=1` | Allows the UI to verify a same-user console pipe for local development; leave disabled for installed service |

`AUTHORIZED → PREPARING → WINDOWS_PROFILE_READY → SYNCING_IN → ACTIVE → ENDING → SYNCING_OUT → STORAGE_CONFIRMED → CLEANUP → CLOSED`; failures preserve local data in `SYNC_PENDING` or `RECOVERY_REQUIRED`. Native ACTIVE requires independent WTS SID evidence. A reboot/lost token invalidates the durable prior logon flag and requires recovery.

See [CREDENTIAL_PROVIDER.md](CREDENTIAL_PROVIDER.md) for current executable contracts and [VM_ACCEPTANCE.md](VM_ACCEPTANCE.md) for guarded installation, VMware HTTPS, manual native checks and actual evidence statuses.
