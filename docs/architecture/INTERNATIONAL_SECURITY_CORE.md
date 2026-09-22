# Интернационализация и безопасность Core

## Проверенное состояние до этого этапа

- В Core 23 таблицы PostgreSQL. `core.accounts`, `core.auth_sessions`,
  `core.audit_events`, and `core.system_settings` are the relevant existing
  persistence boundaries.
- `core.auth_sessions` уже является авторитетным серверным хранилищем сессий.
  It contains token hashes, security stamp, authentication method, current IP,
  User-Agent, timestamps, and revocation state.
- `core.audit_events` уже хранит сведения об акторе, действии, ресурсе, запросе и
  correlation identifiers, IP, User-Agent, result, and JSON metadata.
- В шаблонах Communication используются канонические идентификаторы локали `ru-RU` и `en-US`.
- В сопоставлениях временных меток репозиториев обычно используется PostgreSQL `timestamptz::text`, поэтому
  API-visible formatting is not yet a stable RFC3339 UTC contract.
- Redis развёртывается через Compose, но клиент Redis и его интеграция с DI приложения
  integration.
- В HTTP-конвейере нет общего контекста клиента и не обрабатываются заголовки прокси
  headers.

## Реализованный фундамент

Platform Core предоставляет единую основу для интернационализации, времени, контекста
клиента и безопасности сессий:

- canonical BCP 47 locales `ru-RU` and `en-US`;
- UTC internal time and RFC3339 UTC serialization;
- IANA timezone validation, explicit resolution source, and CLDR formatting;
- immutable per-request client context;
- trusted-proxy-aware IPv4/IPv6 resolution;
- normalized, non-invasive device information;
- HMAC device risk signal that is never an authentication factor;
- approximate provider-based GeoIP with safe unknown fallback;
- PostgreSQL-backed session lifecycle and append-only Audit history;
- deterministic risk signals that advise Auth policy but do not block accounts.

Этот слой не включает Education, Games, Chat, реализацию frontend или полный сценарий входа.

## Недостающие поля схемы и миграция 010

Миграция `010_core_international_security.sql` изменяет только существующие таблицы.

В `core.accounts` добавляются необязательные сохранённые настройки:

- `locale varchar(35)`;
- `timezone varchar(64)`.

В `core.auth_sessions` добавляется снимок клиентских данных для отображения и безопасности:

- browser name/version, OS name/version, device type/model;
- client locale/timezone and optional client instance UUID;
- 32-byte HMAC fingerprint hash;
- approximate GeoIP country code, region, city, and timezone.

Частичный индекс по аккаунту и времени последней активности ускоряет список активных сессий.
Новая таблица не требуется: настройки аккаунта занимают два столбца, снимок текущей сессии
относится к `auth_sessions`, а история изменений IP/устройства — к Audit. В Core по-прежнему
23 таблицы. Миграции `001`–`009` не изменяются.

## Locale contract

Application locale values are canonical BCP 47 tags. `ru`, `ru_RU`, and
case-equivalent forms normalize to `ru-RU`; `en` and `en_US` normalize to
`en-US`. New persistence and Outbox payloads use canonical tags.

For an anonymous request, resolution priority is supported `Accept-Language`,
then installation default. For an authenticated request, an explicit supported
`Accept-Language` preference wins over the saved account locale, followed by
the installation default. Header negotiation honors quality values, original
order, language fallback, wildcard, and `q=0`. Machine-readable API identifiers
are stable and are never translated; only user-facing text uses message catalogs.

## Time contract

Database and internal application time are UTC. API timestamps are RFC3339 UTC,
for example `2026-09-20T15:42:11Z`. Calendar dates such as `birth_date` remain
dates and are never converted to timestamps.

Display timezone values are IANA identifiers. Resolution priority is saved user
timezone, client-reported timezone, approximate GeoIP timezone, then `UTC`.
Locale never implies timezone. DST and historical offset changes are delegated
to PHP's IANA timezone database and `IntlDateFormatter`.

## Client context contract

One immutable context contains resolved IP, User-Agent, `Accept-Language`,
locale, reported and resolved timezone (including its source), normalized client
hints, device info, optional client-instance ID, optional fingerprint HMAC,
approximate GeoIP result, and request ID. A middleware computes it once and
places it on the PSR request; Actions must not independently reinterpret headers.

## Trusted proxy contract

The direct socket address is authoritative by default. `Forwarded` and
`X-Forwarded-For` are honored only when the direct peer matches an explicit IP
or CIDR in `TRUSTED_PROXIES`. Resolution walks the chain from the application
toward the client and stops at the first untrusted address. Invalid header
values are ignored. Neither request JSON nor an untrusted proxy header can set
the security IP.

## Session security contract

PostgreSQL `core.auth_sessions` remains the source of truth. Application use
cases list active sessions for the authenticated account, find one owned
session, throttle `last_seen_at` writes, revoke one, revoke all others, or revoke
all. Public routes stay unregistered until authenticated server-side identity
middleware exists; account IDs are never accepted from request input for “own
sessions”. Token hashes, refresh hashes, and security stamps are internal only.

Audit actions are namespaced (`auth.session.created`, `.revoked`,
`.revoked_all`, `.ip_changed`, `.device_changed`). Session rows contain the
current snapshot; Audit contains the historical trail.

## Device and fingerprint boundary

Device parsing is local and provider-based. Normalized fields describe browser,
OS, broad device type/model, and client type. There is no canvas, audio, font,
WebGL, hardware serial, or other invasive fingerprinting.

An optional client instance UUID is distinct from the server session ID. The
device fingerprint is an HMAC-SHA-256 over a small normalized set of stable
client signals. It excludes IP, GeoIP, tokens, and timezone. Only the binary
HMAC is persisted. It is a risk signal, never identity or authentication proof.

## GeoIP limitations

GeoIP is approximate location inferred from an IP address. Private, reserved,
loopback, invalid, or unavailable-database lookups return unknown. The preferred
adapter reads a local MaxMind GeoLite2 database configured by environment; the
database binary is not committed. Missing database or lookup failure must not
fail a request and no external HTTP call is required.

Redis is not introduced merely because its container exists. Local database
lookup is fast and correctness does not require a cache. A future best-effort
cache decorator may be added with bounded TTL; Redis must never become the
session or GeoIP source of truth.

## Security and privacy boundaries

IP, User-Agent, approximate location, client instance ID, and fingerprint HMAC
are security/privacy data. They are collected to let account owners understand
active sessions and to support security review. Owners may see normalized
device, current IP, approximate city/country, creation time, and last activity.
Administrative access requires a future explicit permission policy.

Never store or log passwords, password hashes, session or refresh tokens, TOTP
secrets, recovery codes, challenge secrets, SMTP credentials, or raw
fingerprints in Audit metadata or arbitrary logs. GeoIP, timezone, forwarded
headers, and fingerprint signals are not proof of compromise.

Audit retention is installation policy and is documented/configurable. This
stage does not perform destructive cleanup without a product-approved retention
policy. Suggested defaults are 365 days for security Audit and normal session
revocation/expiry retention until a later cleanup policy is approved.
