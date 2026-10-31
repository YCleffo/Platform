# Справочник фактической базы данных

Сгенерировано `scripts/documentation/build-reference.py` из `schema.catalog.json`.
Только фактические таблицы, столбцы и SQL FK. Источник структуры — применённые миграции PostgreSQL.
CHECK, partial/expression indexes, trigger functions и deferred semantics полностью сохранены в JSON-каталогах;
DBML служит представлением для диаграммы, а не исполняемой заменой миграций.

## Инвентарь

| Схема | Таблицы | Столбцы | FK | CHECK | Индексы | Пользовательские triggers |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `core` | 50 | 561 | 86 | 195 | 161 | 18 |
| `platform_runtime` | 3 | 36 | 1 | 18 | 7 | 2 |
| `commercial` | 61 | 615 | 97 | 272 | 176 | 53 |

Технические `public.platform_migrations` и `public.doctrine_migration_versions` не являются бизнес-таблицами и не включены в инвентарь.
Cross-plane ID в подписанном документе не создаёт FK между двумя базами.

## Как читать поля

`NULL: да` означает допустимый SQL NULL. `DEFAULT: —` означает отсутствие SQL default.
`bytea` — хеш, подпись, public key или ciphertext в зависимости от поля.
`timestamp with time zone` хранит момент времени, `amount_minor` — целые минимальные единицы валюты.
SQL-комментарии приводятся из PostgreSQL; отсутствие комментария не заменяется догадкой.

## core.account_roles

Current role assignments of Accounts.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `account_id` | `uuid` | нет | — | — |
| `role_id` | `uuid` | нет | — | — |
| `assigned_at` | `timestamp with time zone` | нет | now() | — |
| `assigned_by_account_id` | `uuid` | да | — | Account that assigned the role; NULL for seed/system operation. |
| `expires_at` | `timestamp with time zone` | да | — | Optional assignment expiration; NULL means no expiration. |

**Первичный ключ:** PRIMARY KEY (account_id, role_id).

**Реальные внешние ключи:**

- `fk_account_roles_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.
- `fk_account_roles_assigned_by`: `FOREIGN KEY (assigned_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.
- `fk_account_roles_role`: `FOREIGN KEY (role_id) REFERENCES core.roles(id) ON DELETE CASCADE`.

CHECK: 0; индексов: 3; пользовательских triggers: 0.

## core.account_settings

Sparse personal setting overrides. Effective values are resolved by the versioned Core schema registry.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `account_id` | `uuid` | нет | — | — |
| `settings` | `jsonb` | нет | '{}'::jsonb | Whitelisted sparse overrides only; null reset values are never persisted. |
| `schema_version` | `integer` | нет | 1 | Version of the settings registry used to validate the stored document. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (account_id).

**Реальные внешние ключи:**

- `account_settings_account_id_fkey`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

CHECK: 4; индексов: 1; пользовательских triggers: 0.

## core.accounts

System identities. Human Accounts link to Person; service/system Accounts may not.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `person_id` | `uuid` | да | — | Account -> Person. Unique, therefore one Person has at most one Account in Core v1. |
| `account_type` | `core.account_type` | нет | 'human'::core.account_type | human, service or system. |
| `status` | `core.account_status` | нет | 'pending_verification'::core.account_status | Account lifecycle/status. |
| `label` | `character varying(160)` | да | — | — |
| `security_stamp` | `uuid` | нет | uuidv4() | Random security version. Rotate to invalidate old sessions. |
| `locked_until` | `timestamp with time zone` | да | — | — |
| `last_login_at` | `timestamp with time zone` | да | — | — |
| `created_by_account_id` | `uuid` | да | — | Self-reference: Account that created this Account. |
| `approved_by_account_id` | `uuid` | да | — | Self-reference: Account that approved this Account. |
| `approved_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `disabled_at` | `timestamp with time zone` | да | — | — |
| `archived_at` | `timestamp with time zone` | да | — | — |
| `locale` | `character varying(35)` | да | — | Optional saved canonical BCP 47 locale preference. |
| `timezone` | `character varying(64)` | да | — | Optional saved IANA timezone preference. |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_accounts_approved_by`: `FOREIGN KEY (approved_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.
- `fk_accounts_created_by`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.
- `fk_accounts_person`: `FOREIGN KEY (person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 5; пользовательских triggers: 1.

## core.audit_events

Append-only business/security audit: who changed what and when. NOT technical telemetry.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `bigint` | нет | IDENTITY BY DEFAULT | — |
| `event_id` | `uuid` | нет | uuidv7() | — |
| `occurred_at` | `timestamp with time zone` | нет | now() | — |
| `actor_type` | `core.audit_actor_type` | нет | — | — |
| `actor_account_id` | `uuid` | да | — | Account actor when applicable; SET NULL preserves audit after account deletion. |
| `action` | `character varying(180)` | нет | — | Stable namespaced action code. |
| `resource_type` | `character varying(180)` | да | — | Polymorphic resource type, e.g. core.account or education.lesson. |
| `resource_id` | `character varying(160)` | да | — | Resource ID serialized as text for polymorphic audit reference. |
| `request_id` | `uuid` | да | — | — |
| `correlation_id` | `uuid` | да | — | — |
| `ip_address` | `inet` | да | — | — |
| `user_agent` | `text` | да | — | — |
| `success` | `boolean` | нет | true | — |
| `error_code` | `character varying(120)` | да | — | — |
| `before_data` | `jsonb` | да | — | Relevant safe before-state/diff only; never secrets. |
| `after_data` | `jsonb` | да | — | Relevant safe after-state/diff only; never secrets. |
| `metadata` | `jsonb` | нет | '{}'::jsonb | Additional safe metadata. Never passwords, tokens, TOTP secrets, private keys. |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_audit_events_actor`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

CHECK: 1; индексов: 7; пользовательских triggers: 0.

## core.auth_challenges

Short-lived one-time auth/security challenges: verification, reset, invitation, magic login, etc.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | да | — | — |
| `challenge_type` | `character varying(80)` | нет | — | Extensible challenge scenario identifier. |
| `secret_hash` | `bytea` | нет | — | Hash of one-time secret/token. |
| `target_normalized` | `character varying(320)` | да | — | — |
| `payload` | `jsonb` | нет | '{}'::jsonb | Safe scenario-specific JSON payload; never place secrets here. |
| `attempts` | `smallint` | нет | 0 | — |
| `max_attempts` | `smallint` | нет | 5 | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `cancelled_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_auth_challenges_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

CHECK: 5; индексов: 4; пользовательских triggers: 0.

## core.auth_external_identities

Links local Accounts to stable external provider subjects.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `provider_id` | `uuid` | нет | — | — |
| `provider_subject` | `character varying(255)` | нет | — | Stable user identifier supplied by the provider, e.g. OIDC sub. |
| `email` | `character varying(320)` | да | — | Provider-reported email; never auto-merge Accounts solely by this value. |
| `email_verified` | `boolean` | нет | false | — |
| `display_name` | `character varying(255)` | да | — | — |
| `avatar_url` | `text` | да | — | — |
| `claims` | `jsonb` | нет | '{}'::jsonb | Additional non-secret provider claims. |
| `linked_at` | `timestamp with time zone` | нет | now() | — |
| `last_login_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | Creation timestamp for the account-to-provider-subject link; mirrors linked_at for pre-existing rows. |
| `updated_at` | `timestamp with time zone` | нет | now() | Last local mutation of the external identity link; raw provider payloads are never stored. |
| `last_used_at` | `timestamp with time zone` | да | — | Last successful use after provider-side verification; mirrors last_login_at for login-based providers. |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_external_identities_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.
- `fk_external_identities_provider`: `FOREIGN KEY (provider_id) REFERENCES core.auth_providers(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 7; пользовательских triggers: 0.

## core.auth_identifiers

Login/recovery identifiers of Account: username, email or phone.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | auth_identifiers -> accounts. |
| `identifier_type` | `core.auth_identifier_type` | нет | — | — |
| `value` | `character varying(320)` | нет | — | — |
| `normalized_value` | `character varying(320)` | нет | — | Canonical normalized form used in unique lookup. |
| `is_primary` | `boolean` | нет | false | — |
| `can_login` | `boolean` | нет | true | Whether this identifier can be used for login. |
| `can_recover` | `boolean` | нет | false | Whether recovery policy may use this identifier. |
| `verified_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_auth_identifiers_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

CHECK: 5; индексов: 5; пользовательских triggers: 0.

## core.auth_passkeys

WebAuthn/passkey credentials. One Account may have multiple passkeys.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `credential_id` | `bytea` | нет | — | Binary WebAuthn credential ID. |
| `public_key` | `bytea` | нет | — | Public key; private key never reaches the server. |
| `sign_count` | `bigint` | нет | 0 | — |
| `aaguid` | `uuid` | да | — | — |
| `transports` | `text[]` | нет | ARRAY[]::text[] | Known authenticator transports such as internal, usb, nfc, ble. |
| `label` | `character varying(160)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `last_used_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `backup_eligible` | `boolean` | нет | false | WebAuthn Level 3 flag: credential is eligible for cloud synchronization. |
| `backup_state` | `boolean` | нет | false | WebAuthn Level 3 flag: credential is currently backed up in cloud keychain. |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_auth_passkeys_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

CHECK: 4; индексов: 4; пользовательских triggers: 0.

## core.auth_password_credentials

Optional password credential of an Account. Zero or one row per Account.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `password_hash` | `text` | нет | — | Password hash only. Never store raw passwords. |
| `password_algorithm` | `character varying(32)` | нет | — | Informational algorithm identifier, e.g. argon2id. |
| `must_change_password` | `boolean` | нет | false | Force password change on next suitable authentication flow. |
| `password_changed_at` | `timestamp with time zone` | нет | now() | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_auth_password_credentials_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

CHECK: 2; индексов: 2; пользовательских triggers: 0.

## core.auth_providers

Registry of external authentication providers such as OIDC/OAuth/custom providers.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(50)` | нет | — | Stable provider code, e.g. google, telegram, microsoft. |
| `protocol` | `character varying(50)` | нет | — | Extensible protocol name; intentionally VARCHAR rather than ENUM. |
| `display_name` | `character varying(120)` | нет | — | — |
| `enabled` | `boolean` | нет | false | Provider availability switch. |
| `allow_login` | `boolean` | нет | true | — |
| `allow_registration` | `boolean` | нет | false | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 3; индексов: 3; пользовательских triggers: 0.

## core.auth_recovery_codes

Single-use MFA recovery codes stored only as cryptographic hashes.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `code_hash` | `bytea` | нет | — | Hash of a recovery code; raw code is never stored. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `used_at` | `timestamp with time zone` | да | — | NULL until consumed. |
| `revoked_at` | `timestamp with time zone` | да | — | Invalidation timestamp used by regeneration, MFA disable, and recovery reset. |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_auth_recovery_codes_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

CHECK: 1; индексов: 5; пользовательских triggers: 0.

## core.auth_sessions

Active/historical sessions. Opaque session tokens are stored only as hashes.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `session_token_hash` | `bytea` | нет | — | Hash of the opaque session token. |
| `refresh_token_hash` | `bytea` | да | — | Optional refresh-token hash for clients that need refresh semantics. |
| `security_stamp` | `uuid` | нет | — | Snapshot of accounts.security_stamp when the session was created. |
| `authentication_method` | `character varying(80)` | нет | — | Extensible authentication method: password, passkey, provider code, etc. |
| `mfa_verified_at` | `timestamp with time zone` | да | — | — |
| `client_type` | `character varying(40)` | нет | — | Extensible client type: web, mobile, agent, etc. |
| `device_name` | `character varying(160)` | да | — | — |
| `ip_address` | `inet` | да | — | — |
| `user_agent` | `text` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `last_seen_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `revoke_reason` | `character varying(255)` | да | — | — |
| `browser_name` | `character varying(120)` | да | — | — |
| `browser_version` | `character varying(60)` | да | — | — |
| `os_name` | `character varying(120)` | да | — | — |
| `os_version` | `character varying(60)` | да | — | — |
| `device_type` | `character varying(40)` | да | — | — |
| `device_model` | `character varying(160)` | да | — | — |
| `client_locale` | `character varying(35)` | да | — | — |
| `client_timezone` | `character varying(64)` | да | — | — |
| `client_instance_id` | `uuid` | да | — | Optional random frontend installation UUID; not an authentication factor. |
| `device_fingerprint_hash` | `bytea` | да | — | HMAC-SHA-256 risk signal; never an authentication factor. |
| `geo_country_code` | `character(2)` | да | — | Approximate ISO 3166-1 alpha-2 country inferred from IP. |
| `geo_region` | `character varying(160)` | да | — | — |
| `geo_city` | `character varying(160)` | да | — | Approximate city inferred from IP; never exact location. |
| `geo_timezone` | `character varying(64)` | да | — | — |
| `access_expires_at` | `timestamp with time zone` | нет | — | Expiry of the current short-lived opaque access token; expires_at is absolute session expiry. |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_auth_sessions_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

CHECK: 13; индексов: 7; пользовательских triggers: 0.

## core.auth_totp_factors

TOTP MFA factors. Secret is encrypted; encryption key lives outside the database.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `label` | `character varying(160)` | да | — | — |
| `secret_encrypted` | `bytea` | нет | — | Encrypted TOTP secret. |
| `algorithm` | `core.totp_algorithm` | нет | 'sha1'::core.totp_algorithm | — |
| `digits` | `smallint` | нет | 6 | — |
| `period_seconds` | `smallint` | нет | 30 | — |
| `verified_at` | `timestamp with time zone` | да | — | NULL until setup is confirmed by a valid TOTP code. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `last_used_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `last_used_counter` | `bigint` | да | — | Last accepted RFC 6238 time-step counter. A counter may be accepted only once. |
| `secret_key_version` | `smallint` | нет | 1 | Version of the external encryption key used for secret_encrypted. |
| `setup_expires_at` | `timestamp with time zone` | да | — | Expiry of a pending, unverified TOTP setup. |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_auth_totp_factors_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

CHECK: 7; индексов: 5; пользовательских triggers: 0.

## core.data_subject_request_deadlines

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `request_id` | `uuid` | нет | — | — |
| `deadline_code` | `character varying(120)` | нет | — | — |
| `triggered_at` | `timestamp with time zone` | нет | — | — |
| `calendar_type` | `character varying(20)` | нет | — | — |
| `due_at` | `timestamp with time zone` | нет | — | — |
| `extension_allowed` | `boolean` | нет | false | — |
| `extended_at` | `timestamp with time zone` | да | — | — |
| `extended_due_at` | `timestamp with time zone` | да | — | — |
| `extension_reason_code` | `character varying(120)` | да | — | — |
| `satisfied_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `data_subject_request_deadlines_request_id_fkey`: `FOREIGN KEY (request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.

CHECK: 6; индексов: 3; пользовательских triggers: 1.

## core.data_subject_request_events

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `request_id` | `uuid` | нет | — | — |
| `event_code` | `character varying(120)` | нет | — | — |
| `from_status` | `character varying(32)` | да | — | — |
| `to_status` | `character varying(32)` | да | — | — |
| `actor_account_id` | `uuid` | нет | — | — |
| `reason_code` | `character varying(120)` | да | — | — |
| `occurred_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `data_subject_request_events_actor_account_id_fkey`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `data_subject_request_events_request_id_fkey`: `FOREIGN KEY (request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 2; пользовательских triggers: 1.

## core.data_subject_request_executions

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `request_id` | `uuid` | нет | — | — |
| `execution_code` | `character varying(120)` | нет | — | — |
| `status` | `character varying(20)` | нет | — | — |
| `actor_account_id` | `uuid` | да | — | — |
| `contributor_code` | `character varying(120)` | да | — | — |
| `resource_type` | `character varying(120)` | да | — | — |
| `resource_id` | `uuid` | да | — | — |
| `schema_version` | `character varying(40)` | да | — | — |
| `result_sha256` | `character(64)` | да | — | — |
| `result_size_bytes` | `bigint` | да | — | — |
| `changed_paths` | `text[]` | да | — | — |
| `error_code` | `character varying(120)` | да | — | — |
| `started_at` | `timestamp with time zone` | нет | — | — |
| `completed_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `stopped_purpose_codes` | `text[]` | да | — | — |
| `retained_purpose_codes` | `text[]` | да | — | — |
| `reason_codes` | `text[]` | да | — | — |
| `erasure_case_id` | `uuid` | да | — | — |
| `erasure_completed_items` | `integer` | да | — | — |
| `erasure_blocked_items` | `integer` | да | — | — |
| `erasure_failed_items` | `integer` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `data_subject_request_executions_actor_account_id_fkey`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `data_subject_request_executions_erasure_case_id_fkey`: `FOREIGN KEY (erasure_case_id) REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT`.
- `data_subject_request_executions_request_id_fkey`: `FOREIGN KEY (request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.

CHECK: 10; индексов: 3; пользовательских triggers: 1.

## core.data_subject_requests

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_person_id` | `uuid` | нет | — | — |
| `submitted_by_person_id` | `uuid` | да | — | — |
| `submitted_by_account_id` | `uuid` | нет | — | — |
| `legal_representation_id` | `uuid` | да | — | — |
| `request_type` | `character varying(40)` | нет | — | — |
| `status` | `character varying(32)` | нет | 'submitted'::character varying | — |
| `submitted_at` | `timestamp with time zone` | нет | — | — |
| `identity_verified_at` | `timestamp with time zone` | да | — | — |
| `completed_at` | `timestamp with time zone` | да | — | — |
| `resolution_code` | `character varying(120)` | да | — | — |
| `submission_request_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `intake_channel` | `character varying(32)` | да | — | — |
| `identity_verification_code` | `character varying(120)` | да | — | — |
| `identity_evidence_reference` | `character varying(500)` | да | — | — |
| `representative_verification_reference` | `character varying(500)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `data_subject_requests_legal_representation_id_fkey`: `FOREIGN KEY (legal_representation_id) REFERENCES core.legal_representations(id) ON DELETE RESTRICT`.
- `data_subject_requests_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `data_subject_requests_submitted_by_account_id_fkey`: `FOREIGN KEY (submitted_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `data_subject_requests_submitted_by_person_id_fkey`: `FOREIGN KEY (submitted_by_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 6; индексов: 5; пользовательских triggers: 1.

## core.feature_flags

Global or module-owned feature flags with percentage rollout.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `module_id` | `uuid` | да | — | Owner module; NULL means installation-wide flag. |
| `code` | `character varying(160)` | нет | — | — |
| `enabled` | `boolean` | нет | false | — |
| `rollout_percent` | `smallint` | нет | 100 | Integer rollout percentage from 0 to 100. |
| `config` | `jsonb` | нет | '{}'::jsonb | — |
| `description` | `text` | да | — | — |
| `updated_by_account_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_feature_flags_module`: `FOREIGN KEY (module_id) REFERENCES core.modules(id) ON DELETE CASCADE`.
- `fk_feature_flags_updated_by`: `FOREIGN KEY (updated_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

CHECK: 4; индексов: 3; пользовательских triggers: 0.

## core.inbox_events

Consumer idempotency ledger. event_id + consumer is unique.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `bigint` | нет | IDENTITY BY DEFAULT | — |
| `event_id` | `uuid` | нет | — | — |
| `consumer` | `character varying(180)` | нет | — | Stable unique code of the consuming handler. |
| `status` | `core.inbox_status` | нет | 'received'::core.inbox_status | — |
| `attempts` | `integer` | нет | 0 | — |
| `received_at` | `timestamp with time zone` | нет | now() | — |
| `processed_at` | `timestamp with time zone` | да | — | Successful processing time. |
| `locked_by` | `character varying(160)` | да | — | — |
| `locked_until` | `timestamp with time zone` | да | — | Lease expiration for a consumer worker lock. |
| `last_error` | `text` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 2; индексов: 4; пользовательских triggers: 0.

## core.legal_representation_scopes

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `representation_id` | `uuid` | нет | — | — |
| `scope_code` | `character varying(120)` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (representation_id, scope_code).

**Реальные внешние ключи:**

- `legal_representation_scopes_representation_id_fkey`: `FOREIGN KEY (representation_id) REFERENCES core.legal_representations(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 1; пользовательских triggers: 1.

## core.legal_representations

Verified generic authority to act for another Person; no scanned documents stored here.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_person_id` | `uuid` | нет | — | — |
| `representative_person_id` | `uuid` | нет | — | — |
| `authority_type` | `character varying(120)` | нет | — | — |
| `status` | `character varying(32)` | нет | 'declared'::character varying | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `verified_at` | `timestamp with time zone` | да | — | — |
| `verified_by_account_id` | `uuid` | да | — | — |
| `evidence_type` | `character varying(120)` | да | — | — |
| `evidence_reference` | `character varying(512)` | да | — | — |
| `evidence_sha256` | `bytea` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `revoked_by_account_id` | `uuid` | да | — | — |
| `revocation_reason_code` | `character varying(120)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `legal_representations_representative_person_id_fkey`: `FOREIGN KEY (representative_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `legal_representations_revoked_by_account_id_fkey`: `FOREIGN KEY (revoked_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `legal_representations_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `legal_representations_verified_by_account_id_fkey`: `FOREIGN KEY (verified_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.

CHECK: 10; индексов: 3; пользовательских triggers: 2.

## core.module_settings

Non-secret settings of a specific module.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `module_id` | `uuid` | нет | — | — |
| `setting_key` | `character varying(160)` | нет | — | Setting key unique inside a module. |
| `value` | `jsonb` | нет | — | JSONB setting value. Do not put secrets here. |
| `description` | `text` | да | — | — |
| `updated_by_account_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_module_settings_module`: `FOREIGN KEY (module_id) REFERENCES core.modules(id) ON DELETE CASCADE`.
- `fk_module_settings_updated_by`: `FOREIGN KEY (updated_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

CHECK: 3; индексов: 2; пользовательских triggers: 0.

## core.modules

Registry of installed/known platform modules. Core knows metadata only, never module internals.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(100)` | нет | — | Stable module code, e.g. education, games, chat. |
| `name` | `character varying(160)` | нет | — | — |
| `description` | `text` | да | — | — |
| `version` | `character varying(40)` | нет | — | — |
| `settings_schema_version` | `integer` | нет | 1 | Version of module settings schema. |
| `enabled` | `boolean` | нет | false | — |
| `installed_at` | `timestamp with time zone` | нет | now() | — |
| `enabled_at` | `timestamp with time zone` | да | — | — |
| `disabled_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 5; индексов: 3; пользовательских triggers: 0.

## core.organization_memberships

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `status` | `character varying(20)` | нет | 'active'::character varying | — |
| `joined_at` | `timestamp with time zone` | нет | now() | — |
| `left_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `organization_memberships_organization_id_fkey`: `FOREIGN KEY (organization_id) REFERENCES core.organizations(id) ON DELETE RESTRICT`.
- `organization_memberships_person_id_fkey`: `FOREIGN KEY (person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 3; индексов: 3; пользовательских triggers: 0.

## core.organizations

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(120)` | нет | — | — |
| `name` | `character varying(255)` | нет | — | — |
| `status` | `character varying(20)` | нет | 'active'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `archived_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 4; индексов: 2; пользовательских triggers: 0.

## core.outbox_events

Transactional Outbox. Business state and outbox event must be committed in one PostgreSQL transaction.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `bigint` | нет | IDENTITY BY DEFAULT | — |
| `event_id` | `uuid` | нет | uuidv7() | — |
| `producer_module` | `character varying(100)` | нет | — | Module that produced the event. |
| `event_type` | `character varying(220)` | нет | — | Stable namespaced event type. |
| `aggregate_type` | `character varying(160)` | да | — | — |
| `aggregate_id` | `character varying(160)` | да | — | — |
| `payload` | `jsonb` | нет | — | — |
| `headers` | `jsonb` | нет | '{}'::jsonb | — |
| `correlation_id` | `uuid` | да | — | — |
| `causation_id` | `uuid` | да | — | — |
| `status` | `core.outbox_status` | нет | 'pending'::core.outbox_status | — |
| `attempts` | `integer` | нет | 0 | — |
| `occurred_at` | `timestamp with time zone` | нет | now() | — |
| `available_at` | `timestamp with time zone` | нет | now() | Earliest time the publisher worker may attempt delivery. |
| `published_at` | `timestamp with time zone` | да | — | — |
| `locked_by` | `character varying(160)` | да | — | — |
| `locked_until` | `timestamp with time zone` | да | — | Lease expiration for a worker lock. |
| `last_error` | `text` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 3; индексов: 5; пользовательских triggers: 0.

## core.permissions

Global catalog of RBAC permission codes.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `module_id` | `uuid` | да | — | Permission -> Module. NULL means permission belongs to Core itself. |
| `code` | `character varying(180)` | нет | — | Globally unique namespaced permission code. |
| `name` | `character varying(180)` | нет | — | — |
| `description` | `text` | да | — | — |
| `is_system` | `boolean` | нет | false | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_permissions_module`: `FOREIGN KEY (module_id) REFERENCES core.modules(id) ON DELETE SET NULL`.

CHECK: 3; индексов: 3; пользовательских triggers: 0.

## core.person_contacts

Contacts of a physical Person. These are not Account login identifiers.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `person_id` | `uuid` | нет | — | Child -> parent: person_contacts.person_id -> persons.id. |
| `contact_type` | `core.contact_type` | нет | — | Contact type: email or phone. |
| `value` | `character varying(320)` | нет | — | Original/display value. |
| `normalized_value` | `character varying(320)` | нет | — | Normalized value used for comparison/search. |
| `label` | `character varying(80)` | да | — | — |
| `is_primary` | `boolean` | нет | false | Marks the primary contact of a given type for the Person. |
| `verified_at` | `timestamp with time zone` | да | — | Verification time; NULL means unverified. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_person_contacts_person`: `FOREIGN KEY (person_id) REFERENCES core.persons(id) ON DELETE CASCADE`.

CHECK: 5; индексов: 5; пользовательских triggers: 0.

## core.persons

Physical individuals separated from accounts. Core v1 base table (1 of 23). Data hygiene constraints applied.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | UUIDv7 primary key of the physical person. |
| `status` | `core.person_status` | нет | 'active'::core.person_status | Person lifecycle status: active, inactive, archived. |
| `given_name` | `character varying(120)` | нет | — | First/given name. |
| `family_name` | `character varying(120)` | нет | — | Family/last name. |
| `middle_name` | `character varying(120)` | да | — | Optional middle/patronymic name. |
| `display_name` | `character varying(255)` | да | — | Optional preformatted display name for UI. |
| `birth_date` | `date` | да | — | Birth date without time. |
| `created_at` | `timestamp with time zone` | нет | now() | Creation timestamp. |
| `updated_at` | `timestamp with time zone` | нет | now() | Last update timestamp; application-managed for now. |
| `archived_at` | `timestamp with time zone` | да | — | Archive timestamp; NULL means not archived. |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 5; индексов: 4; пользовательских triggers: 0.

## core.privacy_cleanup_runs

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `category_code` | `character varying(120)` | нет | — | — |
| `deleted_count` | `integer` | нет | — | — |
| `policy_days` | `integer` | да | — | — |
| `executed_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 2; индексов: 1; пользовательских triggers: 0.

## core.privacy_collection_notices

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_person_id` | `uuid` | нет | — | — |
| `purpose_code` | `character varying(120)` | нет | — | — |
| `category_codes` | `text[]` | нет | — | — |
| `source_code` | `character varying(120)` | нет | — | — |
| `collected_at` | `timestamp with time zone` | нет | — | — |
| `notice_required` | `boolean` | нет | — | — |
| `exemption_code` | `character varying(120)` | да | — | — |
| `notice_issued_at` | `timestamp with time zone` | да | — | — |
| `notice_evidence_reference` | `character varying(500)` | да | — | — |
| `created_by_account_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_collection_notices_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_collection_notices_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 2; пользовательских triggers: 0.

## core.privacy_consents

Subject and actor consent evidence; migration 018 rows remain flagged as unclassified legacy evidence.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `granted_by_account_id` | `uuid` | нет | — | — |
| `consent_code` | `character varying(120)` | нет | — | — |
| `document_version` | `character varying(80)` | нет | — | — |
| `document_sha256` | `bytea` | нет | — | — |
| `locale` | `character varying(35)` | нет | — | — |
| `source` | `character varying(80)` | нет | — | — |
| `granted_at` | `timestamp with time zone` | нет | now() | — |
| `withdrawn_at` | `timestamp with time zone` | да | — | — |
| `request_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `subject_person_id` | `uuid` | да | — | — |
| `granted_by_person_id` | `uuid` | да | — | — |
| `legal_representation_id` | `uuid` | да | — | — |
| `purpose_code` | `character varying(120)` | да | — | — |
| `retention_until` | `timestamp with time zone` | да | — | — |
| `legacy_record` | `boolean` | нет | false | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `account_consents_account_id_fkey`: `FOREIGN KEY (granted_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_consents_granted_by_person_id_fkey`: `FOREIGN KEY (granted_by_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `privacy_consents_legal_representation_id_fkey`: `FOREIGN KEY (legal_representation_id) REFERENCES core.legal_representations(id) ON DELETE RESTRICT`.
- `privacy_consents_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 9; индексов: 5; пользовательских triggers: 2.

## core.privacy_destruction_evidence

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `case_id` | `uuid` | нет | — | — |
| `item_id` | `uuid` | нет | — | — |
| `resource_type` | `character varying(120)` | нет | — | — |
| `resource_reference_hash` | `character(64)` | нет | — | — |
| `policy_code` | `character varying(120)` | нет | — | — |
| `evidence_policy_code` | `character varying(120)` | нет | — | — |
| `evidence_retain_until` | `timestamp with time zone` | нет | — | — |
| `action` | `character varying(24)` | нет | — | — |
| `result` | `character varying(24)` | нет | — | — |
| `executed_at` | `timestamp with time zone` | нет | — | — |
| `executor_account_id` | `uuid` | да | — | — |
| `journal_event_id` | `uuid` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_destruction_evidence_case_id_fkey`: `FOREIGN KEY (case_id) REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT`.
- `privacy_destruction_evidence_executor_account_id_fkey`: `FOREIGN KEY (executor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_destruction_evidence_item_id_fkey`: `FOREIGN KEY (item_id) REFERENCES core.privacy_erasure_items(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 3; пользовательских triggers: 1.

## core.privacy_distribution_consents

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_person_id` | `uuid` | нет | — | — |
| `granted_by_person_id` | `uuid` | нет | — | — |
| `granted_by_account_id` | `uuid` | нет | — | — |
| `legal_representation_id` | `uuid` | да | — | — |
| `purpose_code` | `character varying(120)` | нет | — | — |
| `category_codes` | `text[]` | нет | — | — |
| `field_codes` | `text[]` | нет | — | — |
| `condition_codes` | `text[]` | нет | '{}'::text[] | — |
| `restriction_codes` | `text[]` | нет | '{}'::text[] | — |
| `document_version` | `character varying(80)` | нет | — | — |
| `document_sha256` | `character(64)` | нет | — | — |
| `granted_at` | `timestamp with time zone` | нет | — | — |
| `withdrawn_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_distribution_consents_granted_by_account_id_fkey`: `FOREIGN KEY (granted_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_distribution_consents_granted_by_person_id_fkey`: `FOREIGN KEY (granted_by_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `privacy_distribution_consents_legal_representation_id_fkey`: `FOREIGN KEY (legal_representation_id) REFERENCES core.legal_representations(id) ON DELETE RESTRICT`.
- `privacy_distribution_consents_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 7; индексов: 2; пользовательских triggers: 1.

## core.privacy_erasure_cases

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_person_id` | `uuid` | нет | — | — |
| `source_request_id` | `uuid` | да | — | — |
| `reason_code` | `character varying(120)` | нет | — | — |
| `status` | `character varying(32)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | — | — |
| `started_at` | `timestamp with time zone` | да | — | — |
| `completed_at` | `timestamp with time zone` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | — | — |
| `created_by_account_id` | `uuid` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_erasure_cases_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_erasure_cases_source_request_id_fkey`: `FOREIGN KEY (source_request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.
- `privacy_erasure_cases_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 4; пользовательских triggers: 0.

## core.privacy_erasure_item_purposes

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `item_id` | `uuid` | нет | — | — |
| `purpose_code` | `character varying(120)` | нет | — | — |
| `policy_code` | `character varying(120)` | нет | — | — |
| `trigger_code` | `character varying(120)` | нет | — | — |
| `triggered_at` | `timestamp with time zone` | да | — | — |
| `contributor_code` | `character varying(120)` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (item_id, purpose_code, contributor_code).

**Реальные внешние ключи:**

- `privacy_erasure_item_purposes_item_id_fkey`: `FOREIGN KEY (item_id) REFERENCES core.privacy_erasure_items(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 1; пользовательских triggers: 0.

## core.privacy_erasure_items

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `case_id` | `uuid` | нет | — | — |
| `resource_type` | `character varying(120)` | нет | — | — |
| `resource_id` | `uuid` | нет | — | — |
| `policy_code` | `character varying(120)` | нет | — | — |
| `planned_action` | `character varying(24)` | да | — | — |
| `status` | `character varying(24)` | нет | — | — |
| `attempts` | `integer` | нет | 0 | — |
| `started_at` | `timestamp with time zone` | да | — | — |
| `completed_at` | `timestamp with time zone` | да | — | — |
| `failure_code` | `character varying(120)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | — | — |
| `updated_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_erasure_items_case_id_fkey`: `FOREIGN KEY (case_id) REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT`.

CHECK: 8; индексов: 3; пользовательских triggers: 0.

## core.privacy_incident_categories

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `incident_id` | `uuid` | нет | — | — |
| `category_code` | `character varying(120)` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (incident_id, category_code).

**Реальные внешние ключи:**

- `privacy_incident_categories_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 1; пользовательских triggers: 0.

## core.privacy_incident_events

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `incident_id` | `uuid` | нет | — | — |
| `event_code` | `character varying(120)` | нет | — | — |
| `from_status` | `character varying(24)` | да | — | — |
| `to_status` | `character varying(24)` | да | — | — |
| `reason_code` | `character varying(120)` | да | — | — |
| `actor_account_id` | `uuid` | нет | — | — |
| `occurred_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_incident_events_actor_account_id_fkey`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_incident_events_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 2; пользовательских triggers: 1.

## core.privacy_incident_subject_scope

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `incident_id` | `uuid` | нет | — | — |
| `subject_person_id` | `uuid` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (incident_id, subject_person_id).

**Реальные внешние ключи:**

- `privacy_incident_subject_scope_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT`.
- `privacy_incident_subject_scope_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 0; индексов: 2; пользовательских triggers: 0.

## core.privacy_incidents

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `incident_type` | `character varying(120)` | нет | — | — |
| `status` | `character varying(24)` | нет | — | — |
| `containment_status` | `character varying(24)` | нет | — | — |
| `detected_at` | `timestamp with time zone` | нет | — | — |
| `initial_notification_due_at` | `timestamp with time zone` | нет | — | — |
| `initial_notification_at` | `timestamp with time zone` | да | — | — |
| `initial_notification_reference` | `character varying(120)` | да | — | — |
| `investigation_notification_due_at` | `timestamp with time zone` | нет | — | — |
| `investigation_notification_at` | `timestamp with time zone` | да | — | — |
| `investigation_notification_reference` | `character varying(120)` | да | — | — |
| `estimated_subjects` | `integer` | да | — | — |
| `safe_reason_code` | `character varying(120)` | нет | — | — |
| `created_by_account_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | — | — |
| `updated_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_incidents_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.

CHECK: 7; индексов: 2; пользовательских triggers: 0.

## core.privacy_legal_destruction_packages

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `evidence_id` | `uuid` | нет | — | — |
| `case_id` | `uuid` | нет | — | — |
| `subject_person_id` | `uuid` | нет | — | — |
| `subject_name` | `character varying(400)` | нет | — | — |
| `operator_name` | `character varying(300)` | нет | — | — |
| `operator_address` | `character varying(500)` | нет | — | — |
| `processor_name` | `character varying(300)` | да | — | — |
| `processor_address` | `character varying(500)` | да | — | — |
| `category_codes` | `text[]` | нет | — | — |
| `information_system_name` | `character varying(300)` | нет | — | — |
| `information_system_code` | `character varying(120)` | нет | — | — |
| `destruction_method` | `character varying(200)` | нет | — | — |
| `destruction_reason_code` | `character varying(120)` | нет | — | — |
| `destroyed_at` | `timestamp with time zone` | нет | — | — |
| `executor_account_id` | `uuid` | нет | — | — |
| `document_reference` | `character varying(500)` | нет | — | — |
| `journal_event_id` | `uuid` | нет | — | — |
| `retain_until` | `timestamp with time zone` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_legal_destruction_packages_case_id_fkey`: `FOREIGN KEY (case_id) REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT`.
- `privacy_legal_destruction_packages_evidence_id_fkey`: `FOREIGN KEY (evidence_id) REFERENCES core.privacy_destruction_evidence(id) ON DELETE CASCADE`.
- `privacy_legal_destruction_packages_executor_account_id_fkey`: `FOREIGN KEY (executor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_legal_destruction_packages_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 3; пользовательских triggers: 1.

## core.privacy_legal_destruction_signatures

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `package_id` | `uuid` | нет | — | — |
| `signed_document_reference` | `character varying(500)` | нет | — | — |
| `signature_reference` | `character varying(500)` | нет | — | — |
| `attested_by_account_id` | `uuid` | нет | — | — |
| `attested_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (package_id).

**Реальные внешние ключи:**

- `privacy_legal_destruction_signature_attested_by_account_id_fkey`: `FOREIGN KEY (attested_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_legal_destruction_signatures_package_id_fkey`: `FOREIGN KEY (package_id) REFERENCES core.privacy_legal_destruction_packages(id) ON DELETE CASCADE`.

CHECK: 1; индексов: 1; пользовательских triggers: 1.

## core.privacy_legal_holds

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_person_id` | `uuid` | да | — | — |
| `scope_type` | `character varying(20)` | нет | — | — |
| `purpose_code` | `character varying(120)` | да | — | — |
| `resource_type` | `character varying(120)` | да | — | — |
| `resource_id` | `uuid` | да | — | — |
| `reason_code` | `character varying(120)` | нет | — | — |
| `source_type` | `character varying(120)` | да | — | — |
| `source_reference` | `character varying(160)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | — | — |
| `created_by_account_id` | `uuid` | нет | — | — |
| `expires_at` | `timestamp with time zone` | да | — | — |
| `released_at` | `timestamp with time zone` | да | — | — |
| `released_by_account_id` | `uuid` | да | — | — |
| `release_reason_code` | `character varying(120)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_legal_holds_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_legal_holds_released_by_account_id_fkey`: `FOREIGN KEY (released_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_legal_holds_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 5; индексов: 3; пользовательских triggers: 0.

## core.privacy_processing_blocks

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_person_id` | `uuid` | нет | — | — |
| `scope_type` | `character varying(20)` | нет | — | — |
| `purpose_code` | `character varying(120)` | да | — | — |
| `resource_type` | `character varying(120)` | да | — | — |
| `resource_id` | `uuid` | да | — | — |
| `reason_code` | `character varying(120)` | нет | — | — |
| `source_request_id` | `uuid` | да | — | — |
| `started_at` | `timestamp with time zone` | нет | — | — |
| `expires_at` | `timestamp with time zone` | да | — | — |
| `released_at` | `timestamp with time zone` | да | — | — |
| `released_by_account_id` | `uuid` | да | — | — |
| `release_reason_code` | `character varying(120)` | да | — | — |
| `created_by_account_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_processing_blocks_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_processing_blocks_released_by_account_id_fkey`: `FOREIGN KEY (released_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_processing_blocks_source_request_id_fkey`: `FOREIGN KEY (source_request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.
- `privacy_processing_blocks_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 3; пользовательских triggers: 1.

## core.privacy_retention_decisions

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_person_id` | `uuid` | нет | — | — |
| `resource_type` | `character varying(120)` | нет | — | — |
| `resource_id` | `uuid` | нет | — | — |
| `purpose_code` | `character varying(120)` | нет | — | — |
| `policy_code` | `character varying(120)` | нет | — | — |
| `decision_code` | `character varying(120)` | нет | — | — |
| `trigger_code` | `character varying(120)` | нет | — | — |
| `triggered_at` | `timestamp with time zone` | да | — | — |
| `eligible_at` | `timestamp with time zone` | да | — | — |
| `legal_hold_id` | `uuid` | да | — | — |
| `source_request_id` | `uuid` | да | — | — |
| `evaluated_at` | `timestamp with time zone` | нет | — | — |
| `evaluator_version` | `character varying(40)` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_retention_decisions_legal_hold_id_fkey`: `FOREIGN KEY (legal_hold_id) REFERENCES core.privacy_legal_holds(id) ON DELETE RESTRICT`.
- `privacy_retention_decisions_source_request_id_fkey`: `FOREIGN KEY (source_request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.
- `privacy_retention_decisions_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 3; пользовательских triggers: 1.

## core.role_permissions

Many-to-many relation Role <-> Permission.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `role_id` | `uuid` | нет | — | — |
| `permission_id` | `uuid` | нет | — | — |
| `granted_at` | `timestamp with time zone` | нет | now() | — |
| `granted_by_account_id` | `uuid` | да | — | Account that granted the permission; NULL for seed/system operation. |

**Первичный ключ:** PRIMARY KEY (role_id, permission_id).

**Реальные внешние ключи:**

- `fk_role_permissions_granted_by`: `FOREIGN KEY (granted_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.
- `fk_role_permissions_permission`: `FOREIGN KEY (permission_id) REFERENCES core.permissions(id) ON DELETE CASCADE`.
- `fk_role_permissions_role`: `FOREIGN KEY (role_id) REFERENCES core.roles(id) ON DELETE CASCADE`.

CHECK: 0; индексов: 2; пользовательских triggers: 0.

## core.roles

RBAC roles. A role is a bundle of permissions, not a separate Admin entity.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(120)` | нет | — | Stable namespaced role code, e.g. core.superadmin. |
| `name` | `character varying(160)` | нет | — | — |
| `description` | `text` | да | — | — |
| `is_system` | `boolean` | нет | false | Role is system-provided. |
| `is_protected` | `boolean` | нет | false | Ordinary management flows must not remove/alter protected roles freely. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 3; индексов: 3; пользовательских triggers: 0.

## core.service_credentials

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `credential_id` | `uuid` | нет | uuidv4() | — |
| `secret_hash` | `bytea` | нет | — | — |
| `status` | `character varying(16)` | нет | 'active'::character varying | — |
| `scopes` | `text[]` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | да | — | — |
| `last_used_at` | `timestamp with time zone` | да | — | — |
| `rotated_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `created_by_account_id` | `uuid` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `service_credentials_account_id_fkey`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `service_credentials_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

CHECK: 5; индексов: 4; пользовательских triggers: 1.

## core.system_settings

Installation-wide non-secret settings: generic platform/branding metadata and similar configuration.

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `setting_key` | `character varying(160)` | нет | — | Globally unique generic setting key. |
| `value` | `jsonb` | нет | — | — |
| `is_public` | `boolean` | нет | false | Whether this non-secret setting may be exposed to a public/client configuration endpoint. |
| `description` | `text` | да | — | — |
| `updated_by_account_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `fk_system_settings_updated_by`: `FOREIGN KEY (updated_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

CHECK: 3; индексов: 2; пользовательских triggers: 0.

## platform_runtime.background_job_status

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `job_code` | `character varying(120)` | нет | — | — |
| `last_started_at` | `timestamp with time zone` | да | — | — |
| `last_succeeded_at` | `timestamp with time zone` | да | — | — |
| `last_failed_at` | `timestamp with time zone` | да | — | — |
| `last_duration_ms` | `integer` | да | — | — |
| `last_processed_count` | `integer` | да | — | — |
| `consecutive_failures` | `integer` | нет | 0 | — |
| `last_error_code` | `character varying(120)` | да | — | — |
| `last_result` | `character varying(16)` | нет | 'waiting'::character varying | — |
| `heartbeat_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (job_code).

SQL FK из этой таблицы отсутствуют.

CHECK: 6; индексов: 1; пользовательских triggers: 0.

## platform_runtime.entitlement_documents

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `installation_id` | `uuid` | нет | — | — |
| `document_version` | `integer` | нет | — | Signed document format version; sequence orders snapshots for one installation. |
| `sequence` | `bigint` | нет | — | — |
| `issued_at` | `timestamp with time zone` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `payload` | `jsonb` | нет | — | — |
| `signature` | `bytea` | нет | — | Ed25519 signature bytes. The private signing key is never stored in this database. |
| `signing_key_id` | `character varying(160)` | нет | — | — |
| `status` | `platform_runtime.entitlement_status` | нет | 'pending'::platform_runtime.entitlement_status | — |
| `applied_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `entitlement_documents_installation_id_fkey`: `FOREIGN KEY (installation_id) REFERENCES platform_runtime.installations(id) ON DELETE RESTRICT`.

CHECK: 7; индексов: 4; пользовательских triggers: 1.

## platform_runtime.installations

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `singleton_key` | `smallint` | нет | 1 | — |
| `deployment_mode` | `platform_runtime.deployment_mode` | нет | — | — |
| `environment` | `platform_runtime.environment` | нет | — | — |
| `application_version` | `character varying(120)` | нет | — | — |
| `release_id` | `character varying(160)` | нет | — | — |
| `release_channel` | `platform_runtime.release_channel` | нет | — | — |
| `status` | `platform_runtime.installation_status` | нет | 'provisioned'::platform_runtime.installation_status | — |
| `current_entitlement_sequence` | `bigint` | нет | 0 | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `activated_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 5; индексов: 2; пользовательских triggers: 1.

## commercial.audit_events

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `bigint` | нет | IDENTITY ALWAYS | — |
| `event_id` | `uuid` | нет | uuidv7() | — |
| `occurred_at` | `timestamp with time zone` | нет | now() | — |
| `actor_type` | `character varying(24)` | нет | — | — |
| `actor_id` | `uuid` | да | — | — |
| `commercial_account_id` | `uuid` | да | — | — |
| `action` | `character varying(160)` | нет | — | — |
| `resource_type` | `character varying(120)` | нет | — | — |
| `resource_id` | `character varying(160)` | нет | — | — |
| `correlation_id` | `uuid` | да | — | — |
| `outcome` | `character varying(24)` | нет | — | — |
| `metadata` | `jsonb` | нет | '{}'::jsonb | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `audit_events_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.

CHECK: 5; индексов: 3; пользовательских triggers: 2.

## commercial.background_job_status

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `job_code` | `character varying(120)` | нет | — | — |
| `last_started_at` | `timestamp with time zone` | да | — | — |
| `last_succeeded_at` | `timestamp with time zone` | да | — | — |
| `last_failed_at` | `timestamp with time zone` | да | — | — |
| `last_duration_ms` | `integer` | да | — | — |
| `last_processed_count` | `integer` | да | — | — |
| `consecutive_failures` | `integer` | нет | 0 | — |
| `last_error_code` | `character varying(120)` | да | — | — |
| `last_result` | `character varying(16)` | нет | 'waiting'::character varying | — |
| `heartbeat_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (job_code).

SQL FK из этой таблицы отсутствуют.

CHECK: 6; индексов: 1; пользовательских triggers: 0.

## commercial.billing_profiles

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `legal_name` | `character varying(240)` | нет | — | — |
| `country_code` | `character(2)` | нет | — | — |
| `is_default` | `boolean` | нет | false | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `archived_at` | `timestamp with time zone` | да | — | — |
| `contact_name` | `character varying(200)` | да | — | — |
| `contact_email` | `character varying(320)` | да | — | — |
| `tax_identifier` | `character varying(80)` | да | — | — |
| `address` | `jsonb` | нет | '{}'::jsonb | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `billing_profiles_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.

CHECK: 6; индексов: 3; пользовательских triggers: 0.

## commercial.commercial_account_invitations

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | — | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `email` | `character varying(320)` | нет | — | — |
| `email_normalized` | `character varying(320)` | нет | — | — |
| `role` | `character varying(16)` | нет | — | — |
| `invited_by` | `uuid` | нет | — | — |
| `token_hash` | `bytea` | нет | — | — |
| `token_version` | `integer` | нет | 1 | — |
| `status` | `character varying(16)` | нет | 'pending'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `accepted_by` | `uuid` | да | — | — |
| `accepted_at` | `timestamp with time zone` | да | — | — |
| `declined_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `commercial_account_invitations_accepted_by_fkey`: `FOREIGN KEY (accepted_by) REFERENCES commercial.control_users(id)`.
- `commercial_account_invitations_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id)`.
- `commercial_account_invitations_invited_by_fkey`: `FOREIGN KEY (invited_by) REFERENCES commercial.control_users(id)`.

CHECK: 4; индексов: 5; пользовательских triggers: 0.

## commercial.commercial_account_memberships

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `role` | `character varying(24)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `commercial_account_memberships_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `commercial_account_memberships_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 3; пользовательских triggers: 1.

## commercial.commercial_accounts

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(120)` | нет | — | — |
| `display_name` | `character varying(200)` | нет | — | — |
| `status` | `character varying(24)` | нет | 'draft'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `archived_at` | `timestamp with time zone` | да | — | — |
| `legal_name` | `character varying(240)` | да | — | — |
| `onboarding_state` | `character varying(40)` | нет | 'profile_required'::character varying | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 6; индексов: 2; пользовательских triggers: 3.

## commercial.control_auth_challenges

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `token_hash` | `bytea` | нет | — | — |
| `kind` | `character varying(24)` | нет | — | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `attempts` | `smallint` | нет | 0 | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `invalidated_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `control_auth_challenges_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 5; индексов: 4; пользовательских triggers: 0.

## commercial.control_session_refresh_history

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `token_hash` | `bytea` | нет | — | — |
| `control_session_id` | `uuid` | нет | — | — |
| `consumed_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (token_hash).

**Реальные внешние ключи:**

- `control_session_refresh_history_control_session_id_fkey`: `FOREIGN KEY (control_session_id) REFERENCES commercial.control_sessions(id) ON DELETE CASCADE`.

CHECK: 1; индексов: 2; пользовательских triggers: 0.

## commercial.control_sessions

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `token_family_id` | `uuid` | нет | uuidv7() | — |
| `access_token_hash` | `bytea` | нет | — | — |
| `refresh_token_hash` | `bytea` | нет | — | — |
| `previous_refresh_token_hash` | `bytea` | да | — | — |
| `generation` | `integer` | нет | 0 | — |
| `issued_at` | `timestamp with time zone` | нет | now() | — |
| `access_expires_at` | `timestamp with time zone` | нет | — | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `last_used_at` | `timestamp with time zone` | да | — | — |
| `rotated_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `revoke_reason` | `character varying(80)` | да | — | — |
| `ip_hash` | `bytea` | да | — | — |
| `user_agent` | `character varying(300)` | да | — | — |
| `authentication_method` | `character varying(24)` | нет | 'password'::character varying | — |
| `primary_authenticated_at` | `timestamp with time zone` | нет | now() | — |
| `mfa_authenticated_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `control_sessions_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 9; индексов: 6; пользовательских triggers: 0.

## commercial.control_user_email_verifications

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `token_hash` | `bytea` | нет | — | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `invalidated_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `control_user_email_verifications_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 3; индексов: 4; пользовательских triggers: 0.

## commercial.control_user_password_resets

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `token_hash` | `bytea` | нет | — | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `invalidated_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `control_user_password_resets_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 3; индексов: 4; пользовательских triggers: 0.

## commercial.control_user_recovery_codes

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `factor_id` | `uuid` | нет | — | — |
| `code_hash` | `bytea` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `control_user_recovery_codes_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `control_user_recovery_codes_factor_id_fkey`: `FOREIGN KEY (factor_id) REFERENCES commercial.control_user_totp_factors(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 3; пользовательских triggers: 0.

## commercial.control_user_totp_factors

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `secret_ciphertext` | `bytea` | нет | — | — |
| `key_version` | `character varying(32)` | нет | — | — |
| `status` | `character varying(16)` | нет | 'pending'::character varying | — |
| `last_accepted_counter` | `bigint` | нет | '-1'::integer | — |
| `expires_at` | `timestamp with time zone` | да | — | — |
| `confirmed_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `control_user_totp_factors_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 3; индексов: 4; пользовательских triggers: 0.

## commercial.control_users

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `email` | `character varying(320)` | нет | — | — |
| `status` | `character varying(24)` | нет | 'pending_verification'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `archived_at` | `timestamp with time zone` | да | — | — |
| `email_normalized` | `character varying(320)` | нет | — | — |
| `password_hash` | `character varying(255)` | нет | — | — |
| `email_verified_at` | `timestamp with time zone` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `last_login_at` | `timestamp with time zone` | да | — | — |
| `password_changed_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 4; индексов: 2; пользовательских triggers: 1.

## commercial.deployment_domains

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `deployment_id` | `uuid` | нет | — | — |
| `kind` | `character varying(16)` | нет | — | — |
| `hostname` | `character varying(253)` | нет | — | — |
| `status` | `character varying(24)` | нет | 'pending'::character varying | — |
| `ownership_verified_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `disabled_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `deployment_domains_deployment_id_fkey`: `FOREIGN KEY (deployment_id) REFERENCES commercial.deployments(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 3; пользовательских triggers: 1.

## commercial.deployment_entitlement_documents

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `deployment_id` | `uuid` | нет | — | — |
| `installation_id` | `uuid` | нет | — | — |
| `document_version` | `integer` | нет | — | — |
| `sequence` | `bigint` | нет | — | — |
| `canonical_document` | `bytea` | нет | — | — |
| `document_sha256` | `bytea` | нет | — | — |
| `signature` | `bytea` | нет | — | — |
| `signing_key_id` | `character varying(160)` | нет | — | — |
| `source_fingerprint` | `bytea` | нет | — | — |
| `issued_at` | `timestamp with time zone` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `deployment_entitlement_documents_deployment_id_fkey`: `FOREIGN KEY (deployment_id) REFERENCES commercial.deployments(id) ON DELETE RESTRICT`.
- `deployment_entitlement_documents_signing_key_id_fkey`: `FOREIGN KEY (signing_key_id) REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 3; пользовательских triggers: 1.

## commercial.deployment_operations

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `deployment_id` | `uuid` | нет | — | — |
| `operation_type` | `character varying(32)` | нет | — | — |
| `idempotency_key` | `character varying(200)` | нет | — | — |
| `status` | `character varying(24)` | нет | 'pending'::character varying | — |
| `current_step` | `character varying(120)` | да | — | — |
| `attempt_count` | `integer` | нет | 0 | — |
| `next_retry_at` | `timestamp with time zone` | да | — | — |
| `last_error_code` | `character varying(120)` | да | — | — |
| `started_at` | `timestamp with time zone` | да | — | — |
| `finished_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `deployment_operations_deployment_id_fkey`: `FOREIGN KEY (deployment_id) REFERENCES commercial.deployments(id) ON DELETE RESTRICT`.

CHECK: 5; индексов: 3; пользовательских triggers: 0.

## commercial.deployments

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `installation_id` | `uuid` | нет | — | — |
| `mode` | `character varying(16)` | нет | — | — |
| `system_slug` | `character varying(63)` | да | — | — |
| `status` | `character varying(24)` | нет | 'reserved'::character varying | — |
| `provisioning_state` | `character varying(24)` | нет | 'pending'::character varying | — |
| `current_release_id` | `uuid` | да | — | — |
| `desired_release_id` | `uuid` | да | — | — |
| `core_db_secret_ref` | `character varying(300)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `archived_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `deployments_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `deployments_current_release_id_fkey`: `FOREIGN KEY (current_release_id) REFERENCES commercial.releases(id) ON DELETE RESTRICT`.
- `deployments_desired_release_id_fkey`: `FOREIGN KEY (desired_release_id) REFERENCES commercial.releases(id) ON DELETE RESTRICT`.

CHECK: 6; индексов: 4; пользовательских triggers: 2.

## commercial.external_compensation_attestations

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `plan_change_id` | `uuid` | нет | — | — |
| `payment_id` | `uuid` | нет | — | — |
| `amount_minor` | `bigint` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `external_transaction_reference` | `character varying(200)` | нет | — | — |
| `document_reference` | `character varying(500)` | нет | — | — |
| `document_sha256` | `character(64)` | нет | — | — |
| `proposed_by` | `uuid` | нет | — | — |
| `proposed_at` | `timestamp with time zone` | нет | now() | — |
| `status` | `character varying(16)` | нет | 'proposed'::character varying | — |
| `reviewed_by` | `uuid` | да | — | — |
| `reviewed_at` | `timestamp with time zone` | да | — | — |
| `rejection_reason` | `character varying(500)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `external_compensation_attestations_payment_id_fkey`: `FOREIGN KEY (payment_id) REFERENCES commercial.payments(id) ON DELETE RESTRICT`.
- `external_compensation_attestations_plan_change_id_fkey`: `FOREIGN KEY (plan_change_id) REFERENCES commercial.subscription_plan_changes(id) ON DELETE RESTRICT`.
- `external_compensation_attestations_proposed_by_fkey`: `FOREIGN KEY (proposed_by) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `external_compensation_attestations_reviewed_by_fkey`: `FOREIGN KEY (reviewed_by) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 7; индексов: 3; пользовательских triggers: 1.

## commercial.features

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(160)` | нет | — | — |
| `value_type` | `character varying(16)` | нет | — | — |
| `description` | `text` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `retired_at` | `timestamp with time zone` | да | — | — |
| `usage_mode` | `character varying(16)` | нет | 'none'::character varying | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 5; индексов: 2; пользовательских triggers: 1.

## commercial.financial_operators

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `control_user_id` | `uuid` | нет | — | — |
| `authorization_reference` | `character varying(500)` | нет | — | — |
| `authorized_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (control_user_id).

**Реальные внешние ключи:**

- `financial_operators_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 1; пользовательских triggers: 0.

## commercial.idempotency_keys

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `scope` | `character varying(120)` | нет | — | — |
| `idempotency_key` | `character varying(200)` | нет | — | — |
| `request_sha256` | `bytea` | нет | — | — |
| `status` | `character varying(24)` | нет | — | — |
| `result_reference` | `character varying(200)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 5; индексов: 2; пользовательских triggers: 1.

## commercial.invoice_lines

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `invoice_id` | `uuid` | нет | — | — |
| `subscription_item_id` | `uuid` | нет | — | — |
| `plan_price_id` | `uuid` | нет | — | — |
| `plan_code` | `character varying(120)` | нет | — | — |
| `plan_version` | `integer` | нет | — | — |
| `plan_name` | `character varying(200)` | нет | — | — |
| `billing_period` | `character varying(24)` | нет | — | — |
| `description` | `character varying(400)` | нет | — | — |
| `quantity` | `integer` | нет | — | — |
| `unit_amount_minor` | `bigint` | нет | — | — |
| `total_minor` | `bigint` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `invoice_lines_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `invoice_lines_plan_price_id_fkey`: `FOREIGN KEY (plan_price_id) REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT`.
- `invoice_lines_subscription_item_id_fkey`: `FOREIGN KEY (subscription_item_id) REFERENCES commercial.subscription_items(id) ON DELETE RESTRICT`.

CHECK: 8; индексов: 4; пользовательских triggers: 1.

## commercial.invoices

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `subscription_id` | `uuid` | нет | — | — |
| `billing_profile_id` | `uuid` | нет | — | — |
| `billing_reason` | `character varying(40)` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `total_minor` | `bigint` | нет | 0 | — |
| `status` | `character varying(16)` | нет | 'draft'::character varying | — |
| `bill_to_legal_name` | `character varying(240)` | нет | — | — |
| `bill_to_country_code` | `character(2)` | нет | — | — |
| `bill_to_contact_name` | `character varying(200)` | да | — | — |
| `bill_to_contact_email` | `character varying(320)` | да | — | — |
| `bill_to_tax_identifier` | `character varying(80)` | да | — | — |
| `bill_to_address` | `jsonb` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `issued_at` | `timestamp with time zone` | да | — | — |
| `paid_at` | `timestamp with time zone` | да | — | — |
| `voided_at` | `timestamp with time zone` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `service_period_start` | `timestamp with time zone` | да | — | — |
| `service_period_end` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `invoices_billing_profile_id_fkey`: `FOREIGN KEY (billing_profile_id) REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT`.
- `invoices_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `invoices_subscription_id_fkey`: `FOREIGN KEY (subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

CHECK: 12; индексов: 6; пользовательских triggers: 1.

## commercial.issued_entitlement_documents

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `license_id` | `uuid` | нет | — | — |
| `installation_id` | `uuid` | нет | — | — |
| `document_version` | `integer` | нет | — | — |
| `sequence` | `bigint` | нет | — | — |
| `envelope` | `bytea` | нет | — | — |
| `envelope_sha256` | `bytea` | нет | — | — |
| `signature` | `bytea` | нет | — | — |
| `signing_key_id` | `character varying(160)` | нет | — | — |
| `issued_at` | `timestamp with time zone` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `issued_entitlement_documents_license_id_fkey`: `FOREIGN KEY (license_id) REFERENCES commercial.licenses(id) ON DELETE RESTRICT`.
- `issued_entitlement_documents_signing_key_id_fkey`: `FOREIGN KEY (signing_key_id) REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 2; пользовательских triggers: 1.

## commercial.license_installations

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `license_id` | `uuid` | нет | — | — |
| `installation_id` | `uuid` | нет | — | — |
| `activated_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (license_id, installation_id).

**Реальные внешние ключи:**

- `license_installations_license_id_fkey`: `FOREIGN KEY (license_id) REFERENCES commercial.licenses(id) ON DELETE RESTRICT`.

CHECK: 0; индексов: 1; пользовательских triggers: 1.

## commercial.licenses

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `kind` | `character varying(24)` | нет | — | — |
| `status` | `character varying(24)` | нет | 'draft'::character varying | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `max_installations` | `integer` | нет | — | — |
| `policy` | `jsonb` | нет | '{}'::jsonb | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `licenses_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.

CHECK: 6; индексов: 2; пользовательских triggers: 2.

## commercial.maintenance_contracts

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `license_id` | `uuid` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | нет | — | — |
| `status` | `character varying(24)` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `maintenance_contracts_license_id_fkey`: `FOREIGN KEY (license_id) REFERENCES commercial.licenses(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 3; пользовательских triggers: 1.

## commercial.outbox_events

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `bigint` | нет | IDENTITY ALWAYS | — |
| `event_id` | `uuid` | нет | uuidv7() | — |
| `event_type` | `character varying(180)` | нет | — | — |
| `aggregate_type` | `character varying(120)` | нет | — | — |
| `aggregate_id` | `uuid` | нет | — | — |
| `payload` | `jsonb` | нет | — | — |
| `status` | `character varying(24)` | нет | 'pending'::character varying | — |
| `attempts` | `integer` | нет | 0 | — |
| `available_at` | `timestamp with time zone` | нет | now() | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `published_at` | `timestamp with time zone` | да | — | — |
| `schema_version` | `smallint` | нет | 1 | — |
| `locked_at` | `timestamp with time zone` | да | — | — |
| `lock_token` | `uuid` | да | — | — |
| `last_error` | `character varying(160)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 8; индексов: 4; пользовательских triggers: 1.

## commercial.payment_attempts

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `payment_id` | `uuid` | нет | — | — |
| `provider_account_id` | `uuid` | нет | — | — |
| `method_type` | `character varying(80)` | нет | — | — |
| `provider_request_key` | `character varying(200)` | нет | — | — |
| `provider_payment_id` | `character varying(200)` | да | — | — |
| `status` | `character varying(16)` | нет | 'pending'::character varying | — |
| `failure_code` | `character varying(120)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `completed_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `payment_attempts_payment_id_fkey`: `FOREIGN KEY (payment_id) REFERENCES commercial.payments(id) ON DELETE RESTRICT`.
- `payment_attempts_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.

CHECK: 6; индексов: 5; пользовательских triggers: 1.

## commercial.payment_provider_accounts

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `provider_code` | `character varying(120)` | нет | — | — |
| `external_account_id` | `character varying(200)` | да | — | — |
| `secret_ref` | `character varying(300)` | нет | — | — |
| `status` | `character varying(16)` | нет | 'active'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `receipt_mode` | `character varying(24)` | нет | 'disabled'::character varying | — |
| `receipt_vat_code` | `smallint` | да | — | — |
| `receipt_payment_subject` | `character varying(40)` | да | — | — |
| `receipt_payment_mode` | `character varying(40)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 5; индексов: 1; пользовательских triggers: 1.

## commercial.payment_provider_events

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `provider_account_id` | `uuid` | нет | — | — |
| `event_key` | `character varying(400)` | нет | — | — |
| `event_type` | `character varying(160)` | нет | — | — |
| `provider_object_id` | `character varying(200)` | нет | — | — |
| `payload_sha256` | `bytea` | нет | — | — |
| `status` | `character varying(16)` | нет | 'received'::character varying | — |
| `received_at` | `timestamp with time zone` | нет | now() | — |
| `processed_at` | `timestamp with time zone` | да | — | — |
| `last_error_code` | `character varying(120)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `payment_provider_events_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.

CHECK: 7; индексов: 3; пользовательских triggers: 1.

## commercial.payments

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `invoice_id` | `uuid` | нет | — | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `amount_minor` | `bigint` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `status` | `character varying(16)` | нет | 'pending'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `succeeded_at` | `timestamp with time zone` | да | — | — |
| `cancelled_at` | `timestamp with time zone` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `payments_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `payments_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 3; пользовательских triggers: 1.

## commercial.plan_features

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `plan_id` | `uuid` | нет | — | — |
| `feature_id` | `uuid` | нет | — | — |
| `value` | `jsonb` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (plan_id, feature_id).

**Реальные внешние ключи:**

- `plan_features_feature_id_fkey`: `FOREIGN KEY (feature_id) REFERENCES commercial.features(id) ON DELETE RESTRICT`.
- `plan_features_plan_id_fkey`: `FOREIGN KEY (plan_id) REFERENCES commercial.plans(id) ON DELETE RESTRICT`.

CHECK: 0; индексов: 2; пользовательских triggers: 1.

## commercial.plan_prices

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `plan_id` | `uuid` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `amount_minor` | `bigint` | нет | — | — |
| `billing_period` | `character varying(24)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `plan_prices_plan_id_fkey`: `FOREIGN KEY (plan_id) REFERENCES commercial.plans(id) ON DELETE RESTRICT`.

CHECK: 3; индексов: 2; пользовательских triggers: 1.

## commercial.plans

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(120)` | нет | — | — |
| `version` | `integer` | нет | — | — |
| `status` | `character varying(24)` | нет | 'draft'::character varying | — |
| `name` | `character varying(200)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `published_at` | `timestamp with time zone` | да | — | — |
| `retired_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 5; индексов: 2; пользовательских triggers: 1.

## commercial.privacy_erasure_cases

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `status` | `character varying(32)` | нет | — | — |
| `reason_code` | `character varying(80)` | нет | — | — |
| `financial_evidence_count` | `integer` | нет | 0 | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `completed_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_erasure_cases_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 1; пользовательских triggers: 1.

## commercial.privacy_export_events

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `actor_id` | `uuid` | нет | — | — |
| `schema_version` | `smallint` | нет | 1 | — |
| `item_count` | `integer` | нет | — | — |
| `exported_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_export_events_actor_id_fkey`: `FOREIGN KEY (actor_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `privacy_export_events_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 1; пользовательских triggers: 1.

## commercial.privacy_holds

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `reason_code` | `character varying(80)` | нет | — | — |
| `opened_at` | `timestamp with time zone` | нет | now() | — |
| `released_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_holds_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 2; пользовательских triggers: 0.

## commercial.privacy_incident_events

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `incident_id` | `uuid` | нет | — | — |
| `event_code` | `character varying(80)` | нет | — | — |
| `evidence_reference` | `character varying(500)` | да | — | — |
| `actor_user_id` | `uuid` | нет | — | — |
| `occurred_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_incident_events_actor_user_id_fkey`: `FOREIGN KEY (actor_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `privacy_incident_events_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES commercial.privacy_incidents(id) ON DELETE RESTRICT`.

CHECK: 0; индексов: 1; пользовательских triggers: 1.

## commercial.privacy_incidents

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `detected_at` | `timestamp with time zone` | нет | — | — |
| `initial_notification_due_at` | `timestamp with time zone` | нет | — | — |
| `investigation_notification_due_at` | `timestamp with time zone` | нет | — | — |
| `category_codes` | `text[]` | нет | — | — |
| `estimated_subjects` | `integer` | да | — | — |
| `status` | `character varying(32)` | нет | 'detected'::character varying | — |
| `containment_reference` | `character varying(500)` | да | — | — |
| `investigation_reference` | `character varying(500)` | да | — | — |
| `initial_notification_reference` | `character varying(500)` | да | — | — |
| `investigation_notification_reference` | `character varying(500)` | да | — | — |
| `created_by_user_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_incidents_created_by_user_id_fkey`: `FOREIGN KEY (created_by_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 2; пользовательских triggers: 0.

## commercial.privacy_lifecycle_runs

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `category` | `character varying(80)` | нет | — | — |
| `dry_run` | `boolean` | нет | — | — |
| `selected_count` | `integer` | нет | — | — |
| `deleted_count` | `integer` | нет | — | — |
| `executed_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

SQL FK из этой таблицы отсутствуют.

CHECK: 1; индексов: 1; пользовательских triggers: 0.

## commercial.privacy_operators

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `control_user_id` | `uuid` | нет | — | — |
| `authorization_reference` | `character varying(500)` | нет | — | — |
| `authorized_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (control_user_id).

**Реальные внешние ключи:**

- `privacy_operators_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

CHECK: 0; индексов: 1; пользовательских triggers: 0.

## commercial.privacy_subject_holds

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `billing_profile_id` | `uuid` | нет | — | — |
| `reason_code` | `character varying(80)` | нет | — | — |
| `opened_at` | `timestamp with time zone` | нет | now() | — |
| `released_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_subject_holds_billing_profile_id_fkey`: `FOREIGN KEY (billing_profile_id) REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 1; пользовательских triggers: 0.

## commercial.privacy_subject_requests

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subject_kind` | `character varying(32)` | нет | — | — |
| `subject_reference` | `character varying(500)` | нет | — | — |
| `identity_evidence_reference` | `character varying(500)` | нет | — | — |
| `billing_profile_id` | `uuid` | да | — | — |
| `invoice_id` | `uuid` | да | — | — |
| `receipt_id` | `uuid` | да | — | — |
| `request_type` | `character varying(32)` | нет | — | — |
| `source_channel` | `character varying(32)` | нет | — | — |
| `received_at` | `timestamp with time zone` | нет | — | — |
| `status` | `character varying(32)` | нет | 'submitted'::character varying | — |
| `resolution_code` | `character varying(120)` | да | — | — |
| `response_evidence_reference` | `character varying(500)` | да | — | — |
| `operator_user_id` | `uuid` | нет | — | — |
| `completed_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `privacy_subject_requests_billing_profile_id_fkey`: `FOREIGN KEY (billing_profile_id) REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT`.
- `privacy_subject_requests_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `privacy_subject_requests_operator_user_id_fkey`: `FOREIGN KEY (operator_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `privacy_subject_requests_receipt_id_fkey`: `FOREIGN KEY (receipt_id) REFERENCES commercial.receipts(id) ON DELETE RESTRICT`.

CHECK: 6; индексов: 2; пользовательских triggers: 0.

## commercial.rate_limit_counters

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `scope` | `character varying(80)` | нет | — | — |
| `key_hash` | `bytea` | нет | — | — |
| `window_started_at` | `timestamp with time zone` | нет | — | — |
| `hits` | `integer` | нет | 1 | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (scope, key_hash, window_started_at).

SQL FK из этой таблицы отсутствуют.

CHECK: 4; индексов: 2; пользовательских triggers: 0.

## commercial.receipt_items

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `receipt_id` | `uuid` | нет | — | — |
| `invoice_line_id` | `uuid` | нет | — | — |
| `description` | `character varying(400)` | нет | — | — |
| `quantity` | `integer` | нет | — | — |
| `unit_amount_minor` | `bigint` | нет | — | — |
| `total_minor` | `bigint` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `vat_code` | `smallint` | нет | — | — |
| `payment_subject` | `character varying(40)` | нет | — | — |
| `payment_mode` | `character varying(40)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `receipt_items_invoice_line_id_fkey`: `FOREIGN KEY (invoice_line_id) REFERENCES commercial.invoice_lines(id) ON DELETE RESTRICT`.
- `receipt_items_receipt_id_fkey`: `FOREIGN KEY (receipt_id) REFERENCES commercial.receipts(id) ON DELETE RESTRICT`.

CHECK: 5; индексов: 3; пользовательских triggers: 2.

## commercial.receipts

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `payment_id` | `uuid` | нет | — | — |
| `invoice_id` | `uuid` | нет | — | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `payment_attempt_id` | `uuid` | да | — | — |
| `provider_account_id` | `uuid` | нет | — | — |
| `receipt_type` | `character varying(24)` | нет | 'payment'::character varying | — |
| `status` | `character varying(16)` | нет | 'pending'::character varying | — |
| `provider_request_key` | `character varying(200)` | нет | — | — |
| `external_receipt_id` | `character varying(200)` | да | — | — |
| `amount_minor` | `bigint` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `customer_email` | `character varying(320)` | нет | — | — |
| `customer_name` | `character varying(200)` | да | — | — |
| `vat_code` | `smallint` | нет | — | — |
| `payment_subject` | `character varying(40)` | нет | — | — |
| `payment_mode` | `character varying(40)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `last_attempted_at` | `timestamp with time zone` | да | — | — |
| `completed_at` | `timestamp with time zone` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `last_error_code` | `character varying(120)` | да | — | — |
| `refund_id` | `uuid` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `receipts_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `receipts_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `receipts_payment_attempt_id_fkey`: `FOREIGN KEY (payment_attempt_id) REFERENCES commercial.payment_attempts(id) ON DELETE RESTRICT`.
- `receipts_payment_id_fkey`: `FOREIGN KEY (payment_id) REFERENCES commercial.payments(id) ON DELETE RESTRICT`.
- `receipts_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.
- `receipts_refund_id_fkey`: `FOREIGN KEY (refund_id) REFERENCES commercial.refunds(id) ON DELETE RESTRICT`.

CHECK: 11; индексов: 6; пользовательских triggers: 2.

## commercial.refund_items

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `refund_id` | `uuid` | нет | — | — |
| `invoice_line_id` | `uuid` | нет | — | — |
| `description` | `character varying(400)` | нет | — | — |
| `quantity` | `integer` | нет | — | — |
| `unit_amount_minor` | `bigint` | нет | — | — |
| `total_minor` | `bigint` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `vat_code` | `smallint` | нет | — | — |
| `payment_subject` | `character varying(40)` | нет | — | — |
| `payment_mode` | `character varying(40)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `refund_items_invoice_line_id_fkey`: `FOREIGN KEY (invoice_line_id) REFERENCES commercial.invoice_lines(id) ON DELETE RESTRICT`.
- `refund_items_refund_id_fkey`: `FOREIGN KEY (refund_id) REFERENCES commercial.refunds(id) ON DELETE RESTRICT`.

CHECK: 5; индексов: 3; пользовательских triggers: 2.

## commercial.refunds

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `payment_id` | `uuid` | нет | — | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `provider_account_id` | `uuid` | нет | — | — |
| `amount_minor` | `bigint` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `status` | `character varying(16)` | нет | 'pending'::character varying | — |
| `client_request_key` | `character varying(200)` | нет | — | — |
| `request_sha256` | `bytea` | нет | — | — |
| `provider_request_key` | `character varying(200)` | нет | — | — |
| `provider_refund_id` | `character varying(200)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `last_attempted_at` | `timestamp with time zone` | да | — | — |
| `completed_at` | `timestamp with time zone` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `last_error_code` | `character varying(120)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `refunds_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `refunds_payment_id_fkey`: `FOREIGN KEY (payment_id) REFERENCES commercial.payments(id) ON DELETE RESTRICT`.
- `refunds_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.

CHECK: 9; индексов: 6; пользовательских triggers: 2.

## commercial.release_entitlements

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `release_id` | `uuid` | нет | — | — |
| `feature_id` | `uuid` | нет | — | — |
| `requirement` | `jsonb` | нет | — | — |

**Первичный ключ:** PRIMARY KEY (release_id, feature_id).

**Реальные внешние ключи:**

- `release_entitlements_feature_id_fkey`: `FOREIGN KEY (feature_id) REFERENCES commercial.features(id) ON DELETE RESTRICT`.
- `release_entitlements_release_id_fkey`: `FOREIGN KEY (release_id) REFERENCES commercial.releases(id) ON DELETE RESTRICT`.

CHECK: 0; индексов: 2; пользовательских triggers: 1.

## commercial.releases

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `version` | `character varying(80)` | нет | — | — |
| `channel` | `character varying(24)` | нет | — | — |
| `status` | `character varying(24)` | нет | 'draft'::character varying | — |
| `compatibility` | `jsonb` | нет | '{}'::jsonb | — |
| `oci_reference` | `character varying(400)` | да | — | — |
| `image_digest` | `character varying(80)` | да | — | — |
| `manifest_sha256` | `bytea` | да | — | — |
| `manifest_signature` | `bytea` | да | — | — |
| `signing_key_id` | `character varying(160)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `published_at` | `timestamp with time zone` | да | — | — |
| `retired_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `releases_signing_key_id_fkey`: `FOREIGN KEY (signing_key_id) REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT`.

CHECK: 9; индексов: 2; пользовательских triggers: 1.

## commercial.renewal_collection_states

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `invoice_id` | `uuid` | нет | — | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `subscription_id` | `uuid` | нет | — | — |
| `state` | `character varying(32)` | нет | 'ready'::character varying | — |
| `attempt_count` | `integer` | нет | 0 | — |
| `last_attempt_at` | `timestamp with time zone` | да | — | — |
| `next_attempt_at` | `timestamp with time zone` | да | — | — |
| `last_failure_class` | `character varying(32)` | да | — | — |
| `last_failure_code` | `character varying(120)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `network_retry_count` | `integer` | нет | 0 | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `renewal_collection_account_subscription_fk`: `FOREIGN KEY (commercial_account_id, subscription_id) REFERENCES commercial.subscriptions(commercial_account_id, id) ON DELETE RESTRICT`.
- `renewal_collection_invoice_identity_fk`: `FOREIGN KEY (commercial_account_id, subscription_id, invoice_id) REFERENCES commercial.invoices(commercial_account_id, subscription_id, id) ON DELETE RESTRICT`.
- `renewal_collection_states_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `renewal_collection_states_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `renewal_collection_states_subscription_id_fkey`: `FOREIGN KEY (subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

CHECK: 4; индексов: 3; пользовательских triggers: 1.

## commercial.saved_payment_methods

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `provider_account_id` | `uuid` | нет | — | — |
| `provider_method_reference` | `character varying(200)` | нет | — | — |
| `method_type` | `character varying(80)` | нет | — | — |
| `status` | `character varying(24)` | нет | 'active'::character varying | — |
| `display_title` | `character varying(200)` | да | — | — |
| `card_brand` | `character varying(80)` | да | — | — |
| `last4` | `character varying(4)` | да | — | — |
| `expiry_month` | `smallint` | да | — | — |
| `expiry_year` | `smallint` | да | — | — |
| `is_default` | `boolean` | нет | false | — |
| `created_from_payment_attempt_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `last_used_at` | `timestamp with time zone` | да | — | — |
| `disabled_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `saved_payment_methods_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `saved_payment_methods_created_from_payment_attempt_id_fkey`: `FOREIGN KEY (created_from_payment_attempt_id) REFERENCES commercial.payment_attempts(id) ON DELETE RESTRICT`.
- `saved_payment_methods_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.

CHECK: 5; индексов: 4; пользовательских triggers: 1.

## commercial.service_credentials

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `deployment_id` | `uuid` | нет | — | — |
| `credential_id` | `uuid` | нет | uuidv4() | — |
| `secret_hash` | `bytea` | нет | — | — |
| `status` | `character varying(16)` | нет | 'active'::character varying | — |
| `scopes` | `text[]` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | да | — | — |
| `last_used_at` | `timestamp with time zone` | да | — | — |
| `rotated_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `service_credentials_deployment_id_fkey`: `FOREIGN KEY (deployment_id) REFERENCES commercial.deployments(id) ON DELETE RESTRICT`.

CHECK: 5; индексов: 4; пользовательских triggers: 0.

## commercial.signing_keys

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `key_id` | `character varying(160)` | нет | — | — |
| `algorithm` | `character varying(24)` | нет | — | — |
| `purpose` | `character varying(32)` | нет | — | — |
| `public_key` | `bytea` | нет | — | — |
| `private_key_secret_ref` | `character varying(300)` | нет | — | — |
| `status` | `character varying(24)` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (key_id).

SQL FK из этой таблицы отсутствуют.

CHECK: 8; индексов: 1; пользовательских triggers: 2.

## commercial.subscription_items

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subscription_id` | `uuid` | нет | — | — |
| `plan_price_id` | `uuid` | нет | — | — |
| `quantity` | `integer` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `subscription_items_plan_price_id_fkey`: `FOREIGN KEY (plan_price_id) REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT`.
- `subscription_items_subscription_id_fkey`: `FOREIGN KEY (subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

CHECK: 2; индексов: 3; пользовательских triggers: 1.

## commercial.subscription_plan_changes

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `source_subscription_id` | `uuid` | нет | — | — |
| `target_subscription_id` | `uuid` | нет | — | — |
| `old_plan_price_id` | `uuid` | нет | — | — |
| `new_plan_price_id` | `uuid` | нет | — | — |
| `old_quantity` | `integer` | нет | — | — |
| `new_quantity` | `integer` | нет | — | — |
| `change_kind` | `character varying(16)` | нет | — | — |
| `status` | `character varying(24)` | нет | — | — |
| `idempotency_key` | `character varying(200)` | нет | — | — |
| `request_sha256` | `bytea` | нет | — | — |
| `calculation` | `jsonb` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `proration_net_minor` | `bigint` | нет | — | — |
| `amount_due_minor` | `bigint` | нет | — | — |
| `period_start` | `timestamp with time zone` | нет | — | — |
| `period_end` | `timestamp with time zone` | нет | — | — |
| `invoice_id` | `uuid` | да | — | — |
| `requested_at` | `timestamp with time zone` | нет | now() | — |
| `applied_at` | `timestamp with time zone` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `compensation_status` | `character varying(24)` | да | — | — |
| `compensation_reason` | `character varying(40)` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `subscription_plan_changes_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_new_plan_price_id_fkey`: `FOREIGN KEY (new_plan_price_id) REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_old_plan_price_id_fkey`: `FOREIGN KEY (old_plan_price_id) REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_source_subscription_id_fkey`: `FOREIGN KEY (source_subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_target_subscription_id_fkey`: `FOREIGN KEY (target_subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

CHECK: 11; индексов: 7; пользовательских triggers: 1.

## commercial.subscriptions

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `billing_profile_id` | `uuid` | нет | — | — |
| `status` | `character varying(24)` | нет | 'draft'::character varying | — |
| `current_period_start` | `timestamp with time zone` | да | — | — |
| `current_period_end` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `billing_status` | `character varying(16)` | нет | 'current'::character varying | — |
| `past_due_since` | `timestamp with time zone` | да | — | — |
| `grace_until` | `timestamp with time zone` | да | — | — |
| `auto_renew_enabled` | `boolean` | нет | true | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `subscriptions_billing_profile_id_fkey`: `FOREIGN KEY (billing_profile_id) REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT`.
- `subscriptions_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.

CHECK: 7; индексов: 6; пользовательских triggers: 2.

## commercial.usage_counters

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `usage_period_id` | `uuid` | нет | — | — |
| `feature_id` | `uuid` | нет | — | — |
| `quantity` | `bigint` | нет | 0 | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** PRIMARY KEY (usage_period_id, feature_id).

**Реальные внешние ключи:**

- `usage_counters_feature_id_fkey`: `FOREIGN KEY (feature_id) REFERENCES commercial.features(id) ON DELETE RESTRICT`.
- `usage_counters_usage_period_id_fkey`: `FOREIGN KEY (usage_period_id) REFERENCES commercial.usage_periods(id) ON DELETE RESTRICT`.

CHECK: 1; индексов: 2; пользовательских triggers: 1.

## commercial.usage_periods

| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subscription_id` | `uuid` | нет | — | — |
| `starts_at` | `timestamp with time zone` | нет | — | — |
| `ends_at` | `timestamp with time zone` | нет | — | — |
| `status` | `character varying(16)` | нет | 'open'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `closed_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** PRIMARY KEY (id).

**Реальные внешние ключи:**

- `usage_periods_subscription_id_fkey`: `FOREIGN KEY (subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

CHECK: 3; индексов: 3; пользовательских triggers: 2.

