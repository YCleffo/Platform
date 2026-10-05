# Commercial Control Plane security boundary

Phase 2 uses a dedicated central identity, not Diary authentication. Passwords
are hashed with Symfony PasswordHasher. Email lookup is lowercase and unique in
PostgreSQL; presentation email is trimmed and retained separately. Status
transitions are constrained to `pending_verification`, `active`, `suspended`
and `disabled`.

Email verification and session credentials are never stored raw. Verification
tokens are one-time and expiring. Opaque access tokens are short-lived;
server-side refresh tokens rotate on every use. Reuse of the previous refresh
token revokes the session family. Logout is idempotent. Expired artifacts are
removed by `control:security:cleanup`, not by arbitrary HTTP requests.

Registration, login, verification resend, refresh and account creation use
expiring database rate-limit counters with HMAC-pseudonymized keys. API bodies
are limited to 64 KiB. API errors never include SQL, stack traces, filesystem
paths or secrets. Auth responses are `no-store`; all responses receive
`nosniff`, deny framing, `frame-ancestors 'none'` and `no-referrer`. CORS is
closed by default. Authentication uses Authorization bearer tokens, not
cookies, so this API boundary does not rely on cookie CSRF protection.

Account permissions are centralized. Owner, admin, billing and technical are
Control Plane roles and are unrelated to Diary RBAC. Account membership is
checked for every account resource. Owner removal and downgrade are serialized
under the account row lock and backed by deferred database constraints, so
concurrent requests cannot leave an account ownerless.

Audit events include correlation IDs and fixed, redacted metadata. They never
copy passwords, raw tokens or Authorization headers. Transactional outbox rows
contain no raw credential. Registration, verification, login summary, account
creation, membership changes and billing profile changes are audited. Login
failure audit is protected by the login rate limits.

`/health` reveals only `ok` or `not_ready` and checks only the presence of the
required schema objects. Production boot requires both `CONTROL_APP_SECRET`
and `CONTROL_TOKEN_SECRET` to contain at least 32 characters. The Commercial
and Diary databases use separate credentials, volumes and networks.

The production Docker image pins the FrankenPHP base manifest, runs as
`www-data`, installs dependencies with `--no-dev`, excludes tests, `.env` and
Git metadata, and makes only `var/cache`, `var/log` plus FrankenPHP data/config
directories writable. CI reports High vulnerabilities and fails on any
Critical vulnerability.

The Commercial Control Plane is a separate authentication boundary from Core. It implements encrypted TOTP secrets, pending-to-active enrollment, replay protection, bounded single-use MFA login challenges, one-time recovery codes with concurrency protection, password reset with hash-only/expiring single-use artifacts, and session/security-artifact revocation after reset. Recent primary authentication plus recent MFA protects sensitive Control Plane mutations including billing, refunds, subscriptions, and saved payment methods.
