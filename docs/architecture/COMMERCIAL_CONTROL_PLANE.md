# Commercial Control Plane — Phase 4.0 Integration Foundation

`apps/control-plane` is a separate Symfony 7.4/PHP 8.3 application with its
own `platform_commercial` PostgreSQL database and `commercial` schema. It does
not receive Yii/Diary database credentials and does not join the Diary Docker
network.

These boundaries are contractual:

- Control User != Diary User.
- Commercial Account != Diary Organization.
- Control Plane Auth != Diary Auth.

A central Control User is created before any tenant Diary exists. The Control
Plane now owns the commercial subscription and payment contracts, but still
does not create a tenant database.

## Implemented schema

Twenty ordered Doctrine migrations create 39 `commercial.*` tables. Migration
020 changes the outbox lifecycle without adding another table. Runtime checks
assert required objects and migration state rather than an exact table count.
The 21 Phase 1 tables remain unchanged. Phase 2 adds:

- `control_user_email_verifications` — one-time, expiring, hash-only tokens;
- `control_sessions` — short-lived opaque access tokens and rotating refresh
  tokens, both stored only as hashes;
- `control_session_refresh_history` — consumed token hashes for complete family
  reuse detection;
- `rate_limit_counters` — expiring, pseudonymized fixed-window counters.

Migrations 008–010 also extend `control_users`, `commercial_accounts` and
`billing_profiles`. Account creation writes the account, owner membership,
audit event, outbox event and idempotency result in one transaction. Deferred
PostgreSQL constraint triggers prevent an account from committing without an
active owner and serialize concurrent owner removal/downgrade.

Phase 3.1 adds `subscriptions` and `subscription_items`. A draft selects an
immutable published recurring `plan_price`; partial unique indexes allow at
most one draft and one active subscription per Commercial Account. Creation,
its item, audit/outbox events and idempotency result commit atomically.

Phase 3.2 adds no table. Internal `ActivateSubscription` locks and validates
the subscription contract, calculates its first UTC calendar period, freezes
its items through PostgreSQL guards, activates the Commercial Account and
completes onboarding in one transaction. Activation is intentionally not an
HTTP customer action.

Phase 3.3 also adds no table. A customer with `billing.manage` can cancel a
draft or active subscription through an idempotent HTTP action. The internal
`control:subscriptions:expire` command claims due active subscriptions in
bounded `FOR UPDATE SKIP LOCKED` batches. Both terminal transitions write
audit and outbox records atomically. After cancellation or expiration the
Commercial Account remains `active/completed`, effective entitlements become
empty, and a new subscription row can be created and activated. Historical
subscriptions and their items are never recycled or deleted.

Phase 3.4 adds `usage_periods`, `usage_counters` and immutable `usage_mode`
semantics on catalog features. Explicitly metered integer features use either
monotonic `counter` or signed `gauge` deltas. Limits come from the effective
subscription contract and are enforced as hard quotas by an idempotent,
transactional internal service. Activation creates the open usage period with
the subscription boundaries; cancellation and expiration close it while
preserving counters. A replacement subscription receives a new period and
starts from zero. This operational quota state is not usage-based billing.

Phase 3.5 adds `invoices` and `invoice_lines` for the initial
`subscription_create` charge. `IssueInvoice` derives currency, quantity and
integer minor-unit amounts from the locked draft subscription contract and
publishes the invoice atomically with `invoice.issued` audit/outbox records.
The issued invoice stores immutable billing-profile and catalog snapshots;
reads never reconstruct history from mutable source rows. Cancelling a draft
subscription voids its issued invoice in the same transaction and emits
`invoice.voided`, while cancelling an active subscription preserves the
historical invoice. The `paid` state belongs exclusively to Payment Core and
payment settlement activates the subscription atomically.

Phase 3.6 adds provider-neutral `payment_provider_accounts`, `payments` and
`payment_attempts`. One payment mirrors one invoice's immutable money, while
multiple historical attempts model provider retries with at most one pending
attempt. A trusted internal success confirmation atomically succeeds the
attempt/payment, pays the invoice, activates its draft subscription, opens the
usage period and writes audit/outbox lifecycle events. Direct activation is
payment-gated. Zero-amount invoices create a historical succeeded payment and
activate without a fake provider or attempt. Refunds and receipts are
intentionally absent.

Phase 3.7 adds the first concrete provider integration: YooKassa with SBP,
`capture=true` and hosted redirect confirmation. Credentials remain outside
PostgreSQL: `payment_provider_accounts.secret_ref` points to an environment
variable resolved server-side. The provider request uses the existing attempt
request key as YooKassa `Idempotence-Key`; money is derived from the immutable
local Payment and only RUB is accepted.

`payment_provider_events` is a hash-only webhook inbox. It deduplicates by
provider account plus deterministic event key and never stores the raw body.
Webhook JSON is only a notification: final state, amount, currency and local
metadata are re-fetched from YooKassa before the existing provider-neutral
bind/completion services are called. Metadata supports recovery when the
provider created a payment but the process died before the local reference was
bound. `control:payments:reconcile-yookassa --limit=100` performs the same
authoritative flow for a bounded batch without holding database locks during
network I/O. Settlement remains owned by Payment Core.

Phase 3.8 adds `receipts` and `receipt_items`. A successful non-zero provider
settlement creates the local fiscal obligation and immutable invoice-line
snapshot in the same database transaction as Payment, Invoice, Subscription
and Usage changes. Provider HTTP is never executed in that transaction.
YooKassa receipt submission uses a separate stable idempotency key and the
provider payment reference; uncertain failures leave the payment paid and the
receipt pending for `control:payments:reconcile-yookassa-receipts --limit=100`.
Only `pending -> succeeded` and `pending -> canceled` transitions are accepted.
Customer email/name come from the issued invoice snapshot and are excluded
from the receipt read API, audit and outbox payloads. Zero payments do not
create receipts.

Phase 3.9 adds immutable `subscription_renewal` invoice periods and the bounded
`control:billing:prepare-renewals` / `control:subscriptions:advance` jobs. Early
payment funds the next period without changing the active period; rollover
atomically closes the old usage period and opens a zeroed one. Expiration waits
for the configurable settlement grace and serializes on the subscription row.

The same phase adds `refunds` and `refund_items`. Refund money is derived from
validated invoice-line allocations, while payment locking prevents concurrent
over-refund. YooKassa create/get calls reuse a stable provider idempotency key;
webhooks remain hash-only notifications followed by authoritative GET. A
succeeded refund creates a separate immutable `refund` receipt obligation in
the settlement transaction and the existing receipt reconciler delivers it.

The current schema, including clearly marked future entities, is
`docs/database/commercial/Commercial_ControlPlane_v2.dbml`. Effective
entitlements are derived at read time from the current active subscription,
its valid items and immutable plan features. There is no mutable
`commercial.entitlements` table. Usage is a separate mutable read/write model.
Phase 3.9 adds immutable renewal service periods, bounded preparation and
rollover commands, settlement grace, provider-neutral full/partial refunds,
YooKassa refund reconciliation/webhooks, and separate refund receipts. Invoice
remains distinct from payment; plan switching and usage-based charging remain
future work.

## HTTP contract

Public endpoints:

- `POST /api/control/auth/register`
- `POST /api/control/auth/verification/resend`
- `POST /api/control/auth/verify`
- `POST /api/control/auth/login`
- `POST /api/control/auth/refresh`
- `POST /api/control/auth/logout`
- `GET /api/control/catalog/plans`
- `GET /health`

Authenticated endpoints create/read commercial accounts, manage memberships,
save the billing profile, advance onboarding, create/read subscriptions,
cancel them through `POST /api/control/accounts/{accountId}/subscriptions/{subscriptionId}/cancel`,
issue the initial invoice through
`POST /api/control/accounts/{accountId}/subscriptions/{subscriptionId}/invoice`,
read its immutable snapshot through
`GET /api/control/accounts/{accountId}/invoices/{invoiceId}`, and expose
payment creation through
`POST /api/control/accounts/{accountId}/invoices/{invoiceId}/payment`, payment
reads through `GET /api/control/accounts/{accountId}/payments/{paymentId}`,
reads sanitized fiscal state through
`GET /api/control/accounts/{accountId}/payments/{paymentId}/receipt`, and
starts hosted SBP checkout through
`POST /api/control/accounts/{accountId}/payments/{paymentId}/checkout`.
Refunds use item allocations through
`POST /api/control/accounts/{accountId}/payments/{paymentId}/refunds`, with
history at the matching `GET` endpoint and
`GET /api/control/accounts/{accountId}/refunds/{refundId}`. YooKassa
notifications enter through the Control-User-independent machine endpoint
`POST /api/control/webhooks/yookassa`; authoritative provider re-fetch is its
authentication boundary. The endpoint does not trust forwarded IP headers or
notification JSON as financial proof. The API also exposes
`GET /api/control/accounts/{accountId}/entitlements` plus the read-only
`GET /api/control/accounts/{accountId}/usage`. Every account
resource is authorized from active account membership; an account UUID from
the URL is never trusted by itself. Roles and permissions live in the central
`AccountAuthorization` policy, not in controllers and not in Diary RBAC.

`POST /api/m2m/usage` accepts a deployment service credential with
`usage.write` and an `Idempotency-Key`. The server resolves the deployment's
commercial account and accepts writes only for active deployments. It uses
`ApplyUsageDelta`; replay is scoped to the deployment, and successful writes
do not create audit or outbox events. See `M2M_SERVICE_AUTHENTICATION.md` for
the request and response contract.

Registration stores the trimmed presentation email and a lowercase normalized
lookup key. Passwords use Symfony PasswordHasher `auto`. Verification tokens
are authenticated from an artifact UUID with `CONTROL_TOKEN_SECRET`; only the
SHA-256 token hash is stored. The transactional outbox contains the recipient
and verification artifact ID, so a mail worker can reproduce the link without
persisting the raw token. `control:outbox:process-email` leases those events,
reconstructs a short-lived verification link, sends via SMTP, and marks them
published or retries with bounded backoff. `control:outbox:status` reports
status counts/oldest pending and supports explicit `--requeue=ID` for dead events.
Use the supervised `control-outbox-worker` Compose service or a production
cron/systemd equivalent. SMTP is at-least-once; stable `Message-ID` is derived
from `event_id`, and the raw token and email body never enter the outbox/logs.

## Local validation

Set a test-only `CONTROL_TEST_PASSWORD`, then run from the repository root:

```bash
bash ./scripts/control-test-start.sh
bash ./scripts/control-test-run.sh
APP_ENV=test bash ./scripts/control-test-reset.sh
bash ./scripts/control-test-stop.sh
```

PowerShell equivalents are in `scripts/`. The reset command refuses any target
except `APP_ENV=test`, `CONTROL_TEST_ISOLATED=1`, host
`control-postgres-test`, database `platform_commercial_test`.

For the development profile configure separate `CONTROL_POSTGRES_PASSWORD`,
`CONTROL_APP_SECRET` and `CONTROL_TOKEN_SECRET` values of at least 32
characters, plus the real HTTPS `CONTROL_YOOKASSA_RETURN_URL` before live checkout.
The `.env.example` return URL is only a local placeholder. Configure
`CONTROL_MAILER_DSN` (`smtp://` or `smtps://`, with optional `?starttls=1`),
`CONTROL_MAIL_FROM`, `CONTROL_VERIFICATION_URL` and
`CONTROL_MESSAGE_ID_DOMAIN` for the verification worker. Locally the SMTP
sink is Mailpit; production must use a real transport and HTTPS verification
URL. Configure merchant metadata with
`control:payments:providers:yookassa:configure --shop-id=... --secret-ref=env:YOOKASSA_SECRET_KEY --receipt-mode=yookassa --vat-code=1 --payment-subject=service --payment-mode=full_payment`;
the command never accepts the secret itself. The production image remains
FrankenPHP, runs as `www-data`, has
no dev dependencies or tests, and grants write access only to runtime
directories.

## Deferred

`licenses`, `license_installations`, `maintenance_contracts`,
`issued_entitlement_documents`, `releases` and `release_entitlements` remain
schema foundations. Phase 4.6 uses the separate immutable
`deployment_entitlement_documents` table for signed deployment snapshots.
`ReserveDeployment` creates a durable reservation. Phase 4.7 adds an operator
start and authenticated runtime readiness report for the application bootstrap
lifecycle; it does not create infrastructure. Control current-user/session APIs and paginated
commercial read models belong to Phase 4.1. M2M service credentials and usage
ingestion and signed entitlement delivery are implemented.

Plan switching, additional payment providers and methods, bank cards,
saved/recurring credentials,
usage-based and overage billing, DNS/TLS, custom
domains, On-Prem activation, MFA and updater execution remain future phases.

## Application deployment bootstrap (Phase 4.7)

Phase 5.4 adds a separate local Docker `provisioner-worker` for dedicated dev/test deployments. It consumes `deployment_operations`, checks a published digest-pinned release, creates isolated runtime infrastructure, then invokes the existing activation command. The HTTP Control Plane has no Docker socket. See `docs/operations/PROVISIONING.md` for the boundary and retry behavior.

Reserve a shared or dedicated deployment, then run
`control:deployments:provision:start --deployment-id=<uuid>`. This locks the
reserved deployment and its existing provision operation, moves them to
`provisioning` / `running`, increments `attempt_count`, and returns
`installationId`, `credentialId`, `credentialSecret`, and scopes once. The
credential has a finite expiry from `CONTROL_DEPLOYMENT_CREDENTIAL_TTL_DAYS` and is created through `DeploymentCredentialService`; only its hash is
stored. A repeated start is rejected and cannot retrieve the old secret.

Pass the installation ID and bootstrap credential to the runtime deployment
environment through its secret/config boundary. Run `runtime:deployment:activate` to initialize the singleton local installation, pull and apply the signed snapshot, then report its applied sequence. The existing `runtime:installation:init` and `runtime:entitlements:sync` remain available as bounded manual steps. During provisioning the issuer signs
the desired runtime state `active` with current effective features. Moving the
Control Plane deployment from `provisioning` to `active` alone does not issue a
duplicate snapshot. Activation sends `{"status":"ready","entitlementSequence":<applied sequence>}` to
`POST /api/m2m/deployment/status`. Control Plane checks the authenticated
deployment, running operation and issued sequence before atomically marking
the deployment active and the operation succeeded and auditing
`deployment.provisioned`. It removes `deployment.status.write` from that credential in the same transaction and leaves `entitlements.read` plus `usage.write`. A repeated ready report for the latest accepted sequence returns `already_ready` without another audit event. A machine-code failed report marks both failed.
The runtime report confirms application activation; VM/container creation,
tenant DB creation, DNS and TLS remain separate external operations.

Operators can inspect expiry using `control:deployments:credential:status --deployment-id=<uuid>` and rotate the single active runtime credential using `control:deployments:credential:rotate --deployment-id=<uuid>`. Rotation issues a new finite credential once and invalidates the old one immediately. The operator must update the external deployment secret source and restart or recreate the runtime process that reads it; Control Plane does not update remote `.env` files.

## Signed deployment entitlement delivery

`GET /api/m2m/entitlements/latest` authenticates a deployment credential with
`entitlements.read` and derives the deployment only from that credential. Active
deployments receive the current `EffectiveEntitlements` composition; suspended
deployments receive `{}` features. Archived and decommissioned deployments are
denied. A deployment row lock serializes issuance. A source fingerprint makes
repeat fetches return identical canonical bytes, signature and sequence until
the effective state, validity boundary or signing key changes. Each new snapshot
is an immutable v1 Ed25519 document; the same transaction inserts the
`entitlement.issued` outbox event. Signing uses an active `commercial.signing_keys`
row and resolves its `private_key_secret_ref` to an external read-only secret
file. `CONTROL_ENTITLEMENT_SECRET_FILES` maps references to container paths;
the file contains base64 of a 64-byte Ed25519 secret key. An absent or invalid
mapping fails issuance closed. The Core runtime receives only trusted public
keys, never signing secrets.

## Local dedicated decommission (Phase 5.6)

The existing `commercial.deployment_operations` table carries `deprovision` through `pending`, `running`, `retry`, and `succeeded` or `failed`. An operator request confirms deployment and installation IDs, suspends the deployment, and revokes active M2M credentials transactionally. The existing provisioner worker quiesces runtime, waits for a new verified deployment backup, destroys owned infrastructure, cleans secrets, and archives the deployment. Audit events contain only deployment, installation, and operation UUIDs. Status output contains IDs and machine state only.

The Control Plane's domain `disabled` state does not remove DNS or TLS resources. Decommission is not DSAR erasure, and deployment backups follow their own explicitly configured retention.
