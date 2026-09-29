# M2M service authentication foundation

Human and machine principals are separate. A human `Account` uses the existing session flow and needs an active `Person`; `service` and `system` Accounts cannot use that flow. Core machine authentication returns `ServiceIdentity`, never `AuthenticatedIdentity`. Control Plane machine authentication returns `DeploymentIdentity`, never a Control User session. A Core Account and a Control User are different security principals.

## Credentials and transport

Core credentials belong only to service or system Accounts. Control Plane credentials belong to a deployment. Their databases, secrets, scopes and lifecycle are independent; there is no cross database foreign key or automatic secret synchronization. A 32 byte cryptographically random secret is returned only by create and rotate. Both databases store only its SHA-256 hash. The public UUID `credentialId` and secret form `Authorization: Service <credentialId>.<secret>`. Authentication checks the hash in constant time, credential status and expiry, and the current owner state. Invalid attempts use the existing rate limit facilities. `last_used_at` is updated at most once a minute on a best effort basis.

Rotation atomically marks the old credential unusable and inserts a new credential with the same scopes and expiry. Revocation is idempotent. An expired or revoked credential cannot authenticate. There is no implicit privilege for a `system` Account. Future machine endpoints must authenticate through these dedicated services and call the scope policy explicitly; this phase does not add usage, entitlement or provisioning endpoints.

## Scopes and management

Core foundation scopes: `runtime.entitlements.read`, `runtime.entitlements.apply`, `usage.report`, `deployment.status.write`. Control Plane foundation scopes: `entitlements.read`, `usage.write`, `deployment.status.write`. Both registries reject unknown scopes and accept explicitly registered future codes. Scope policies authorize against the credential's own scope list.

Core's human admin API lists metadata with `core.service_credentials.read`, and creates, rotates or revokes with `core.service_credentials.manage`. The list contains only ID, credential ID, owner ID, scopes, status and lifecycle timestamps. Create and rotate return the raw secret once. No permissions are automatically granted to existing roles. Lifecycle audit events are `service_credential.created`, `.rotated`, `.revoked`; the resource ID is the credential ID and metadata contains only the Account ID and scope codes. Secrets and hashes are excluded. Successful M2M requests do not create individual audit events.

## Retention

Core `auth/cleanup` deletes bounded batches of revoked, rotated or expired credential metadata after `AUTH_SERVICE_CREDENTIAL_RETENTION_DAYS` (90 days by default). Control Plane privacy cleanup has a `service_credential` category configured through `CONTROL_PRIVACY_RETENTION_DAYS`. Existing audit retention handles lifecycle events. Machine credentials are excluded from human Person DSAR export. No durable raw secret exists.
