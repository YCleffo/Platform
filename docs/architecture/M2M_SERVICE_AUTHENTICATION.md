# M2M service authentication foundation

Human and machine principals are separate. A human `Account` uses the existing session flow and needs an active `Person`; `service` and `system` Accounts cannot use that flow. Core machine authentication returns `ServiceIdentity`, never `AuthenticatedIdentity`. Control Plane machine authentication returns `DeploymentIdentity`, never a Control User session. A Core Account and a Control User are different security principals.

## Credentials and transport

Core credentials belong only to service or system Accounts. Control Plane credentials belong to a deployment. Their databases, secrets, scopes and lifecycle are independent; there is no cross database foreign key or automatic secret synchronization. A 32 byte cryptographically random secret is returned only by create and rotate. Both databases store only its SHA-256 hash. The public UUID `credentialId` and secret form `Authorization: Service <credentialId>.<secret>`. Authentication checks the hash in constant time, credential status and expiry, and the current owner state. Invalid attempts use the existing rate limit facilities. `last_used_at` is updated at most once a minute on a best effort basis.

Rotation atomically marks the old credential unusable and inserts a new credential with the same scopes. Control Plane runtime rotation gets a fresh expiry from `CONTROL_DEPLOYMENT_CREDENTIAL_TTL_DAYS`; an expired or revoked credential cannot authenticate. Revocation is idempotent. There is no implicit privilege for a `system` Account. Machine endpoints authenticate through these dedicated services and call the scope policy explicitly.

## Scopes and management

Core foundation scopes: `runtime.entitlements.read`, `runtime.entitlements.apply`, `usage.report`, `deployment.status.write`. Control Plane foundation scopes: `entitlements.read`, `usage.write`, `deployment.status.write`. Both registries reject unknown scopes and accept explicitly registered future codes. Scope policies authorize against the credential's own scope list.

Core's human admin API lists metadata with `core.service_credentials.read`, and creates, rotates or revokes with `core.service_credentials.manage`. The list contains only ID, credential ID, owner ID, scopes, status and lifecycle timestamps. Create and rotate return the raw secret once. No permissions are automatically granted to existing roles. Lifecycle audit events are `service_credential.created`, `.rotated`, `.revoked`; the resource ID is the credential ID and metadata contains only the Account ID and scope codes. Secrets and hashes are excluded. Successful M2M requests do not create individual audit events.

## Retention

Core `auth/cleanup` deletes bounded batches of revoked, rotated or expired credential metadata after `AUTH_SERVICE_CREDENTIAL_RETENTION_DAYS` (90 days by default). Control Plane privacy cleanup has a `service_credential` category configured through `CONTROL_PRIVACY_RETENTION_DAYS`. Existing audit retention handles lifecycle events. Machine credentials are excluded from human Person DSAR export. No durable raw secret exists.

## Usage ingestion

`POST /api/m2m/usage` accepts a Control Plane deployment credential with `usage.write` only while the deployment is active and not archived. Send `Authorization: Service <credentialId>.<secret>`, `Idempotency-Key`, and JSON `{"featureCode":"some.feature","delta":1}`. The body has exactly those two fields. The deployment and commercial account are resolved server side from the credential. The response contains only `featureCode`, `mode`, `used`, `limit`, `remaining`, and `exhausted`. The existing `ApplyUsageDelta` enforces subscription, quota and metering rules; its idempotency namespace includes the deployment source. Successful usage writes create no per-request audit or outbox event.

## Signed entitlement transport

Control Plane `GET /api/m2m/entitlements/latest` requires a deployment credential
with `entitlements.read`; its response has exactly `documentBase64`,
`signatureBase64`, and `keyId`. Core `POST /api/m2m/runtime/entitlements/apply`
requires a local service credential with `runtime.entitlements.apply` and the
same three-field JSON body. The local installation ID comes from Core storage,
never from a request parameter. The v1 signed bytes contain exactly
`documentVersion`, `sequence`, `installationId`, `issuedAt`, `validFrom`,
`validUntil`, `deploymentStatus`, and `features`. Core verifies Ed25519 over the
exact decoded bytes before applying; unknown or revoked key IDs fail closed.
Configure `RUNTIME_ENTITLEMENT_TRUSTED_KEYS` as JSON mapping key IDs to
`{"publicKeyBase64":"...","status":"trusted"}` or `"revoked"`; multiple IDs
permit rotation. An empty trust map denies all documents. Core stores the exact
canonical bytes as base64 in its existing JSONB snapshot and only exposes
features from the latest valid, verified, active applied snapshot.

## Bootstrap credential and runtime sync

`control:deployments:provision:start --deployment-id=<uuid>` issues one
deployment credential with `entitlements.read`, `usage.write`, and
`deployment.status.write` and a finite expiry from `CONTROL_DEPLOYMENT_CREDENTIAL_TTL_DAYS`. Invalid or missing TTL configuration fails provisioning closed. Its raw secret appears only in that command's
one-time JSON output. Configure the new runtime with
`PLATFORM_INSTALLATION_ID`, `PLATFORM_DEPLOYMENT_MODE`, `APP_ENV`,
`APPLICATION_VERSION`, `PLATFORM_RELEASE_ID`, and
`PLATFORM_RELEASE_CHANNEL`. `runtime:deployment:activate` is the application bootstrap entry point: it creates the local installation, syncs the signed entitlement, and reports the applied sequence as ready. `runtime:installation:init` creates the local
singleton as `provisioned` or confirms an exact replay; conflicting identity
fails closed. Configure `CONTROL_PLANE_URL` and
`CONTROL_PLANE_DEPLOYMENT_CREDENTIAL_REF=env:VARIABLE_NAME`, with the named
environment variable containing `<credentialId>.<secret>`. The credential is
never stored in Core PostgreSQL. Both status reporting and `runtime:entitlements:sync` resolve the same `env:VARIABLE_NAME` reference through `RuntimeSecretResolver`. `runtime:entitlements:sync` calls the Control
Plane pull endpoint directly and invokes the existing local
`EntitlementApplier`. It prints only the applied sequence.

The runtime then sends `POST /api/m2m/deployment/status` using its deployment
credential and `deployment.status.write`. A ready body has exactly `status`
(`ready`) and `entitlementSequence` (positive integer); a failed body has
exactly `status` (`failed`) and a machine-only `errorCode` such as
`runtime.bootstrap_failed`. The deployment identity comes only from the
credential. The ready report succeeds only for a running provision operation
and the latest issued entitlement with the deployment's installation ID. On first success, the same transaction removes `deployment.status.write`; the credential remains usable for `entitlements.read` and `usage.write`. A retry with the same latest sequence returns `already_ready` without a new audit event or scope change. An old or incorrect sequence is rejected.

`control:deployments:credential:status --deployment-id=<uuid>` shows the single active runtime credential's `expiresAt` and `daysUntilExpiry` without a secret. `control:deployments:credential:rotate --deployment-id=<uuid>` atomically invalidates it and returns a new `credentialId.secret` once, preserving its current runtime scopes and assigning a fresh expiry. Ambiguous active credentials fail closed. After rotation, the operator must update the deployment's external secret source and restart or recreate the runtime process that reads its environment. Control Plane does not edit remote files or containers.
