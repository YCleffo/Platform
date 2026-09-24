# Commercial Control Plane security boundary

Phase 1 is closed by default. `/health` returns only `{"status":"ok"}` or
`{"status":"not_ready"}`; it does not expose credentials, exception traces or
environment values. Other routes require an operator role, but no operator
login is implemented yet. Do not publish new write routes before Phase 2
authentication and account ownership checks.
The production kernel refuses to boot when `CONTROL_APP_SECRET` has fewer than
32 characters.

The Commercial and Diary PostgreSQL services have separate users, passwords,
volumes and Docker networks. The Control Plane has no Docker socket or tenant
Diary SQL connection. Signing-key rows contain a 32-byte Ed25519 public key
and a `vault://`, `kms://` or `hsm://` reference, never private-key bytes.
Deployment database credentials also use a secret reference. Payment PAN,
CVV and provider credentials are not modeled in the Phase 1 schema.

Database constraints cover canonical machine codes, unique account codes,
plan versions, slugs and hostnames, typed feature values, nonnegative prices,
uppercase currency, valid state sets and intervals, signing key metadata,
license installation limits, published catalog/release immutability, one
default billing profile, one system domain per Cloud deployment, immutable
issued documents, append-only audit and unique idempotency keys. The
application additionally validates machine identifiers, labels, slugs and
IDNA domains before persistence. Each main HTTP request receives a validated
or generated UUID correlation ID; it is returned in `X-Correlation-ID` and
added to structured Monolog records.

`CreateCommercialAccount` and `ReserveDeployment` commit their outbox and
audit rows with the business row. No background outbox dispatcher exists yet.
Audit metadata on these paths is fixed/empty rather than copied from raw
request bodies. Logs use structured Monolog output; handlers must continue
to omit secrets and request bodies. Future authenticated APIs must enforce
account ownership per request. Database and service credentials are not
security boundaries against a PostgreSQL superuser or an On-Prem root user.

Signed entitlement issuance is not implemented. Before a signer is added,
define and test a versioned canonical envelope (document version,
installation ID, monotonic sequence, issuance/validity and payload), sign
the exact canonical bytes, and retain the immutable bytes/hash/signature.
Do not treat arbitrary JSON serialization order as canonical and do not add
fake signatures to draft licenses/releases.

The test reset command checks environment, isolation marker, host and
database before executing any `DROP`. Run integration/constraint tests only
through the isolated Commercial test runtime. The production image contains
no development dependencies and runs as `www-data`; the separate test image
includes PHPUnit and static-analysis tooling.
