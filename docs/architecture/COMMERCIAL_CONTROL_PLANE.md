# Commercial Control Plane — Phase 3.6 Payment Core

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

Fifteen ordered Doctrine migrations create exactly 34 `commercial.*` tables.
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
historical invoice. The `paid` state is reserved for Payment Core and does not
activate a subscription in this phase.

Phase 3.6 adds provider-neutral `payment_provider_accounts`, `payments` and
`payment_attempts`. One payment mirrors one invoice's immutable money, while
multiple historical attempts model provider retries with at most one pending
attempt. A trusted internal success confirmation atomically succeeds the
attempt/payment, pays the invoice, activates its draft subscription, opens the
usage period and writes audit/outbox lifecycle events. Direct activation is
payment-gated. Zero-amount invoices create a historical succeeded payment and
activate without a fake provider or attempt. Concrete provider clients,
webhooks, refunds and receipts are intentionally absent.

The current schema, including clearly marked future entities, is
`docs/database/commercial/Commercial_ControlPlane_v2.dbml`. Effective
entitlements are derived at read time from the current active subscription,
its valid items and immutable plan features. There is no mutable
`commercial.entitlements` table. Usage is a separate mutable read/write model.
Invoice is not payment; payment execution and usage-based charging remain
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
reads through `GET /api/control/accounts/{accountId}/payments/{paymentId}`, and
`GET /api/control/accounts/{accountId}/entitlements` plus the read-only
`GET /api/control/accounts/{accountId}/usage`. Every account
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

Renewal, plan switching, concrete payment providers, payment methods,
provider webhooks/events, refunds, receipts and fiscalization,
usage-based and overage billing, service-to-service usage ingestion, signed
entitlement issuance, tenant DB creation, provisioning, DNS/TLS, custom
domains, On-Prem activation, MFA and updater execution remain future phases.
