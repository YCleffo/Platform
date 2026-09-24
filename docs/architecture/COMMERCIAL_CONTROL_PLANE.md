# Commercial Control Plane — Phase 3.3 Subscription Lifecycle

`apps/control-plane` is a separate Symfony 7.4/PHP 8.3 application with its
own `platform_commercial` PostgreSQL database and `commercial` schema. It does
not receive Yii/Diary database credentials and does not join the Diary Docker
network.

These boundaries are contractual:

- Control User != Diary User.
- Commercial Account != Diary Organization.
- Control Plane Auth != Diary Auth.

A central Control User is created before any tenant Diary exists. The Control
Plane now owns the commercial subscription contract, but still does not create
a tenant database or process a payment.

## Implemented schema

Twelve ordered Doctrine migrations create exactly 27 `commercial.*` tables.
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

The current schema, including clearly marked future entities, is
`docs/database/commercial/Commercial_ControlPlane_v2.dbml`. Effective
entitlements are derived at read time from the current active subscription,
its valid items and immutable plan features. There is no mutable
`commercial.entitlements` table. Invoices, payments and usage remain future
work.

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
and expose `GET /api/control/accounts/{accountId}/entitlements`. Every account
resource is authorized from active account membership; an account UUID from
the URL is never trusted by itself. Roles and permissions live in the central
`AccountAuthorization` policy, not in controllers and not in Diary RBAC.

Registration stores the trimmed presentation email and a lowercase normalized
lookup key. Passwords use Symfony PasswordHasher `auto`. Verification tokens
are authenticated from an artifact UUID with `CONTROL_TOKEN_SECRET`; only the
SHA-256 token hash is stored. The transactional outbox contains the recipient
and verification artifact ID, so a mail worker can reproduce the link without
persisting the raw token. No production mail dispatcher is implemented yet.

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
characters. The production image remains FrankenPHP, runs as `www-data`, has
no dev dependencies or tests, and grants write access only to runtime
directories.

## Deferred

Renewal, plan switching, usage, billing, invoices, payments, payment providers,
webhooks, signed entitlement issuance, tenant DB creation, provisioning,
DNS/TLS, custom domains, On-Prem activation, MFA and updater execution remain
future phases.
