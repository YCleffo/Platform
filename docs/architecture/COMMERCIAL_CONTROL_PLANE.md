# Commercial Control Plane — Phase 2 identity and onboarding

`apps/control-plane` is a separate Symfony 7.4/PHP 8.3 application with its
own `platform_commercial` PostgreSQL database and `commercial` schema. It does
not receive Yii/Diary database credentials and does not join the Diary Docker
network.

These boundaries are contractual:

- Control User != Diary User.
- Commercial Account != Diary Organization.
- Control Plane Auth != Diary Auth.

A central Control User is created before any tenant Diary exists. Phase 2 does
not create a tenant database, deployment, subscription or payment.

## Implemented schema

Ten ordered Doctrine migrations create exactly 25 `commercial.*` tables. The
21 Phase 1 tables remain unchanged. Phase 2 adds:

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

The current schema, including clearly marked future entities, is
`docs/database/commercial/Commercial_ControlPlane_v2.dbml`. Planned
subscriptions, effective entitlements, invoices, payments and usage tables do
not exist yet.

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
save the billing profile and advance onboarding after catalog review. Every
account resource is authorized from active account membership; an account UUID
from the URL is never trusted by itself. Roles and permissions live in the
central `AccountAuthorization` policy, not in controllers and not in Diary
RBAC.

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

Subscriptions, subscription items, effective entitlements, usage, payment
providers, charges, invoice processing, tenant DB creation, provisioning,
DNS/TLS, custom domains, On-Prem activation, MFA and updater execution remain
future phases.
