# Модель данных Core

Этот документ описывает архитектуру контракта PostgreSQL, заданного файлами
`apps/backend/migrations/sql/001_core_schema_and_types.sql`–
`015_core_data_hygiene.sql`. Зафиксированный Core содержит ровно 23 основные
таблицы `core`. Миграции SQL остаются источником истины.

Миграция `016_platform_runtime.sql` добавляет отдельную схему с двумя таблицами
для идентичности установки и подписанных entitlement snapshots. Она не меняет
число таблиц Core. Её точная модель находится в
`docs/database/runtime/Platform_Runtime_v1.dbml`; будущая Commercial DB будет
отдельной базой данных.

## Соглашения и границы

- Для первичных ключей `uuid` используется `uuidv7()` PostgreSQL 18, если не указано иное.
- В `audit_events`, `outbox_events` и `inbox_events` используются первичные ключи идентичности `bigint` и отдельный UUID события.
- Поле с пометкой `nullable` допускает SQL `NULL`; все остальные перечисленные поля имеют ограничение `NOT NULL`.
- После вставки значениями `created_at`/`updated_at` управляет приложение; схема задаёт только `DEFAULT now()` и не определяет триггер обновления.
- Таблицы настроек явно не предназначены для секретов. Приложение-инициатор должно фильтровать JSON аудита/событий; там запрещено хранить учётные данные и токены.
- Покрытие означает, что у каждой таблицы есть владеющий ею репозиторий/сервис, но не то, что для каждой таблицы существует публичный HTTP CRUD.

### Перечисления PostgreSQL

| Тип | Значения |
|---|---|
| `person_status` | `active`, `inactive`, `archived` |
| `contact_type` | `email`, `phone` |
| `account_type` | `human`, `service`, `system` |
| `account_status` | `pending_verification`, `pending_approval`, `active`, `disabled`, `archived` |
| `auth_identifier_type` | `username`, `email`, `phone` |
| `totp_algorithm` | `sha1`, `sha256`, `sha512` |
| `audit_actor_type` | `anonymous`, `account`, `system`, `service` |
| `outbox_status` | `pending`, `processing`, `published`, `failed`, `dead` |
| `inbox_status` | `received`, `processing`, `processed`, `failed`, `dead` |

## Персона

### `core.persons`

- **Назначение и владелец:** физические лица; владелец — `Core/Person`. Person намеренно не зависит от Account.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Поля:** `status person_status DEFAULT 'active'`, `given_name varchar(120)`,
  `family_name varchar(120)`, `middle_name varchar(120)` (nullable),
  `display_name varchar(255)` (nullable), `birth_date date` (nullable),
  `created_at`, `updated_at`, and `archived_at` (nullable) as `timestamptz`.
- **Проверки:** имена и фамилии после удаления пробелов не должны быть пустыми.
- **Связи и удаление:** родительская запись для контактов (`CASCADE`) и необязательных связей с аккаунтом (`RESTRICT`).
- **Индексы и уникальность:** PK; индексы по `status`, `(family_name, given_name)`,
  и `birth_date`.
- **Чувствительные данные:** дата рождения и имена являются персональными данными; учётных данных здесь нет.
- **Доступ:** публичный API приложения для чтения, записи и архивации; API никогда не удаляет запись физически. Обслуживается `PersonRepository` и сценариями Person.

### `core.person_contacts`

- **Назначение и владелец:** способы связи с Person; владелец — `Core/Person`. Контакты не являются идентификаторами аутентификации.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Поля:** `person_id uuid`, `contact_type contact_type`,
  `value varchar(320)`, `normalized_value varchar(320)`, `label varchar(80)`
  (nullable), `is_primary boolean DEFAULT false`, `verified_at timestamptz`
  (nullable), `created_at`, `updated_at`.
- **FK:** `person_id -> persons.id ON DELETE CASCADE`.
- **Проверки:** `value` и `normalized_value` после удаления пробелов не должны быть пустыми.
- **Indexes/uniqueness:** unique `(person_id, contact_type, normalized_value)`;
  partial unique `(person_id, contact_type) WHERE is_primary`; indexes on
  `(person_id, contact_type)` and `normalized_value`.
- **Чувствительные данные:** значения контактов являются персональными данными.
- **Access:** Person-scoped public read/create API; updates are internal until a
  justified command is defined. Served by `PersonContactRepository` and Person
  contact use cases. Deleting a Person cascades contacts at DB level, while the
  public Person API archives rather than deletes. Phase-one normalization only
  trims surrounding whitespace through a dedicated `ContactNormalizer`; exact
  email/phone canonicalization remains an explicit policy TODO.

## Account

### `core.accounts`

- **Назначение и владелец:** системные идентичности; владелец — `Core/Account`. Account не является Person.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `person_id uuid` (nullable), `account_type account_type DEFAULT
  'human'`, `status account_status DEFAULT 'pending_verification'`, `label
  varchar(160)` (nullable), `security_stamp uuid DEFAULT uuidv4()`,
  `locked_until`, `last_login_at`, `approved_at`, `disabled_at`, `archived_at`
  (all nullable `timestamptz`), `created_by_account_id`,
  `approved_by_account_id` (nullable UUIDs), optional canonical BCP 47 `locale`,
  optional IANA `timezone`, `created_at`, `updated_at`.
- **FK/delete behavior:** `person_id -> persons.id RESTRICT`; creator/approver
  self-references use `SET NULL`.
- **Checks:** human accounts must have a `person_id`.
- **Indexes/uniqueness:** unique `person_id` (PostgreSQL permits multiple NULLs);
  indexes on `status`, `account_type`, and `approved_by_account_id`.
- **Sensitive:** `security_stamp`, lock/login metadata, and approval provenance
  are internal security data and are omitted from ordinary presenters.
- **Access:** limited public application API for list/read/create; lifecycle and
  security fields remain internal. International preferences are validated and
  updated by `UpdateAccountPreferences` through `AccountPreferencesRepository`;
  they are not exposed by an unauthenticated route.

## Аутентификация

Все таблицы Auth принадлежат `Core/Auth`. Они доступны только внутренним сервисам или инфраструктуре; универсального публичного CRUD для них нет.

### `core.auth_identifiers`

- **Purpose:** account login/recovery identifiers.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `account_id uuid`, `identifier_type auth_identifier_type`, `value`
  and `normalized_value varchar(320)`, `is_primary boolean DEFAULT false`,
  `can_login boolean DEFAULT true`, `can_recover boolean DEFAULT false`,
  `verified_at timestamptz` (nullable), `created_at`, `updated_at`.
- **FK/delete:** `account_id -> accounts.id CASCADE`.
- **Checks:** trimmed value and normalized value are non-blank.
- **Indexes/uniqueness:** unique `(identifier_type, normalized_value)`; partial
  unique `(account_id, identifier_type) WHERE is_primary`; indexes on
  `account_id` and `(account_id, identifier_type)`.
- **Sensitive/access:** identifier values and recovery capability are sensitive
  personal/security data. Internal `IdentifierRepository` lookup/write service.

### `core.auth_password_credentials`

- **Purpose:** optional password credential, zero or one per account.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `account_id uuid`, `password_hash text`, `password_algorithm
  varchar(32)`, `must_change_password boolean DEFAULT false`,
  `password_changed_at`, `created_at`, `updated_at`.
- **FK/delete:** `account_id -> accounts.id CASCADE`.
- **Checks:** password hash length is positive; trimmed algorithm is non-blank.
- **Indexes/uniqueness:** unique `account_id`.
- **Sensitive/access:** the hash and all credential state are secret internal
  data. Infrastructure-only `PasswordCredentialRepository`; never presented.

### `core.auth_providers`

- **Purpose:** registry of external authentication providers.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `code varchar(50)`, `protocol varchar(50)`, `display_name
  varchar(120)`, `enabled boolean DEFAULT false`, `allow_login boolean DEFAULT
  true`, `allow_registration boolean DEFAULT false`, `created_at`, `updated_at`.
- **Checks:** trimmed code, protocol, and display name are non-blank.
- **Indexes/uniqueness:** unique `code`; index on `enabled`.
- **Sensitive/access:** metadata itself is non-secret; provider credentials do
  not belong here. Internal `AuthProviderRepository`.

### `core.auth_external_identities`

- **Purpose:** links provider subjects to local accounts.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `account_id`, `provider_id`, `provider_subject varchar(255)`,
  `email varchar(320)`, `display_name varchar(255)`, `avatar_url text`,
  `last_login_at timestamptz` (all four nullable), `email_verified boolean
  DEFAULT false`, `claims jsonb DEFAULT {}`, `linked_at timestamptz`.
- **FK/delete:** account `CASCADE`; provider `RESTRICT`.
- **Checks:** trimmed provider subject is non-blank.
- **Indexes/uniqueness:** unique `(provider_id, provider_subject)` and
  `(account_id, provider_id)`; indexes on account, provider, and email.
- **Sensitive/access:** subject, email, claims, and login metadata are sensitive;
  claims must not contain provider tokens. Internal `ExternalIdentityRepository`.

### `core.auth_passkeys`

- **Purpose:** WebAuthn/passkey public credentials.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `account_id uuid`, `credential_id bytea`, `public_key bytea`,
  `sign_count bigint DEFAULT 0`, `aaguid uuid` (nullable), `transports text[]
  DEFAULT []`, `label varchar(160)`, `backup_eligible boolean DEFAULT false`,
  `backup_state boolean DEFAULT false`, `last_used_at`, `revoked_at` (nullable),
  `created_at`.
- **FK/delete:** account `CASCADE`.
- **Checks:** non-negative sign count and non-empty credential/public-key bytes.
- **Indexes/uniqueness:** unique credential ID; indexes on account and revoked time.
- **Sensitive/access:** credential material and authenticator metadata are
  security-sensitive even though the stored key is public. Internal
  `PasskeyRepository`; no generic response presenter.

### `core.auth_totp_factors`

- **Purpose:** TOTP MFA factors.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `account_id uuid`, `label varchar(160)` (nullable),
  `secret_encrypted bytea`, `algorithm totp_algorithm DEFAULT 'sha1'`, `digits
  smallint DEFAULT 6`, `period_seconds smallint DEFAULT 30`, `verified_at`,
  `last_used_at`, `revoked_at` (nullable), `created_at`.
- **FK/delete:** account `CASCADE`.
- **Checks:** encrypted secret non-empty; digits 6..8; period positive.
- **Indexes/uniqueness:** indexes on account and revoked time.
- **Sensitive/access:** encrypted TOTP secret is secret; encryption keys stay
  outside the database. Infrastructure-only `TotpFactorRepository`.

### `core.auth_recovery_codes`

- **Purpose:** single-use MFA recovery-code hashes.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `account_id uuid`, `code_hash bytea`, `created_at`, `used_at`
  (nullable).
- **FK/delete:** account `CASCADE`.
- **Checks:** code hash is non-empty.
- **Indexes/uniqueness:** unique code hash; indexes on account and used time.
- **Sensitive/access:** hashes are credentials. Infrastructure-only
  `RecoveryCodeRepository`; raw codes are never stored or returned.

### `core.auth_sessions`

- **Purpose:** active and historical opaque-token sessions.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `account_id uuid`, `session_token_hash bytea`,
  `refresh_token_hash bytea` (nullable), `security_stamp uuid`,
  `authentication_method varchar(80)`, `mfa_verified_at timestamptz` (nullable),
  `client_type varchar(40)`, `device_name varchar(160)`, `ip_address inet`,
  `user_agent text`, browser/OS name and version, device type/model, canonical
  client locale, IANA client timezone, optional client-instance UUID,
  32-byte device-fingerprint HMAC, approximate GeoIP country/region/city/timezone
  (all nullable), `created_at`, `last_seen_at`, `expires_at`, `revoked_at
  timestamptz`, `revoke_reason varchar(255)` (nullable).
- **FK/delete:** account `CASCADE`.
- **Checks:** session hash non-empty; trimmed auth method/client type non-blank;
  expiry is later than creation.
- **Indexes/uniqueness:** unique session-token hash and refresh-token hash
  (multiple NULLs allowed); indexes on account, expiry, `(account, expiry)`, and
  a partial `(account, last_seen_at DESC)` index for non-revoked sessions.
- **Sensitive/access:** token hashes, stamp, network/device data, and revocation
  data are sensitive. `SessionRepository` plus own-session application use cases
  list safe metadata, throttle activity writes, and revoke owned sessions. No
  token hash, refresh hash, security stamp, or raw fingerprint is presented.

### `core.auth_challenges`

- **Purpose:** short-lived verification/reset/invitation/login challenges.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `account_id uuid` (nullable), `challenge_type varchar(80)`,
  `secret_hash bytea`, `target_normalized varchar(320)` (nullable), `payload
  jsonb DEFAULT {}`, `attempts smallint DEFAULT 0`, `max_attempts smallint
  DEFAULT 5`, `created_at`, `expires_at`, `consumed_at`, `cancelled_at` (last two
  nullable).
- **FK/delete:** account `CASCADE` when present.
- **Checks:** trimmed type non-blank; secret non-empty; attempts non-negative;
  max attempts positive; expiry later than creation.
- **Indexes/uniqueness:** unique secret hash; indexes on account and
  `(challenge_type, expires_at)`.
- **Sensitive/access:** hash, target, payload, attempts, and lifecycle are
  sensitive; payload must not hold raw secrets. Internal
  `AuthChallengeRepository`.

## RBAC

### `core.roles`

- **Purpose/ownership:** named permission bundles, owned by `Core/Rbac`.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `code varchar(120)`, `name varchar(160)`, `description text`
  (nullable), `is_system boolean DEFAULT false`, `is_protected boolean DEFAULT
  false`, `created_at`, `updated_at`.
- **Checks:** trimmed code and name are non-blank.
- **Indexes/uniqueness:** unique code; index on `is_system`.
- **Sensitive/access:** non-secret catalog. Public read API; writes/assignment
  are guarded internal application operations. `RoleRepository` plus
  `PermissionService`. Seed defines protected `core.superadmin` and `core.admin`.

### `core.permissions`

- **Purpose/ownership:** global permission catalog, owned by `Core/Rbac`.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `module_id uuid` (nullable), `code varchar(180)`, `name
  varchar(180)`, `description text` (nullable), `is_system boolean DEFAULT
  false`, `created_at`, `updated_at`.
- **FK/delete:** module `SET NULL`.
- **Checks:** trimmed code and name are non-blank.
- **Indexes/uniqueness:** unique code; index on module.
- **Sensitive/access:** non-secret catalog. Public read API and internal
  `PermissionRepository`/`PermissionService`.

### `core.role_permissions`

- **Purpose/ownership:** role-to-permission grants, owned by `Core/Rbac`.
- **PK:** composite `(role_id, permission_id)`.
- **Fields:** `granted_at timestamptz DEFAULT now()`,
  `granted_by_account_id uuid` (nullable).
- **FK/delete:** role and permission `CASCADE`; granting account `SET NULL`.
- **Indexes/uniqueness:** composite PK; index on `permission_id`.
- **Sensitive/access:** grant provenance is internal. No standalone HTTP
  resource; managed by `RoleRepository` and queried by `PermissionService`.

### `core.account_roles`

- **Purpose/ownership:** role assignments to accounts, owned by `Core/Rbac`.
- **PK:** composite `(account_id, role_id)`.
- **Fields:** `assigned_at timestamptz DEFAULT now()`,
  `assigned_by_account_id uuid` (nullable), `expires_at timestamptz` (nullable).
- **FK/delete:** account and role `CASCADE`; assigning account `SET NULL`.
- **Indexes/uniqueness:** composite PK; indexes on role and expiry.
- **Sensitive/access:** account authorization and provenance are security data.
  Internal assignment operations and `PermissionService`; no generic CRUD.

## Модули

### `core.modules`

- **Purpose/ownership:** metadata-only module registry, owned by `Core/Module`.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `code varchar(100)`, `name varchar(160)`, `description text`
  (nullable), `version varchar(40)`, `settings_schema_version integer DEFAULT
  1`, `enabled boolean DEFAULT false`, `installed_at`, `enabled_at`,
  `disabled_at` (last two nullable `timestamptz`).
- **Checks:** trimmed code/name/version non-blank; settings schema version positive.
- **Indexes/uniqueness:** unique code; index on enabled.
- **Sensitive/access:** non-secret metadata. Internal `ModuleRepository` and
  `ModuleService::isEnabled`; no domain-module dependency or generic public CRUD.

## Настройки

### `core.module_settings`

- **Purpose/ownership:** non-secret per-module configuration, owned by
  `Core/Settings`.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `module_id uuid`, `setting_key varchar(160)`, `value jsonb`,
  `description text` (nullable), `updated_by_account_id uuid` (nullable),
  `created_at`, `updated_at`.
- **FK/delete:** module `CASCADE`; updating account `SET NULL`.
- **Checks:** trimmed key non-blank.
- **Indexes/uniqueness:** unique `(module_id, setting_key)`.
- **Sensitive/access:** secrets are forbidden by schema contract/comments;
  values are nevertheless internal configuration unless explicitly surfaced by
  an owning module. `ModuleSettingsRepository` and `SettingsService`.

### `core.feature_flags`

- **Purpose/ownership:** global or module-owned feature switches, owned by
  `Core/Settings`.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `module_id uuid` (nullable), `code varchar(160)`, `enabled boolean
  DEFAULT false`, `rollout_percent smallint DEFAULT 100`, `config jsonb DEFAULT
  {}`, `description text` (nullable), `updated_by_account_id uuid` (nullable),
  `created_at`, `updated_at`.
- **FK/delete:** module `CASCADE` when present; updating account `SET NULL`.
- **Checks:** trimmed code non-blank; rollout 0..100.
- **Indexes/uniqueness:** unique code; index on module.
- **Sensitive/access:** non-secret internal configuration. `FeatureFlagRepository`
  and `FeatureFlagService::isEnabled`.

### `core.system_settings`

- **Purpose/ownership:** installation-wide non-secret configuration, owned by
  `Core/Settings`.
- **PK:** `id uuid DEFAULT uuidv7()`.
- **Fields:** `setting_key varchar(160)`, `value jsonb`, `is_public boolean
  DEFAULT false`, `description text` (nullable), `updated_by_account_id uuid`
  (nullable), `created_at`, `updated_at`.
- **FK/delete:** updating account `SET NULL`.
- **Checks:** trimmed key non-blank.
- **Indexes/uniqueness:** unique setting key.
- **Sensitive/access:** secrets are forbidden. `SettingsService` may expose only
  rows explicitly marked `is_public`; writes remain internal through
  `SystemSettingsRepository`.

## Audit

### `core.audit_events`

- **Purpose/ownership:** append-only business/security history, owned by
  `Core/Audit`; not technical telemetry.
- **PK:** `id bigint GENERATED BY DEFAULT AS IDENTITY`.
- **Fields:** `event_id uuid DEFAULT uuidv7()`, `occurred_at`, `actor_type
  audit_actor_type`, `actor_account_id uuid` (nullable), `action varchar(180)`,
  `resource_type varchar(180)`, `resource_id varchar(160)`, `request_id uuid`,
  `correlation_id uuid`, `ip_address inet`, `user_agent text`, `error_code
  varchar(120)`, `before_data jsonb`, `after_data jsonb` (all nullable),
  `success boolean DEFAULT true`, `metadata jsonb DEFAULT {}`.
- **FK/delete:** actor account `SET NULL`, preserving history.
- **Checks:** trimmed action non-blank.
- **Indexes/uniqueness:** unique event UUID; indexes on occurred time, actor,
  `(resource_type, resource_id)`, correlation, and action.
- **Sensitive/access:** IP/user agent and snapshots may be sensitive; writer must
  remove credentials/tokens. Append-only `AuditWriter`; optional filtered read
  API only, never public update/delete.

## Messaging/Outbox

### `core.outbox_events`

- **Purpose/ownership:** transactional outbox, owned by `Core/Messaging`.
- **PK:** `id bigint GENERATED BY DEFAULT AS IDENTITY`.
- **Fields:** `event_id uuid DEFAULT uuidv7()`, `producer_module varchar(100)`,
  `event_type varchar(220)`, `aggregate_type varchar(160)`, `aggregate_id
  varchar(160)` (last two nullable), `payload jsonb`, `headers jsonb DEFAULT {}`,
  `correlation_id uuid`, `causation_id uuid` (nullable), `status outbox_status
  DEFAULT 'pending'`, `attempts integer DEFAULT 0`, `occurred_at`, `available_at`,
  `published_at timestamptz`, `locked_by varchar(160)`, `locked_until
  timestamptz`, `last_error text` (last four nullable).
- **Checks:** trimmed producer/event type non-blank; attempts non-negative.
- **Indexes/uniqueness:** unique event UUID; indexes on `(status, available_at)`,
  `(aggregate_type, aggregate_id)`, and correlation.
- **Sensitive/access:** payload/headers/errors are internal and must be scrubbed
  of credentials. Infrastructure-only `OutboxRepository`/`OutboxPublisher` for
  enqueue, pending claims, and status transitions; no public HTTP API.

### `core.inbox_events`

- **Purpose/ownership:** consumer idempotency ledger, owned by `Core/Messaging`.
- **PK:** `id bigint GENERATED BY DEFAULT AS IDENTITY`.
- **Fields:** `event_id uuid`, `consumer varchar(180)`, `status inbox_status
  DEFAULT 'received'`, `attempts integer DEFAULT 0`, `received_at`,
  `processed_at timestamptz`, `locked_by varchar(160)`, `locked_until
  timestamptz`, `last_error text` (last four nullable).
- **Checks:** trimmed consumer non-blank; attempts non-negative.
- **Indexes/uniqueness:** unique `(event_id, consumer)`; indexes on
  `(consumer, status)` and `(status, received_at)`.
- **Sensitive/access:** processing errors are internal and may require
  sanitization. Infrastructure-only `InboxRepository`/`InboxConsumerGuard` for
  idempotency and processing registration; no public HTTP API.

## API and operation summary

| Area | Public HTTP surface | Internal operations |
|---|---|---|
| Person | list/create/read/update/archive; list/create contacts | normalization policy, verification state |
| Account | list/read/create with safe fields | lifecycle, stamps, approval/security metadata |
| Auth | none in this phase | identifier/credential/provider/MFA/session/challenge persistence |
| RBAC | read roles and permissions | role grants, account assignments, permission checks |
| Modules | none in this phase | registry lookup and enabled check |
| Settings | at most explicitly public system settings | settings writes and flag evaluation |
| Audit | optional filtered read | append-only writes |
| Messaging | none | transactional outbox and inbox idempotency |

All 23 tables are classified. Credential-bearing Auth tables and messaging
ledgers are deliberately internal; lack of a public endpoint is part of their
security design rather than missing coverage.
