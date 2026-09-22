# Покрытие базы данных Core

В этой таблице каждой таблице зафиксированной схемы `core` из 23 таблиц сопоставлен
владелец в backend. `PUBLIC API` означает намеренно открытый HTTP-ресурс;
`INTERNAL SERVICE` — доступность для кода приложения без универсального CRUD;
`CONTROL PLANE API` — защищённый RBAC административный HTTP-интерфейс;
`INFRASTRUCTURE ONLY` — намеренное размещение механизмов учётных данных и хранения
ниже границы публичного приложения.

| Таблица | Репозиторий | Сервис / сценарий использования | API | Тесты | Чувствительные данные | Статус |
|---|---|---|---|---|---|---|
| `core.persons` | `PersonRepository` / `PostgresPersonRepository` | list, create, get, update, archive | `GET/POST /api/core/persons`, `GET/PATCH /api/core/persons/{id}`, `POST .../{id}/archive` | `PersonsCest`, Person input unit tests | PII names, birth date | PUBLIC API |
| `core.person_contacts` | `PersonContactRepository` / PostgreSQL adapter | list/create contact, `ContactNormalizer` | `GET/POST /api/core/persons/{id}/contacts` | `PersonsCest` | contact PII | PUBLIC API |
| `core.accounts` | `AccountRepository`, `AccountPreferencesRepository` / PostgreSQL adapters | list, create, get, lifecycle, invitation, locale/timezone preference | `/api/core/accounts*`, `PATCH /api/auth/me/preferences` | `AccountsCest`, `CredentialLifecycleCest`, `InternationalPreferencesCest` | security/lifecycle metadata omitted | PUBLIC API + INTERNAL SERVICE |
| `core.auth_identifiers` | `IdentifierRepository` / `PostgresAuthRepository` | normalized lookup, verified recovery-email selection | MFA recovery request, без прямого CRUD | `AuthPersistenceCest`, MFA API tests | login/recovery identifier | INTERNAL SERVICE |
| `core.auth_password_credentials` | `PasswordCredentialRepository` / Auth adapter | internal retrieval | none | `AuthPersistenceCest`, `AuthSecurityCest` | password hash | INFRASTRUCTURE ONLY |
| `core.auth_providers` | `AuthProviderRepository` / Auth adapter | provider policy and enabled list | `GET /api/auth/providers` | `ExternalIdentityMfaPersistenceCest`, schema by `db:check` | provider policy metadata only; secrets only в env | AUTH API + INTERNAL SERVICE |
| `core.auth_external_identities` | `ExternalIdentityRepository` / Auth adapter | login snapshot, explicit link/unlink, conflict handling | `/api/auth/external/*`, `/api/auth/me/identities*` | `ExternalIdentityMfaPersistenceCest` | immutable subject, allowlisted profile snapshot; provider tokens forbidden | AUTH API + INTERNAL SERVICE |
| `core.auth_passkeys` | `PasskeyRepository` / Auth adapter | credential-id lookup | none | repository mapping + `AuthSecurityCest` | credential/public-key material | INFRASTRUCTURE ONLY |
| `core.auth_totp_factors` | `TotpFactorRepository` / Auth adapter | pending setup, confirm, active factor, atomic counter anti-replay, revoke | `/api/auth/mfa*` | `MfaAuthenticationCest`, `ExternalIdentityMfaPersistenceCest`, primitive unit tests | XChaCha20-Poly1305 ciphertext; plaintext only in setup response | AUTH API + INFRASTRUCTURE ONLY |
| `core.auth_recovery_codes` | `RecoveryCodeRepository` / Auth adapter | generate hashes, atomic consume, revoke/regenerate/count | MFA verify/status/regenerate | `MfaAuthenticationCest`, `ExternalIdentityMfaPersistenceCest` | one-way recovery-code hash | AUTH API + INFRASTRUCTURE ONLY |
| `core.auth_sessions` | `SessionRepository` / Auth adapter | store, lookup, rotation, throttled touch, list active own sessions, revoke one/others/all | login/refresh/logout и `/api/auth/sessions*` без раскрытия token hashes | `AuthenticationRuntimeCest`, `AuthPersistenceCest`, `AuthSecurityCest`, `SessionLifecycleCest` | hashes/stamp hidden; network, device, approximate GeoIP protected | INTERNAL SERVICE + AUTH API |
| `core.auth_challenges` | `AuthChallengeRepository` / Auth adapter | store, find active, atomic failed attempts, consume/cancel | activation/reset, external OIDC, MFA login/recovery | `AuthPersistenceCest`, `MfaAuthenticationCest` | bearer secret hashes; nonce/PKCE verifier are encrypted, not plaintext payload | INTERNAL SERVICE |
| `core.roles` | `RoleRepository` / PostgreSQL adapter | list, account roles, superadmin check | `GET /api/core/roles` | `RbacCest`, `PermissionServiceTest` | authorization metadata | PUBLIC API |
| `core.permissions` | `PermissionRepository` / PostgreSQL adapter | list, account permissions, permission check | `GET /api/core/permissions` | `RbacCest`, `PermissionServiceTest` | authorization metadata | PUBLIC API |
| `core.role_permissions` | `RoleRepository` + `PermissionRepository` query path | `PermissionService` | none | `PermissionServiceTest`, repository SQL/static analysis | grant provenance | INTERNAL SERVICE |
| `core.account_roles` | `RoleRepository` + `PermissionRepository` query path | account roles/permissions with expiry | `GET/POST/DELETE /api/core/accounts/{id}/roles*` | `RbacCest`, `PermissionServiceTest` | account authorization/provenance | CONTROL PLANE API + INTERNAL SERVICE |
| `core.modules` | `ModuleRepository` / PostgreSQL adapter | `ModuleService`: registry read and explicit enable/disable | `GET /api/core/modules*`, `POST .../{code}/enable|disable` | `ConfigurationRuntimeCest`, `ConfigurationAuthorizationCest`, `SettingsPersistenceCest` | no secrets | CONTROL PLANE API + INTERNAL SERVICE |
| `core.module_settings` | `ModuleSettingsRepository` / PostgreSQL adapter | `SettingsService`, atomic upsert/delete | `GET/PUT/DELETE /api/core/modules/{moduleCode}/settings*` | `ConfigurationRuntimeCest`, `ConfigurationAuthorizationCest` | secret-like keys forbidden recursively | CONTROL PLANE API + INTERNAL SERVICE |
| `core.feature_flags` | `FeatureFlagRepository` / PostgreSQL adapter | `FeatureFlagService` deterministic evaluation; `FeatureFlagManager` control plane | `GET/PUT/DELETE /api/core/feature-flags*` | `ConfigurationRuntimeCest`, `ConfigurationAuthorizationCest`, `FeatureFlagServiceTest` | secret-like config keys forbidden | CONTROL PLANE API + INTERNAL SERVICE |
| `core.system_settings` | `SystemSettingsRepository` / PostgreSQL adapter | `SettingsService`, atomic upsert/delete and public-only selection | `GET/PUT/DELETE /api/core/settings/system*`; `GET /api/public/config` | `ConfigurationRuntimeCest`, `ConfigurationAuthorizationCest` | secret-like keys forbidden; `is_public` enforced | CONTROL PLANE API + PUBLIC FILTERED CONFIG |
| `core.audit_events` | `AuditWriter` / `PostgresAuditWriter` | centralized append-only `AuditService` | none in this phase | `AuditMessagingPersistenceCest` | safe snapshots, IP/user agent | INTERNAL SERVICE |
| `core.outbox_events` | `OutboxRepository` / PostgreSQL adapter | enqueue, claim, publish/fail via `OutboxPublisher` | none | `AuditMessagingPersistenceCest` incl. rollback | internal payload/headers/errors | INFRASTRUCTURE ONLY |
| `core.inbox_events` | `InboxRepository` / PostgreSQL adapter | idempotency via `InboxConsumerGuard` | none | `AuditMessagingPersistenceCest` | internal processing errors | INFRASTRUCTURE ONLY |

## Примечания о покрытии

- Адаптер Auth предоставляет доступ к чувствительным записям только через внутренние интерфейсы; ни один presenter не преобразует их в HTTP-данные.
- Назначения RBAC и configuration control plane защищены централизованными authentication/permission middleware; `core.superadmin` использует существующий централизованный bypass.
- Сервисы модулей/настроек работают только с метаданными и не зависят от Education, Games, Chat, Search, Storage или других необязательных модулей.
- Атомарность Outbox обеспечивается внедрённым подключением Yii DB внутри транзакции вызывающего кода. Это поведение подтверждает интеграционный тест отката.
- Проверки сопоставления репозиториев и статического анализа для редко используемых запросов Auth дополняют интеграционные тесты жизненного цикла; у каждой таблицы определён владелец, случайно открытых API нет.
- Миграция `013_external_identity_mfa_runtime.sql` расширяет существующие TOTP/recovery таблицы,
  добавляет provider seed и partial unique index; новых таблиц нет, число таблиц Core остаётся равным 23.
