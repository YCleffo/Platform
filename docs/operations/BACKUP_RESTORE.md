# Core and Control Plane backup and restore drill

Phase 5.3 uses one backup engine, `infra/backup/backup.sh`, inside a dedicated PostgreSQL-client container. The `backup-worker` is opt in (`BACKUP_ENABLED=false` by default, Compose profile `backup`). Enable it with reviewed `BACKUP_*` settings and start it with the Core and Control databases available. It runs one bounded Core + Control backup set, verifies both archives, applies retention, and repeats at `BACKUP_INTERVAL_SECONDS`. It does not use a Docker socket, application admin token, or M2M credential.

For a one-time operator run from the project root:

```powershell
.\scripts\backup-db.ps1 -Target All
.\scripts\backup-db.ps1 -Target Core
.\scripts\backup-db.ps1 -Target Control
.\scripts\verify-backup.ps1
.\scripts\restore-drill.ps1
```

The manual commands require the relevant PostgreSQL containers to be running. `-ManifestPath` on verify or drill selects a specific `backups/manifests/<backupSetId>.json`; otherwise the newest **verified** set is selected. Verify is read-only and does not connect to a production database. Drill verifies the manifest first, creates random disposable databases in the corresponding PostgreSQL containers, restores the dumps, checks required schema objects and migration metadata, then drops those databases in cleanup. It never starts application processes against drill databases.

## Artifacts and publication

`backups/core/` and `backups/control/` hold `pg_dump -Fc` archives. `backups/manifests/` holds one JSON manifest per complete set. Each manifest records schema version, set ID, creation time, application version, optional release ID, logical database names, relative files, sizes, SHA-256 hashes, and `pg_restore --list` results. `crossDatabaseAtomic` is always `false`: the two dumps are separate PostgreSQL snapshots.

Archives are written as `*.partial`. Only nonempty dumps passing `pg_restore --list` and SHA-256 calculation are renamed to `*.dump`; the manifest is published last with an atomic rename. An incomplete set has no valid manifest and cannot be chosen as the latest backup. `verify-backup.ps1` rechecks schema, files, sizes, hashes, and `pg_restore --list`. Corrupt or missing dumps fail verification.

Retention considers only complete, reverified sets older than `BACKUP_RETENTION_DAYS`. It preserves at least `BACKUP_MIN_SUCCESSFUL_SETS`, the latest successful set overall, and the latest successful set for each database. Unpublished partial or orphan files do not count as successful sets. `backups/status.json` stores only attempt/success/failure timestamps, a failure counter, and the last set ID. The worker healthcheck compares the last success with `BACKUP_HEALTH_TIMEOUT_SECONDS`; no exception text or secret is stored.

## Live restore boundary

Live restore is a manual destructive operation. `restore-db.ps1` requires **all** of `-Target Core|Control`, an explicit `-DumpPath`, `-Force`, and `-AllowLiveRestore`. Production (`BACKEND_APP_ENV` or `CONTROL_APP_ENV` set to `prod`/`production`) additionally requires `-ConfirmProductionRestore`. There is no implicit latest-dump selection and no interactive fallback. Quiesce traffic and dependent workers as part of the operator procedure before invoking it.

```powershell
.\scripts\restore-db.ps1 -Target Core -DumpPath .\backups\core\<backupSetId>.dump -Force -AllowLiveRestore -ConfirmProductionRestore
```

**Restore does not mean privacy-safe restore.** An older dump can reintroduce personal data erased after its capture. After any live restore, operational state remains blocked: privacy reconciliation is required before serving traffic. Phase 5.3 does not automate re-erasure.

Dump files, manifests, and status files are sensitive artifacts. The `backups/` directory is excluded from Git and project ZIP archives. Production backup storage **must provide approved encryption at rest and access control**; the worker does not implement its own encryption wrapper. Operators must retain separate evidence of the actual storage controls.

## Dedicated deployment databases (Phase 5.5)

Core + Control sets and dedicated deployment backups are separate chains. Each eligible dedicated deployment has its own `backups/deployments/<deploymentId>/dumps/` and `manifests/` directory. There is no cross-deployment atomic snapshot. A failed deployment backup does not prevent other deployments or the independent Core + Control schedule from running.

Set `DEPLOYMENT_BACKUP_ENABLED=true` only after the dedicated `platform_backup_net` and backup worker are running. The provisioner connects each dedicated PostgreSQL container to this network and repairs a missing connection on replay. Runtime and Control Plane HTTP containers do not join it. The backup worker has no Docker socket. It reads the Control Plane deployment inventory and a read-only mount of `runtime/provisioning-secrets/`; it selects only unarchived, active or suspended dedicated deployments whose DB secret reference exactly matches `localdev://<deploymentId>/db`. External secret providers require a later adapter.

The worker checks due deployments every 30 seconds and backs up at most `DEPLOYMENT_BACKUP_BATCH_SIZE` per check. A deployment with no verified backup is due immediately after activation. Otherwise it is due after `DEPLOYMENT_BACKUP_INTERVAL_SECONDS`. Per-deployment retention preserves the newest verified backup and at least `DEPLOYMENT_BACKUP_MIN_SUCCESSFUL_SETS` verified backups. `DEPLOYMENT_BACKUP_HEALTH_TIMEOUT_SECONDS` controls freshness health. Deployment health is reported by the backup worker only; it does not change public Core or Control HTTP readiness.

```powershell
.\scripts\backup-db.ps1 -Target Deployment -DeploymentId <uuid>
.\scripts\backup-db.ps1 -Target Deployments
.\scripts\verify-backup.ps1 -DeploymentId <uuid> [-ManifestPath <backupId.json>]
.\scripts\restore-drill.ps1 -DeploymentId <uuid> [-ManifestPath <backupId.json>]
docker compose --profile control --profile backup exec backup-worker bash /opt/backup/backup.sh status
```

The `status` command separates `platform` and `deployments`. Each deployment record contains only `deploymentId`, backup status, last backup timestamp, age, and verification result. Deployment manifests have `schemaVersion=1`, `backupType=deployment`, UUID identities, version and release metadata, relative dump path, size, SHA-256, and `pgRestoreList=true`. Passwords, credentials, and personal data are absent. Publication writes a partial dump, checks it with `pg_restore --list`, computes SHA-256, renames the dump, then publishes the manifest last. Verify is read-only. Drill restores into a disposable database, checks required Core and runtime schema plus migration 031, compares the restored installation ID to the manifest, and drops the disposable database. Live restore remains a separate manual procedure.

## Final backup and decommission retention

When a `deprovision` operation reaches `final_backup`, the backup worker treats that deployment as immediately due even if its prior backup is fresh. The provisioner waits for a published manifest with matching deployment and installation IDs and `createdAt` at or after the operation start. It never performs dump or hash work itself. A failed final backup leaves the DB running for a later attempt; infrastructure destruction cannot proceed on an older manifest.

Decommission does **not** immediately delete the backup chain. `DECOMMISSIONED_DEPLOYMENT_BACKUP_RETENTION_DAYS` is blank by default, disabling automatic purge. Set an approved positive period to allow the backup worker to delete an archived, decommissioned deployment's entire chain after that period. Purge is bounded per cycle and checks the exact deployment ID and DB state before deleting files in that deployment's directory. The active deployment minimum set count does not extend decommission retention. Core, Control, and other deployments are untouched.

Decommission is not privacy erasure. Backup retention, legal holds, and restore/re-erasure procedures remain separate privacy obligations.
# Update checkpoints

An update in `pre_update_backup` is immediately eligible for a deployment backup. The backup
must have been created after quiescing and have verified manifest, SHA-256, and pg_restore-list
evidence before target migrations can begin. The checkpoint is recovery evidence; automated
restore or database rollback is intentionally not implemented.
