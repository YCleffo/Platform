# Commercial Control Plane — Phase 1 foundation

`apps/control-plane` is a separate Symfony 7.4 application on PHP 8.3. It owns
the `platform_commercial` PostgreSQL database and its `commercial` schema. Yii
Diary/Core owns a different PostgreSQL database. Neither application receives
the other's database credentials or joins the other's Compose network.

One Diary organization has one Core database. A commercial account is the
customer of Platform, not a Diary organization, and may later own multiple
deployments. `control_users` and account memberships are central identities;
they are deliberately not Diary users or Diary RBAC records. Authentication,
signup and public commercial APIs are later phases. The only public route now
is `GET /health`.

## Current schema

Seven ordered Doctrine migrations create exactly 21 Phase 1 tables in
`commercial.*`:

- Identity/customer: `control_users`, `commercial_accounts`,
  `commercial_account_memberships`, `billing_profiles`.
- Catalog: `features`, `plans`, `plan_prices`, `plan_features`.
- Signing/licensing: `signing_keys`, `licenses`, `license_installations`,
  `maintenance_contracts`, `issued_entitlement_documents`.
- Releases: `releases`, `release_entitlements`.
- Deployments: `deployments`, `deployment_domains`, `deployment_operations`.
- Reliability: `idempotency_keys`, `audit_events`, `outbox_events`.

Doctrine migration history remains in `public.doctrine_migration_versions` in
the Commercial database. The full target model, including subscriptions,
invoices, provider-neutral payments and usage, is in
`docs/database/commercial/Commercial_ControlPlane_v2.dbml`; tables marked
`Planned` there do not exist in Phase 1.

Published plan/release terms, issued entitlement documents and audit records
have database immutability guards. Account creation and cloud deployment
reservation write their business row, audit event and outbox event in one
transaction. Reservation creates a system domain and durable provisioning
operation, but no worker provisions infrastructure yet. A system domain is
retained when a custom domain is added. A deployment UUID, not its slug, is
the permanent identity.

## Local test workflow

Set `CONTROL_TEST_PASSWORD` to a local test-only value. The isolated profile
uses `control-postgres-test`, `control_test_net` and its own named volume;
neither Yii `backend` nor its test network is connected. From repository root:

```bash
bash ./scripts/control-test-start.sh
bash ./scripts/control-test-run.sh
APP_ENV=test bash ./scripts/control-test-reset.sh
bash ./scripts/control-test-stop.sh
```

PowerShell equivalents are `control-test-start.ps1`, `control-test-run.ps1`,
`control-test-reset.ps1`, and `control-test-stop.ps1` in `scripts/`.
`control:test:reset` refuses anything other than `APP_ENV=test`,
`CONTROL_TEST_ISOLATED=1`, host `control-postgres-test`, and database
`platform_commercial_test`. Reset drops only the isolated Commercial test
schema and migration history; it never targets development.

For development, set separate `CONTROL_POSTGRES_PASSWORD` and a 32+-character
`CONTROL_APP_SECRET` locally, then run the one-shot migration with `--rm`:

```bash
docker compose --profile control up -d control-postgres
docker compose --profile control run --rm control-migrate
docker compose --profile control up -d control-plane
```

The Commercial PostgreSQL port is bound to localhost and should not be published
in a production deployment. The test credentials used in CI are non-secret
throwaway values. Never put actual signing/private keys in `.env`, Git or the
Commercial database.

## Deferred phases

Phase 2 adds Control Plane authentication, signup and account onboarding.
Later phases add subscriptions/entitlements, invoices, provider-neutral
payments, provider adapters, provisioning, DNS/TLS, On-Prem issuance and a
signed-release updater. No provider SDK, automatic tenant database creation,
Docker socket, DNS API, ACME, SSH or renewal engine is present in Phase 1.
