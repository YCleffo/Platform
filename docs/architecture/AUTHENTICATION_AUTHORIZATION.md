# Среда аутентификации Core и граница авторизации

## Проверенное исходное состояние

Core already owns the PostgreSQL persistence required for password
authentication and authorization:

- `accounts` separates system identity from `persons` and contains lifecycle,
  lock, security-stamp, locale, and timezone state;
- `auth_identifiers`, `auth_password_credentials`, `auth_sessions`, and
  `auth_challenges` are the authoritative credential/session stores;
- passkey обслуживается полноценным WebAuthn Level 3 runtime (migration 014); TOTP, recovery-code и external-identity
  таблицы обслуживаются полноценным runtime;
- `roles`, `permissions`, `role_permissions`, and `account_roles` implement the
  RBAC data model;
- `audit_events` is the append-only security history;
- Communication and Outbox already provide transactional email delivery.

В репозитории также есть `SessionRepository`, сервис
`SessionActivityService`, own-session use cases, `PermissionService`,
`SecurityAuditService`, `ClientContext`, account preference handling, and safe
session presenters.

В начале этого этапа недостающей частью было применение правил во время выполнения:
в существующем хранилище не было полного протокола паролей, выпуска токенов,
идентификации запроса, middleware аутентификации и объявления разрешений на уровне маршрутов.
Описанная ниже среда выполнения теперь закрывает эту границу.

## Целевая среда выполнения

API использует случайные непрозрачные bearer-учётные данные. PostgreSQL `auth_sessions`
остаётся авторитетным источником; JWT и cookie аутентификации не вводятся.
Сохраняются только SHA-256-хеши токенов. Исходные access- и refresh-токены существуют
только в момент выдачи вызывающей стороне.

The request boundary is:

```text
ClientContextMiddleware
    -> route RequireAuthentication middleware
    -> route RequirePermission middleware when required
    -> Action
```

`AuthenticatedIdentity` is derived only from a valid bearer token, its active
session, and the current account security state. Query parameters, JSON, and
custom account-ID headers can never select the current identity.

## Решение по схеме

Migration `011_core_auth_runtime.sql` alters `core.auth_sessions` with one
required `access_expires_at timestamptz` column. Existing rows are safely
backfilled from absolute `expires_at`, then the column becomes non-null with a
constraint requiring `access_expires_at <= expires_at`.

The same migration seeds the minimal Core permission vocabulary and grants it
explicitly to `core.admin`. `core.superadmin` retains the existing centralized
`PermissionService` bypass. No new table is required and the Core table count
remains 23. Migrations `001` through `010` are immutable.

Migration `014_passkey_runtime.sql` extends `core.auth_passkeys` with columns
`transports text[]`, `backup_eligible boolean NOT NULL DEFAULT false`, and
`backup_state boolean NOT NULL DEFAULT false` for W3C WebAuthn Level 3 compliance.
No new table is added; total Core table count remains strictly 23. Migrations `001`
through `013` remain immutable.

## Контракт сессий и токенов

- Access and refresh tokens contain at least 256 bits of cryptographic entropy.
- Token persistence is `SHA-256(raw token)` only.
- Access expiry is short and independently configurable.
- `expires_at` is the absolute, non-sliding session expiry.
- Successful refresh atomically compares and replaces the refresh hash, access
  hash, and access expiry in one PostgreSQL statement.
- A rotated access or refresh credential immediately stops being valid.
- Revocation, absolute expiry, account lifecycle, lock state, and security-stamp
  mismatch invalidate authentication.

## Контракт авторизации

Permission requirements live in route middleware definitions. Actions do not
contain role-name conditionals and repositories do not silently enforce HTTP
authorization. Missing or invalid authentication returns `401` with
`WWW-Authenticate: Bearer`; an authenticated identity without the required
permission returns `403`.

Both responses use the global localized error contract. Their stable machine
codes are locale-independent, while the human message and `Content-Language`
come from the request-scoped Core Internationalization context. Authentication
and authorization code never returns inline public text or a domain exception
message.

The existing person, contact, account, role, and permission routes are all
protected. Self-service routes derive the account ID exclusively from
`AuthenticatedIdentity`. Role assignment is exposed only through named
account-role operations, never generic table CRUD.

## Первый супер-администратор

The first usable administrator is created only with
`php ./yii core:bootstrap-superadmin`. The command creates the Person, active
Account, verified primary email identifier, Argon2id password credential, and
`core.superadmin` assignment in one transaction. It refuses to run while an
active super administrator exists. The password is read from a hidden prompt;
non-interactive automation may inject `CORE_BOOTSTRAP_PASSWORD` in the process
environment and must not persist it in `.env` or pass it as a CLI argument.

Protected roles require a stronger invariant than generic `core.rbac.manage`:
only a superadmin may assign or revoke them, and the installation must never be
left without a usable active superadmin.

## Жизненный цикл учётных данных

Account invitations and password recovery use `auth_challenges` plus the
transactional Communication Outbox. Challenge credentials are random opaque
values and only their SHA-256 hashes enter `auth_challenges`. Activation and
reset are single-use. Successful password reset changes the password hash,
rotates the account security stamp, revokes every active session, records a
security audit event, and queues a security notification email.

Password-reset request always returns the same `202 Accepted` representation
for known and unknown addresses. Invitation, reset, and login throttling use
digested subjects and cover both IP and identifier dimensions.

Authenticated `POST /api/auth/me/password/change` requires the current
password and recent authentication. It rotates the Argon2id credential and
security stamp, revokes other sessions, writes Audit and queues mandatory
security email. `must_change_password` uses a short-lived
`password_change_required` challenge before normal session issuance;
`POST /api/auth/password/change-required` consumes it once. Yii already
implements reset, MFA, step-up/recent-auth and session self-service. The
separate Control Plane does not: reset, MFA, step-up and Control session
self-service remain Phase 4.1.

## Жизненный цикл аккаунта

Approval, disable, enable, and archive are explicit application operations;
there is no security-sensitive generic account CRUD. Disable and archive
rotate the security stamp and revoke active sessions. Protected-role revocation
and account disable/archive both lock the superadmin role while checking the
last-usable-superadmin invariant.

## Границы безопасности и конфиденциальности

Passwords, password hashes, raw access/refresh/challenge tokens, and recovery
secrets never enter Audit or application logs. Failed login responses are
generic and do not reveal identifier, account, verification, or credential
existence. Audit may record the identifier type and a keyed/one-way identifier
digest together with the existing safe ClientContext metadata.

Redis is permitted only behind a narrow rate-limiter abstraction for
short-lived anti-abuse counters. It is never authoritative for sessions,
accounts, RBAC, challenges, or password state. Rate-limit keys contain no
plaintext identifiers. Authentication abuse-sensitive endpoints fail closed
with `503 Service Unavailable` when Redis cannot enforce their limits; they do
not silently disable protection.

Bearer-header authentication is not automatically attached by browsers, so
CSRF middleware is not part of this contract. Introducing authentication
cookies later also requires introducing CSRF protection in the same change.

## Намеренно исключённые функции

Этот этап не реализует API keys, SAML, SMS/email OTP как обычный
второй фактор, frontend или optional product modules. Google/Telegram OIDC, Passkeys (WebAuthn Level 3),
TOTP, recovery codes и email MFA recovery описаны в `docs/security/AUTHENTICATION_AND_MFA.md`.
