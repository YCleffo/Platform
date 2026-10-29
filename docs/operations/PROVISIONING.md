# Local Docker deployment provisioning

A dedicated `provisioner-worker` process. The Control Plane HTTP process owns deployment reservations, published releases, the durable `commercial.deployment_operations` queue, credentials, entitlements, and the authenticated ready transition. It has no Docker socket. The worker alone has that socket; **Docker socket access is a privileged infrastructure capability**.

This provider is restricted to `APP_ENV=dev|test`, `PROVISIONER_PROVIDER=local-docker`, and `mode=dedicated`. Production, `shared`, and `on_prem` fail closed. It does not manage DNS, TLS, or cloud resources. Local dedicated deployments support the safe forward release-update workflow described below; automatic rollback and production/cloud updates remain unsupported.

## Состояние локального окружения

Provisioner отключён и не входит в основной Compose. Он создаёт отдельные ресурсы выделенных установок, поэтому его запуск в текущем локальном окружении не используется. CI сохраняет build definitions в `compose.ci.yaml` для проверки образов и инфраструктурных контрактов.

Для отдельной установки этот провайдер требует опубликованный immutable release с точным image digest, настроенное подписание entitlements и проверенное резервирование. Эти зависимости являются условиями операции; их отсутствие не разрешает пропустить checkpoint или выдать ready.

## Resource and secret boundary

```text
Dedicated deployment
├── PostgreSQL
├── Redis
├── Runtime HTTP
├── Scheduler
└── Email worker
```

For each deployment UUID, the worker owns one network and five deterministic containers: PostgreSQL, Redis, HTTP runtime, Core scheduler, and email worker. Each resource has deployment and role ownership labels. All three Core processes use the same release image digest, deployment DB, and deployment Redis. PostgreSQL joins the deployment and backup networks; Redis joins only the deployment network; Core processes join the deployment and control networks. Core processes have no Docker socket or backup network. A conflicting name fails closed. Each deployment has a separate database and random 256-bit password. The password and bootstrap M2M credential are stored in files under `runtime/provisioning-secrets/<deploymentId>/`, mounted read-only into the applicable containers. Only a `localdev://<deploymentId>/db` reference is stored in Control Plane. No raw password or credential enters the operation row, audit, or logs. The local file scheme is valid only in dev/test and requires a Linux host with POSIX group 33; unsupported hosts fail before writing secrets. CI exercises the real group and mode checks in the Linux Control Plane test image. Production requires an external secret-store scheme.

The worker passes one explicit configuration allowlist to all Core processes: installation and release identity, dedicated mode, database and Redis endpoints, Control Plane URL, deployment credential file reference, and trusted entitlement public keys. It does not mount the host `.env` or Control Plane database credentials. Scheduler privacy automation is disabled without deployment-specific policy. The migration container runs the existing Core SQL migration mechanism against **only the deployment database** before activation. The provider starts scheduler and email worker before HTTP runtime.

After the infrastructure is ready, the worker invokes `php /app/yii runtime:deployment:activate` inside the runtime. That command initializes the installation, pulls and verifies a signed entitlement, waits up to `RUNTIME_ACTIVATION_READY_TIMEOUT_SECONDS` for Redis and fresh scheduler/email heartbeats, and sends the authenticated ready report. Only Control Plane's status endpoint sets the deployment `active` and operation `succeeded`. The provider then checks `/health/ready`, both `ops:health` commands, and that all Core containers remain running. An active deployment therefore includes healthy required background processes.

For an existing active or suspended local dedicated deployment, run `php /app/bin/console control:deployments:infrastructure:reconcile --deployment-id=<uuid>` in the provisioner worker environment. This adds missing Redis/workers, restarts owned stopped resources, and replaces a verified legacy HTTP container whose immutable environment lacks dedicated Redis. It does not issue a credential, change a release, migrate the DB, or change deployment state. Repeating the command reuses the completed topology.

The backup worker uses `platform_backup_net` to reach each dedicated PostgreSQL container after activation. Start the `backup` Compose profile before provisioning so that the network exists. The provider attaches a new DB to `PROVISIONER_BACKUP_NETWORK` and repairs a missing attachment on replay. Neither runtime nor Control Plane HTTP joins the backup network. A successful activation makes the deployment backup due immediately; provisioning does not wait for a dump. See [BACKUP_RESTORE.md](BACKUP_RESTORE.md) for commands, retention, and drill behavior.

`commercial.deployment_domains.status` is a reservation and workflow state. Even `active` is **not evidence that DNS resolves or TLS is available**. Infrastructure readiness must not infer DNS or TLS availability from this field; those checks belong to a later domain phase.

## Failure handling

The operation row stores the bounded attempt count, machine error code, step, and next retry time. Transient failures use bounded exponential backoff. A stale running attempt is recovered after ten minutes; any old bootstrap credential is revoked before retry. A permanent failure marks the deployment failed. Provisioning cleanup removes only owned resources before successful activation; it does not automatically remove an active deployment.

## Safe local decommission

For an active or suspended, unarchived dedicated deployment in dev/test, run:

```powershell
docker compose --profile control exec control-plane php bin/console control:deployments:decommission --deployment-id=<uuid> --confirm-installation-id=<installation-uuid> --idempotency-key=<unique-key>
docker compose --profile control --profile provisioner exec provisioner-worker php /app/bin/console control:deployments:decommission-status --deployment-id=<uuid>
```

The first command only queues a durable `deprovision` operation. In the same transaction it suspends the deployment, revokes every active service credential, and writes one audit event. The idempotency key returns the same operation on replay. The provisioner worker then stops HTTP runtime, scheduler, and email worker before final backup, leaves PostgreSQL running for that backup, and removes all six owned resources after verified evidence. It then cleans secrets, archives the deployment, and disables its domain rows. Missing final backup leaves the operation in `retry` at `final_backup`, with writers stopped and DB intact. Worker retries are safe after a crash at any step.

The backup worker must be running with `DEPLOYMENT_BACKUP_ENABLED=true` to service final backup requests automatically. The provisioner mounts `backups/` read-only and only reads published manifest evidence; it does not make dumps. `DECOMMISSION_BACKUP_CHECK_INTERVAL_SECONDS` controls how often a waiting operation retries. Production and other deployment modes fail closed.

Decommission does not delete DNS/TLS records, perform DSAR erasure, or immediately remove backups. Domain `disabled` is a Control Plane workflow state, not DNS deletion.
# Safe release updates

For an active dedicated deployment, `current_release_id` is the proven runtime release and
`desired_release_id` is the requested target. `control:deployments:update` creates a durable
update operation. The provisioner quiesces application writers, requires a fresh verified
backup, runs migrations from the pinned target image, and replaces only runtime, scheduler,
and email-worker. It advances `current_release_id` only after runtime verification succeeds.
Failed updates preserve deployment data and do not perform automatic rollback.

## Проверка workers

CLI workers имеют отдельный healthcheck `ops:health`. HTTP readiness проверяет актуальность heartbeat и состояние обязательных задач. Ошибка HTTP probe не заменяет проверку CLI-процесса; после изменения конфигурации постоянные контейнеры пересоздаются.
