# Local Docker deployment provisioning

Phase 5.4 runs a dedicated `provisioner-worker` process. The Control Plane HTTP process owns deployment reservations, published releases, the durable `commercial.deployment_operations` queue, credentials, entitlements, and the authenticated ready transition. It has no Docker socket. The worker alone has that socket; **Docker socket access is a privileged infrastructure capability**.

This provider is restricted to `APP_ENV=dev|test`, `PROVISIONER_PROVIDER=local-docker`, and `mode=dedicated`. Production, `shared`, and `on_prem` fail closed. It does not manage DNS, TLS, cloud resources, upgrades, rollback, or decommissioning.

## Local setup

Build the self-contained Backend production image and migration image, then build the worker after the Control Plane image:

```powershell
docker build --target prod -t platform-runtime:local -f apps/backend/docker/Dockerfile apps/backend
docker compose --profile provisioner-build build provisioner-migrator
docker compose --profile control build control-plane
docker compose --profile control --profile provisioner build provisioner-worker
```

Record the exact `docker image inspect platform-runtime:local --format '{{.Id}}'` digest in a **published** `commercial.releases` row as `image_digest`, with `oci_reference=platform-runtime:local`. The provider compares the local image ID to the release digest and starts the runtime by image ID. It rejects a missing, retired, draft, or incomplete release. Set `deployments.desired_release_id` to that published release before processing its pending provision operation. A deployment's published release metadata is immutable. Control Plane also needs an active entitlement signing key whose private key is available through its configured signing-key resolver; runtime receives only the corresponding trusted public keys.

Set `CONTROL_APP_ENV=dev` only in a local installation, set `PROVISIONER_ENABLED=true`, and start `docker compose --profile control --profile provisioner up -d provisioner-worker`. Defaults are disabled. The worker polls a bounded batch of pending and due retry **provision operations**, never the raw outbox. It sleeps between polls. `runtime/provisioning-secrets/` is ignored by Git and excluded from the project ZIP; protect that local directory with host access controls.

The worker writes `runtime/provisioning-secrets/status.json` with heartbeat, last attempt, last success, last failure, and consecutive failures. Its Docker healthcheck rejects a stale heartbeat when enabled. No exception text, credential, or database password is written there.

## Resource and secret boundary

For each deployment UUID, the worker creates a deterministically named Docker network, PostgreSQL container, and Backend runtime container with ownership labels. It checks labels, image digest, installation ID, network, and database configuration before reusing resources after a crash. A conflicting name fails closed. Each deployment has a separate database and random 256-bit password. The password and bootstrap M2M credential are stored in files under `runtime/provisioning-secrets/<deploymentId>/`, mounted read-only into the applicable containers. Only a `localdev://<deploymentId>/db` reference is stored in Control Plane. No raw password or credential enters the operation row, audit, or logs. The local file scheme is valid only in dev/test; production requires an external secret-store scheme.

The worker passes an explicit runtime configuration allowlist: installation and release identity, dedicated mode, database host/name/user and password file, Control Plane URL, deployment credential file reference, and trusted entitlement public keys. It does not mount the host `.env`, the Control Plane database credentials, or the Docker socket into the runtime. The migration container runs the existing Core SQL migration mechanism against **only the new deployment database** before activation.

After the infrastructure is ready, the worker invokes `php /app/yii runtime:deployment:activate` inside the runtime. That existing command initializes the installation, pulls and verifies a signed entitlement, and sends the authenticated ready report. Only Control Plane's status endpoint sets the deployment `active` and operation `succeeded`.

The backup worker uses `platform_backup_net` to reach each dedicated PostgreSQL container after activation. Start the `backup` Compose profile before provisioning so that the network exists. The provider attaches a new DB to `PROVISIONER_BACKUP_NETWORK` and repairs a missing attachment on replay. Neither runtime nor Control Plane HTTP joins the backup network. A successful activation makes the deployment backup due immediately; provisioning does not wait for a dump. See [BACKUP_RESTORE.md](BACKUP_RESTORE.md) for commands, retention, and drill behavior.

`commercial.deployment_domains.status` is a reservation and workflow state. Even `active` is **not evidence that DNS resolves or TLS is available**. Infrastructure readiness must not infer DNS or TLS availability from this field; those checks belong to a later domain phase.

## Failure handling

The operation row stores the bounded attempt count, machine error code, step, and next retry time. Transient failures use bounded exponential backoff. A stale running attempt is recovered after ten minutes; any old bootstrap credential is revoked before retry. A permanent failure marks the deployment failed. Cleanup removes only containers and network whose exact name and ownership labels match the deployment, and only before successful activation; an active deployment is not automatically removed. Resource deletion after activation and upgrade are outside this phase.
