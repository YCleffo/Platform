# Platform Core invariants

This document is required context for changes to Core, Commercial Control Plane,
their APIs, migrations, workers, and deployment contracts. It records the
boundaries that must survive a release; it is not a claim that every production
reliability target has already been met. Requirement IDs refer to the
production-grade technical specification (CORE-01, COMM-01, PAY-03, etc.).

## Sources of truth and compatibility

- Current database structure comes from `apps/backend/migrations/sql/` and
  `apps/control-plane/migrations/`, checked by their schema tests. DBML and
  prose are explanatory and must be updated when migrations change.
- Preserve existing public routes, event schemas, authentication methods, and
  stored relations. Add compatible fields or version a contract when an old
  consumer cannot read the new shape. Use expand, migrate, contract across
  separate releases; an old application must remain compatible during rollout.
- `contracts/openapi/` describes HTTP contracts and `contracts/events/`
  describes cross-process events. A code change to either surface updates its
  contract and compatibility tests in the same change.

## Identity and database ownership (CORE-01/02, COMM-01/02)

- `Person != Account`: a person is a human record; an account has its own
  authentication and status lifecycle. Contacts on a Person are not implicit
  login identifiers. Preserve explicit links and their access checks.
- Control User and Core Account are separate identities. Commercial Account and
  Core Organization are separate entities. Never infer equality from a shared
  email, name, or UUID. Cross-plane mapping must be explicit and authenticated.
- Yii Core owns its PostgreSQL database and `core` and `platform_runtime`
  schemas. Symfony Control Plane owns a separate `platform_commercial` database
  and `commercial` schema. Neither process receives the other's DB credentials
  or performs cross-database joins/transactions. Communicate by versioned,
  authenticated API or event contracts.
- PostgreSQL is the transactional source of truth. Redis holds temporary
  cache, rate-limit, presence, or lock state; loss of Redis cannot be treated as
  loss of durable business history.
- The last usable `core.superadmin` is an active human Account linked to an
  active Person with a current role assignment. Service and system accounts,
  disabled Accounts, and archived Persons cannot satisfy this safety guard.

## Financial state (CAT-01/02, SUB-01, BILL-01/02/04, PAY-01/02/07/08,
REF-02/04, FISC-01/02)

- Published catalog plan versions, prices and features, subscription item
  snapshots, and issued invoice and invoice-line snapshots are historical
  records. A new price/version creates new records; it cannot rewrite old
  charges. Payment state does not recalculate an issued invoice.
- Amounts and currency are taken from the locked local contract or issued
  invoice. Use integer minor units or exact decimal arithmetic; never binary
  floating point for money. Refund allocations cannot exceed settled money,
  including under concurrent requests.
- Payment Core owns settlement semantics; YooKassa is an adapter and an
  external observation, not the business source of truth. A successful
  settlement cannot regress because an older webhook arrives. Receipt delivery
  has its own lifecycle; a receipt failure cannot undo a paid invoice.
- Subscription, invoice, payment, refund, and entitlement transitions must be
  serialized where concurrent actors can cause duplicate charges or effects.
  Keep the current lock order and authoritative reconciliation paths when
  extending these state machines.

## Idempotency and asynchronous effects (CORE-12, PAY-03/04/05/06,
SUB-06/07, REF-03, PROV-03)

- A financial mutation has a stable application-level idempotency key and
  records the result atomically with its business change. An identical retry
  returns the same outcome; a conflicting payload cannot reuse the key.
- Provider request keys remain stable across retries of the same attempt. An
  ambiguous provider timeout remains uncertain until authoritative lookup or
  reconciliation; it must not trigger a blind new charge or refund.
- Webhooks are notifications. Deduplicate the inbox, verify by authoritative
  provider fetch, and allow reordered/repeated delivery without another
  business effect. Outbox delivery is at least once; consumers deduplicate by
  event identity and consumer. An audit event is not automatically an
  integration event.
- Workers and provision operations resume after crashes from durable state.
  Do not hold a database transaction across a provider network call.

## Entitlements and monotonic state (CORE-13, ENT-01/02/03)

- Effective commercial entitlements are derived from the current commercial
  subscription and immutable plan features. They are not a separately mutable
  business truth in Core.
- Control Plane issues signed, immutable deployment documents. Core verifies
  exact document bytes, trusted key, signature, installation, validity and
  monotonically increasing sequence before applying them. A stale, expired,
  or revoked document cannot resurrect access.
- Core stores only verified local entitlement snapshots and trusted public
  keys; it does not hold the commercial signing private key. A temporary sync
  failure retains the last valid local document subject to its validity rules.

## Review gate for every change

Identify the affected requirement IDs and owners before editing. Check API and
event compatibility, migration coexistence, authorization, idempotency, race
and crash recovery, sensitive-data logging, and current regression tests.
Changes to money, identity, or entitlements require tests of the invariant,
not only a successful response. Production HA, PITR, SLO, and deployment
readiness require operational evidence beyond passing local tests.

Further detail: [Core data model](../core-data-model.md),
[database boundaries](DATABASE.md), [commercial lifecycle](COMMERCIAL_CONTROL_PLANE.md),
[event contracts](EVENT_CONTRACTS.md), and [M2M entitlement delivery](M2M_SERVICE_AUTHENTICATION.md).
