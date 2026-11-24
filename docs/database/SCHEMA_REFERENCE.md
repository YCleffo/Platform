# Справочник фактической базы данных

Сгенерировано `scripts/documentation/build-reference.py` из `schema.catalog.json`.
Только фактические таблицы, столбцы и SQL FK. Источник структуры — применённые миграции PostgreSQL.
Проверки CHECK, частичные индексы и индексы выражений, триггерные функции и отложенные ограничения полностью сохранены в JSON-каталогах;
DBML служит представлением для диаграммы, а не исполняемой заменой миграций.

## Инвентарь

| Схема | Таблицы | Столбцы | Внешние ключи | Проверки CHECK | Индексы | Пользовательские триггеры |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `core` | 50 | 561 | 86 | 195 | 161 | 19 |
| `platform_runtime` | 3 | 36 | 1 | 18 | 7 | 2 |
| `commercial` | 62 | 619 | 98 | 273 | 177 | 53 |
| `education` | 58 | 517 | 85 | 125 | 156 | 11 |

Технические `public.platform_migrations` и `public.doctrine_migration_versions` не являются бизнес-таблицами и не включены в инвентарь.
Межсистемный идентификатор в подписанном документе не создаёт внешний ключ между базами.

## Как читать поля

«NULL: да» означает допустимый SQL NULL. Прочерк в значении по умолчанию означает отсутствие SQL DEFAULT.
`bytea` — хеш, подпись, открытый ключ или зашифрованные данные в зависимости от поля.
`timestamp with time zone` хранит момент времени, `amount_minor` — целые минимальные единицы валюты.
SQL-комментарии приводятся из PostgreSQL; отсутствие комментария не заменяется догадкой.

## core.account_roles

Текущие назначения ролей аккаунтам.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `account_id` | `uuid` | нет | — | — |
| `role_id` | `uuid` | нет | — | — |
| `assigned_at` | `timestamp with time zone` | нет | now() | — |
| `assigned_by_account_id` | `uuid` | да | — | Аккаунт, назначивший роль; NULL для начального заполнения или системной операции. |
| `expires_at` | `timestamp with time zone` | да | — | Необязательный срок назначения; NULL означает бессрочность. |

**Первичный ключ:** `PRIMARY KEY (account_id, role_id)`.

**Реальные внешние ключи:**

- `fk_account_roles_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.
- `fk_account_roles_assigned_by`: `FOREIGN KEY (assigned_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.
- `fk_account_roles_role`: `FOREIGN KEY (role_id) REFERENCES core.roles(id) ON DELETE CASCADE`.

Проверок CHECK: 0; индексов: 3; пользовательских триггеров: 0.

## core.account_settings

Разреженные пользовательские переопределения настроек. Действующие значения вычисляет версионированный реестр схемы Core.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `account_id` | `uuid` | нет | — | — |
| `settings` | `jsonb` | нет | '{}'::jsonb | Только разрешённые разреженные переопределения; значения сброса null не сохраняются. |
| `schema_version` | `integer` | нет | 1 | Версия реестра настроек, применённая для проверки сохранённого документа. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (account_id)`.

**Реальные внешние ключи:**

- `account_settings_account_id_fkey`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

Проверок CHECK: 4; индексов: 1; пользовательских триггеров: 0.

## core.accounts

Учётные записи системы. Человеческие связаны с Person; сервисные и системные могут не иметь этой связи.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `person_id` | `uuid` | да | — | Account → Person. Уникальная связь: в Core v1 у Person не более одного Account. |
| `account_type` | `core.account_type` | нет | 'human'::core.account_type | Тип аккаунта: human — человеческий, service — сервисный, system — системный. |
| `status` | `core.account_status` | нет | 'pending_verification'::core.account_status | Жизненный цикл и статус аккаунта. |
| `label` | `character varying(160)` | да | — | — |
| `security_stamp` | `uuid` | нет | uuidv4() | Случайная версия безопасности. Замена делает старые сессии недействительными. |
| `locked_until` | `timestamp with time zone` | да | — | — |
| `last_login_at` | `timestamp with time zone` | да | — | — |
| `created_by_account_id` | `uuid` | да | — | Ссылка на эту же таблицу: аккаунт, создавший данный аккаунт. |
| `approved_by_account_id` | `uuid` | да | — | Ссылка на эту же таблицу: аккаунт, подтвердивший данный аккаунт. |
| `approved_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `disabled_at` | `timestamp with time zone` | да | — | — |
| `archived_at` | `timestamp with time zone` | да | — | — |
| `locale` | `character varying(35)` | да | — | Необязательный сохранённый канонический язык по BCP 47. |
| `timezone` | `character varying(64)` | да | — | Необязательный сохранённый часовой пояс IANA. |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_accounts_approved_by`: `FOREIGN KEY (approved_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.
- `fk_accounts_created_by`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.
- `fk_accounts_person`: `FOREIGN KEY (person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 5; пользовательских триггеров: 1.

## core.audit_events

Неизменяемый аудит бизнеса и безопасности: кто, что и когда изменил. Не техническая телеметрия.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `bigint` | нет | IDENTITY BY DEFAULT | — |
| `event_id` | `uuid` | нет | uuidv7() | — |
| `occurred_at` | `timestamp with time zone` | нет | now() | — |
| `actor_type` | `core.audit_actor_type` | нет | — | — |
| `actor_account_id` | `uuid` | да | — | Аккаунт исполнителя, где применимо; SET NULL сохраняет аудит после удаления аккаунта. |
| `action` | `character varying(180)` | нет | — | Стабильный код действия в пространстве имён. |
| `resource_type` | `character varying(180)` | да | — | Полиморфный тип ресурса, например core.account или education.lesson. |
| `resource_id` | `character varying(160)` | да | — | ID ресурса в текстовом виде для полиморфной ссылки аудита. |
| `request_id` | `uuid` | да | — | — |
| `correlation_id` | `uuid` | да | — | — |
| `ip_address` | `inet` | да | — | — |
| `user_agent` | `text` | да | — | — |
| `success` | `boolean` | нет | true | — |
| `error_code` | `character varying(120)` | да | — | — |
| `before_data` | `jsonb` | да | — | Только относящееся к действию безопасное прежнее состояние или различие; без секретов. |
| `after_data` | `jsonb` | да | — | Только относящееся к действию безопасное новое состояние или различие; без секретов. |
| `metadata` | `jsonb` | нет | '{}'::jsonb | Дополнительные безопасные метаданные. Пароли, токены, секреты TOTP и закрытые ключи запрещены. |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_audit_events_actor`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

Проверок CHECK: 1; индексов: 7; пользовательских триггеров: 1.

## core.auth_challenges

Краткоживущие одноразовые проверочные запросы: подтверждение, сброс, приглашение, вход по ссылке и другие.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | да | — | — |
| `challenge_type` | `character varying(80)` | нет | — | Расширяемый идентификатор сценария проверочного запроса. |
| `secret_hash` | `bytea` | нет | — | Хеш одноразового секрета или токена. |
| `target_normalized` | `character varying(320)` | да | — | — |
| `payload` | `jsonb` | нет | '{}'::jsonb | Безопасные данные JSON конкретного сценария; секреты здесь запрещены. |
| `attempts` | `smallint` | нет | 0 | — |
| `max_attempts` | `smallint` | нет | 5 | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `cancelled_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_auth_challenges_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

Проверок CHECK: 5; индексов: 4; пользовательских триггеров: 0.

## core.auth_external_identities

Связи локальных аккаунтов со стабильными субъектами внешних провайдеров.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `provider_id` | `uuid` | нет | — | — |
| `provider_subject` | `character varying(255)` | нет | — | Стабильный идентификатор пользователя от провайдера, например OIDC sub. |
| `email` | `character varying(320)` | да | — | Почта от провайдера; автоматическое объединение аккаунтов только по ней запрещено. |
| `email_verified` | `boolean` | нет | false | — |
| `display_name` | `character varying(255)` | да | — | — |
| `avatar_url` | `text` | да | — | — |
| `claims` | `jsonb` | нет | '{}'::jsonb | Дополнительные несекретные утверждения провайдера. |
| `linked_at` | `timestamp with time zone` | нет | now() | — |
| `last_login_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | Время создания связи аккаунта с внешним субъектом; для прежних строк повторяет linked_at. |
| `updated_at` | `timestamp with time zone` | нет | now() | Последнее локальное изменение внешней связи; исходное содержимое провайдера не сохраняется. |
| `last_used_at` | `timestamp with time zone` | да | — | Последнее успешное использование после проверки провайдера; для провайдеров входа повторяет last_login_at. |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_external_identities_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.
- `fk_external_identities_provider`: `FOREIGN KEY (provider_id) REFERENCES core.auth_providers(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 7; пользовательских триггеров: 0.

## core.auth_identifiers

Идентификаторы входа и восстановления аккаунта: имя пользователя, почта или телефон.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | Связь auth_identifiers → accounts. |
| `identifier_type` | `core.auth_identifier_type` | нет | — | — |
| `value` | `character varying(320)` | нет | — | — |
| `normalized_value` | `character varying(320)` | нет | — | Каноническая нормализованная форма для уникального поиска. |
| `is_primary` | `boolean` | нет | false | — |
| `can_login` | `boolean` | нет | true | Можно ли использовать идентификатор для входа. |
| `can_recover` | `boolean` | нет | false | Может ли политика восстановления использовать идентификатор. |
| `verified_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_auth_identifiers_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

Проверок CHECK: 5; индексов: 5; пользовательских триггеров: 0.

## core.auth_passkeys

Учётные данные WebAuthn и ключей доступа. Один аккаунт может иметь несколько ключей.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `credential_id` | `bytea` | нет | — | Двоичный идентификатор учётных данных WebAuthn. |
| `public_key` | `bytea` | нет | — | Открытый ключ; закрытый ключ никогда не передаётся серверу. |
| `sign_count` | `bigint` | нет | 0 | — |
| `aaguid` | `uuid` | да | — | — |
| `transports` | `text[]` | нет | ARRAY[]::text[] | Известные транспорты аутентификатора, например internal, usb, nfc, ble. |
| `label` | `character varying(160)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `last_used_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `backup_eligible` | `boolean` | нет | false | Флаг WebAuthn Level 3: учётные данные допускают облачную синхронизацию. |
| `backup_state` | `boolean` | нет | false | Флаг WebAuthn Level 3: учётные данные сейчас сохранены в облачной связке ключей. |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_auth_passkeys_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

Проверок CHECK: 4; индексов: 4; пользовательских триггеров: 0.

## core.auth_password_credentials

Необязательные данные пароля аккаунта. Ноль или одна строка на аккаунт.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `password_hash` | `text` | нет | — | Только хеш пароля. Хранить открытые пароли запрещено. |
| `password_algorithm` | `character varying(32)` | нет | — | Информационный идентификатор алгоритма, например argon2id. |
| `must_change_password` | `boolean` | нет | false | Обязательная смена пароля при следующем подходящем входе. |
| `password_changed_at` | `timestamp with time zone` | нет | now() | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_auth_password_credentials_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

Проверок CHECK: 2; индексов: 2; пользовательских триггеров: 0.

## core.auth_providers

Реестр внешних провайдеров аутентификации: OIDC, OAuth и собственные адаптеры.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(50)` | нет | — | Стабильный код провайдера, например google, telegram, microsoft. |
| `protocol` | `character varying(50)` | нет | — | Расширяемое имя протокола; намеренно VARCHAR вместо ENUM. |
| `display_name` | `character varying(120)` | нет | — | — |
| `enabled` | `boolean` | нет | false | Переключатель доступности провайдера. |
| `allow_login` | `boolean` | нет | true | — |
| `allow_registration` | `boolean` | нет | false | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 3; индексов: 3; пользовательских триггеров: 0.

## core.auth_recovery_codes

Одноразовые резервные коды MFA; хранятся только криптографические хеши.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `code_hash` | `bytea` | нет | — | Хеш резервного кода; исходный код не сохраняется. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `used_at` | `timestamp with time zone` | да | — | NULL до использования. |
| `revoked_at` | `timestamp with time zone` | да | — | Время отзыва при обновлении кодов, отключении MFA и сбросе восстановления. |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_auth_recovery_codes_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

Проверок CHECK: 1; индексов: 5; пользовательских триггеров: 0.

## core.auth_sessions

Активные и прежние сессии. Непрозрачные сессионные токены хранятся только как хеши.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `session_token_hash` | `bytea` | нет | — | Хеш непрозрачного токена сессии. |
| `refresh_token_hash` | `bytea` | да | — | Необязательный хеш токена обновления для клиентов с обновлением сессии. |
| `security_stamp` | `uuid` | нет | — | Снимок accounts.security_stamp при создании сессии. |
| `authentication_method` | `character varying(80)` | нет | — | Расширяемый способ аутентификации: пароль, ключ доступа, код провайдера и другие. |
| `mfa_verified_at` | `timestamp with time zone` | да | — | — |
| `client_type` | `character varying(40)` | нет | — | Расширяемый тип клиента: web, mobile, agent и другие. |
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
| `client_instance_id` | `uuid` | да | — | Необязательный случайный UUID экземпляра интерфейса; не фактор аутентификации. |
| `device_fingerprint_hash` | `bytea` | да | — | Сигнал риска HMAC-SHA-256; не фактор аутентификации. |
| `geo_country_code` | `character(2)` | да | — | Примерная страна по IP в формате ISO 3166-1 alpha-2. |
| `geo_region` | `character varying(160)` | да | — | — |
| `geo_city` | `character varying(160)` | да | — | Примерный город по IP; не точное местоположение. |
| `geo_timezone` | `character varying(64)` | да | — | — |
| `access_expires_at` | `timestamp with time zone` | нет | — | Срок текущего краткоживущего непрозрачного токена доступа; expires_at — абсолютный срок сессии. |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_auth_sessions_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

Проверок CHECK: 13; индексов: 7; пользовательских триггеров: 0.

## core.auth_totp_factors

Факторы TOTP MFA. Секрет зашифрован; ключ шифрования находится вне БД.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `label` | `character varying(160)` | да | — | — |
| `secret_encrypted` | `bytea` | нет | — | Зашифрованный секрет TOTP. |
| `algorithm` | `core.totp_algorithm` | нет | 'sha1'::core.totp_algorithm | — |
| `digits` | `smallint` | нет | 6 | — |
| `period_seconds` | `smallint` | нет | 30 | — |
| `verified_at` | `timestamp with time zone` | да | — | NULL до подтверждения подключения действующим кодом TOTP. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `last_used_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `last_used_counter` | `bigint` | да | — | Последний принятый счётчик шага RFC 6238. Каждый счётчик принимается только один раз. |
| `secret_key_version` | `smallint` | нет | 1 | Версия внешнего ключа шифрования secret_encrypted. |
| `setup_expires_at` | `timestamp with time zone` | да | — | Срок незавершённого неподтверждённого подключения TOTP. |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_auth_totp_factors_account`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE CASCADE`.

Проверок CHECK: 7; индексов: 5; пользовательских триггеров: 0.

## core.data_subject_request_deadlines

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `data_subject_request_deadlines_request_id_fkey`: `FOREIGN KEY (request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.

Проверок CHECK: 6; индексов: 3; пользовательских триггеров: 1.

## core.data_subject_request_events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `request_id` | `uuid` | нет | — | — |
| `event_code` | `character varying(120)` | нет | — | — |
| `from_status` | `character varying(32)` | да | — | — |
| `to_status` | `character varying(32)` | да | — | — |
| `actor_account_id` | `uuid` | нет | — | — |
| `reason_code` | `character varying(120)` | да | — | — |
| `occurred_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `data_subject_request_events_actor_account_id_fkey`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `data_subject_request_events_request_id_fkey`: `FOREIGN KEY (request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 2; пользовательских триггеров: 1.

## core.data_subject_request_executions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `data_subject_request_executions_actor_account_id_fkey`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `data_subject_request_executions_erasure_case_id_fkey`: `FOREIGN KEY (erasure_case_id) REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT`.
- `data_subject_request_executions_request_id_fkey`: `FOREIGN KEY (request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.

Проверок CHECK: 10; индексов: 3; пользовательских триггеров: 1.

## core.data_subject_requests

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `data_subject_requests_legal_representation_id_fkey`: `FOREIGN KEY (legal_representation_id) REFERENCES core.legal_representations(id) ON DELETE RESTRICT`.
- `data_subject_requests_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `data_subject_requests_submitted_by_account_id_fkey`: `FOREIGN KEY (submitted_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `data_subject_requests_submitted_by_person_id_fkey`: `FOREIGN KEY (submitted_by_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 6; индексов: 5; пользовательских триггеров: 1.

## core.feature_flags

Общие и модульные флаги возможностей с процентом постепенного включения.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `module_id` | `uuid` | да | — | Модуль-владелец; NULL означает общий флаг установки. |
| `code` | `character varying(160)` | нет | — | — |
| `enabled` | `boolean` | нет | false | — |
| `rollout_percent` | `smallint` | нет | 100 | Целый процент включения от 0 до 100. |
| `config` | `jsonb` | нет | '{}'::jsonb | — |
| `description` | `text` | да | — | — |
| `updated_by_account_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_feature_flags_module`: `FOREIGN KEY (module_id) REFERENCES core.modules(id) ON DELETE CASCADE`.
- `fk_feature_flags_updated_by`: `FOREIGN KEY (updated_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 0.

## core.inbox_events

Журнал идемпотентности потребителя. event_id + consumer уникальны.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `bigint` | нет | IDENTITY BY DEFAULT | — |
| `event_id` | `uuid` | нет | — | — |
| `consumer` | `character varying(180)` | нет | — | Стабильный уникальный код обработчика-потребителя. |
| `status` | `core.inbox_status` | нет | 'received'::core.inbox_status | — |
| `attempts` | `integer` | нет | 0 | — |
| `received_at` | `timestamp with time zone` | нет | now() | — |
| `processed_at` | `timestamp with time zone` | да | — | Время успешной обработки. |
| `locked_by` | `character varying(160)` | да | — | — |
| `locked_until` | `timestamp with time zone` | да | — | Срок аренды блокировки обработчика-потребителя. |
| `last_error` | `text` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 2; индексов: 4; пользовательских триггеров: 0.

## core.legal_representation_scopes

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `representation_id` | `uuid` | нет | — | — |
| `scope_code` | `character varying(120)` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (representation_id, scope_code)`.

**Реальные внешние ключи:**

- `legal_representation_scopes_representation_id_fkey`: `FOREIGN KEY (representation_id) REFERENCES core.legal_representations(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 1.

## core.legal_representations

Проверенное общее полномочие действовать за другого Person; сканы документов здесь не хранятся.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `legal_representations_representative_person_id_fkey`: `FOREIGN KEY (representative_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `legal_representations_revoked_by_account_id_fkey`: `FOREIGN KEY (revoked_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `legal_representations_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `legal_representations_verified_by_account_id_fkey`: `FOREIGN KEY (verified_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 10; индексов: 3; пользовательских триггеров: 2.

## core.module_settings

Несекретные настройки конкретного модуля.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `module_id` | `uuid` | нет | — | — |
| `setting_key` | `character varying(160)` | нет | — | Ключ настройки, уникальный в модуле. |
| `value` | `jsonb` | нет | — | Значение настройки JSONB. Секреты здесь запрещены. |
| `description` | `text` | да | — | — |
| `updated_by_account_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_module_settings_module`: `FOREIGN KEY (module_id) REFERENCES core.modules(id) ON DELETE CASCADE`.
- `fk_module_settings_updated_by`: `FOREIGN KEY (updated_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

Проверок CHECK: 3; индексов: 2; пользовательских триггеров: 0.

## core.modules

Реестр установленных и известных модулей. Core знает только метаданные, а не внутреннюю реализацию.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(100)` | нет | — | Стабильный код модуля, например education, games, chat. |
| `name` | `character varying(160)` | нет | — | — |
| `description` | `text` | да | — | — |
| `version` | `character varying(40)` | нет | — | — |
| `settings_schema_version` | `integer` | нет | 1 | Версия схемы настроек модуля. |
| `enabled` | `boolean` | нет | false | — |
| `installed_at` | `timestamp with time zone` | нет | now() | — |
| `enabled_at` | `timestamp with time zone` | да | — | — |
| `disabled_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 5; индексов: 3; пользовательских триггеров: 0.

## core.organization_memberships

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `status` | `character varying(20)` | нет | 'active'::character varying | — |
| `joined_at` | `timestamp with time zone` | нет | now() | — |
| `left_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `organization_memberships_organization_id_fkey`: `FOREIGN KEY (organization_id) REFERENCES core.organizations(id) ON DELETE RESTRICT`.
- `organization_memberships_person_id_fkey`: `FOREIGN KEY (person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 3; индексов: 3; пользовательских триггеров: 0.

## core.organizations

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(120)` | нет | — | — |
| `name` | `character varying(255)` | нет | — | — |
| `status` | `character varying(20)` | нет | 'active'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `archived_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 4; индексов: 2; пользовательских триггеров: 0.

## core.outbox_events

Транзакционная исходящая очередь. Бизнес-состояние и событие фиксируются в одной транзакции PostgreSQL.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `bigint` | нет | IDENTITY BY DEFAULT | — |
| `event_id` | `uuid` | нет | uuidv7() | — |
| `producer_module` | `character varying(100)` | нет | — | Модуль, создавший событие. |
| `event_type` | `character varying(220)` | нет | — | Стабильный тип события в пространстве имён. |
| `aggregate_type` | `character varying(160)` | да | — | — |
| `aggregate_id` | `character varying(160)` | да | — | — |
| `payload` | `jsonb` | нет | — | — |
| `headers` | `jsonb` | нет | '{}'::jsonb | — |
| `correlation_id` | `uuid` | да | — | — |
| `causation_id` | `uuid` | да | — | — |
| `status` | `core.outbox_status` | нет | 'pending'::core.outbox_status | — |
| `attempts` | `integer` | нет | 0 | — |
| `occurred_at` | `timestamp with time zone` | нет | now() | — |
| `available_at` | `timestamp with time zone` | нет | now() | Самое раннее время, когда обработчик публикации может начать доставку. |
| `published_at` | `timestamp with time zone` | да | — | — |
| `locked_by` | `character varying(160)` | да | — | — |
| `locked_until` | `timestamp with time zone` | да | — | Срок аренды блокировки обработчика. |
| `last_error` | `text` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 3; индексов: 5; пользовательских триггеров: 0.

## core.permissions

Общий каталог кодов разрешений RBAC.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `module_id` | `uuid` | да | — | Разрешение → модуль. NULL означает принадлежность разрешения самому Core. |
| `code` | `character varying(180)` | нет | — | Глобально уникальный код разрешения в пространстве имён. |
| `name` | `character varying(180)` | нет | — | — |
| `description` | `text` | да | — | — |
| `is_system` | `boolean` | нет | false | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_permissions_module`: `FOREIGN KEY (module_id) REFERENCES core.modules(id) ON DELETE SET NULL`.

Проверок CHECK: 3; индексов: 3; пользовательских триггеров: 0.

## core.person_contacts

Контакты физического лица Person. Не являются идентификаторами входа Account.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `person_id` | `uuid` | нет | — | Дочерняя запись → родитель: person_contacts.person_id → persons.id. |
| `contact_type` | `core.contact_type` | нет | — | Тип контакта: email — почта, phone — телефон. |
| `value` | `character varying(320)` | нет | — | Исходное отображаемое значение. |
| `normalized_value` | `character varying(320)` | нет | — | Нормализованное значение для сравнения и поиска. |
| `label` | `character varying(80)` | да | — | — |
| `is_primary` | `boolean` | нет | false | Признак основного контакта данного типа у Person. |
| `verified_at` | `timestamp with time zone` | да | — | Время подтверждения; NULL означает отсутствие подтверждения. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_person_contacts_person`: `FOREIGN KEY (person_id) REFERENCES core.persons(id) ON DELETE CASCADE`.

Проверок CHECK: 5; индексов: 5; пользовательских триггеров: 0.

## core.persons

Физические лица отдельно от аккаунтов. Базовая таблица Core v1, одна из 23 того этапа. Применены ограничения качества данных.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | Первичный ключ физического лица UUIDv7. |
| `status` | `core.person_status` | нет | 'active'::core.person_status | Статус Person: active — активен, inactive — неактивен, archived — архивирован. |
| `given_name` | `character varying(120)` | нет | — | Имя. |
| `family_name` | `character varying(120)` | нет | — | Фамилия. |
| `middle_name` | `character varying(120)` | да | — | Необязательное второе имя или отчество. |
| `display_name` | `character varying(255)` | да | — | Необязательное готовое отображаемое имя интерфейса. |
| `birth_date` | `date` | да | — | Дата рождения без времени. |
| `created_at` | `timestamp with time zone` | нет | now() | Время создания. |
| `updated_at` | `timestamp with time zone` | нет | now() | Время последнего изменения; пока задаётся приложением. |
| `archived_at` | `timestamp with time zone` | да | — | Время архивирования; NULL означает, что запись не архивирована. |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 5; индексов: 4; пользовательских триггеров: 0.

## core.privacy_cleanup_runs

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `category_code` | `character varying(120)` | нет | — | — |
| `deleted_count` | `integer` | нет | — | — |
| `policy_days` | `integer` | да | — | — |
| `executed_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 2; индексов: 1; пользовательских триггеров: 0.

## core.privacy_collection_notices

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_collection_notices_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_collection_notices_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 2; пользовательских триггеров: 0.

## core.privacy_consents

Свидетельства согласия субъекта и действующего лица; строки миграции 018 помечены как неклассифицированные прежние свидетельства.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `account_consents_account_id_fkey`: `FOREIGN KEY (granted_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_consents_granted_by_person_id_fkey`: `FOREIGN KEY (granted_by_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `privacy_consents_legal_representation_id_fkey`: `FOREIGN KEY (legal_representation_id) REFERENCES core.legal_representations(id) ON DELETE RESTRICT`.
- `privacy_consents_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 9; индексов: 5; пользовательских триггеров: 2.

## core.privacy_destruction_evidence

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_destruction_evidence_case_id_fkey`: `FOREIGN KEY (case_id) REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT`.
- `privacy_destruction_evidence_executor_account_id_fkey`: `FOREIGN KEY (executor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_destruction_evidence_item_id_fkey`: `FOREIGN KEY (item_id) REFERENCES core.privacy_erasure_items(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 1.

## core.privacy_distribution_consents

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_distribution_consents_granted_by_account_id_fkey`: `FOREIGN KEY (granted_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_distribution_consents_granted_by_person_id_fkey`: `FOREIGN KEY (granted_by_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.
- `privacy_distribution_consents_legal_representation_id_fkey`: `FOREIGN KEY (legal_representation_id) REFERENCES core.legal_representations(id) ON DELETE RESTRICT`.
- `privacy_distribution_consents_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 7; индексов: 2; пользовательских триггеров: 1.

## core.privacy_erasure_cases

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_erasure_cases_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_erasure_cases_source_request_id_fkey`: `FOREIGN KEY (source_request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.
- `privacy_erasure_cases_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 4; пользовательских триггеров: 0.

## core.privacy_erasure_item_purposes

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `item_id` | `uuid` | нет | — | — |
| `purpose_code` | `character varying(120)` | нет | — | — |
| `policy_code` | `character varying(120)` | нет | — | — |
| `trigger_code` | `character varying(120)` | нет | — | — |
| `triggered_at` | `timestamp with time zone` | да | — | — |
| `contributor_code` | `character varying(120)` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (item_id, purpose_code, contributor_code)`.

**Реальные внешние ключи:**

- `privacy_erasure_item_purposes_item_id_fkey`: `FOREIGN KEY (item_id) REFERENCES core.privacy_erasure_items(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 0.

## core.privacy_erasure_items

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_erasure_items_case_id_fkey`: `FOREIGN KEY (case_id) REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT`.

Проверок CHECK: 8; индексов: 3; пользовательских триггеров: 0.

## core.privacy_incident_categories

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `incident_id` | `uuid` | нет | — | — |
| `category_code` | `character varying(120)` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (incident_id, category_code)`.

**Реальные внешние ключи:**

- `privacy_incident_categories_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 0.

## core.privacy_incident_events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `incident_id` | `uuid` | нет | — | — |
| `event_code` | `character varying(120)` | нет | — | — |
| `from_status` | `character varying(24)` | да | — | — |
| `to_status` | `character varying(24)` | да | — | — |
| `reason_code` | `character varying(120)` | да | — | — |
| `actor_account_id` | `uuid` | нет | — | — |
| `occurred_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_incident_events_actor_account_id_fkey`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_incident_events_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 2; пользовательских триггеров: 1.

## core.privacy_incident_subject_scope

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `incident_id` | `uuid` | нет | — | — |
| `subject_person_id` | `uuid` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (incident_id, subject_person_id)`.

**Реальные внешние ключи:**

- `privacy_incident_subject_scope_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES core.privacy_incidents(id) ON DELETE RESTRICT`.
- `privacy_incident_subject_scope_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 0.

## core.privacy_incidents

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_incidents_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 7; индексов: 2; пользовательских триггеров: 0.

## core.privacy_legal_destruction_packages

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_legal_destruction_packages_case_id_fkey`: `FOREIGN KEY (case_id) REFERENCES core.privacy_erasure_cases(id) ON DELETE RESTRICT`.
- `privacy_legal_destruction_packages_evidence_id_fkey`: `FOREIGN KEY (evidence_id) REFERENCES core.privacy_destruction_evidence(id) ON DELETE CASCADE`.
- `privacy_legal_destruction_packages_executor_account_id_fkey`: `FOREIGN KEY (executor_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_legal_destruction_packages_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 3; пользовательских триггеров: 1.

## core.privacy_legal_destruction_signatures

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `package_id` | `uuid` | нет | — | — |
| `signed_document_reference` | `character varying(500)` | нет | — | — |
| `signature_reference` | `character varying(500)` | нет | — | — |
| `attested_by_account_id` | `uuid` | нет | — | — |
| `attested_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (package_id)`.

**Реальные внешние ключи:**

- `privacy_legal_destruction_signature_attested_by_account_id_fkey`: `FOREIGN KEY (attested_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_legal_destruction_signatures_package_id_fkey`: `FOREIGN KEY (package_id) REFERENCES core.privacy_legal_destruction_packages(id) ON DELETE CASCADE`.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 1.

## core.privacy_legal_holds

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_legal_holds_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_legal_holds_released_by_account_id_fkey`: `FOREIGN KEY (released_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_legal_holds_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 5; индексов: 3; пользовательских триггеров: 0.

## core.privacy_processing_blocks

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_processing_blocks_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_processing_blocks_released_by_account_id_fkey`: `FOREIGN KEY (released_by_account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `privacy_processing_blocks_source_request_id_fkey`: `FOREIGN KEY (source_request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.
- `privacy_processing_blocks_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 1.

## core.privacy_retention_decisions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_retention_decisions_legal_hold_id_fkey`: `FOREIGN KEY (legal_hold_id) REFERENCES core.privacy_legal_holds(id) ON DELETE RESTRICT`.
- `privacy_retention_decisions_source_request_id_fkey`: `FOREIGN KEY (source_request_id) REFERENCES core.data_subject_requests(id) ON DELETE RESTRICT`.
- `privacy_retention_decisions_subject_person_id_fkey`: `FOREIGN KEY (subject_person_id) REFERENCES core.persons(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 3; пользовательских триггеров: 1.

## core.role_permissions

Связь многие-ко-многим Role ↔ Permission.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `role_id` | `uuid` | нет | — | — |
| `permission_id` | `uuid` | нет | — | — |
| `granted_at` | `timestamp with time zone` | нет | now() | — |
| `granted_by_account_id` | `uuid` | да | — | Аккаунт, выдавший разрешение; NULL для начального заполнения или системной операции. |

**Первичный ключ:** `PRIMARY KEY (role_id, permission_id)`.

**Реальные внешние ключи:**

- `fk_role_permissions_granted_by`: `FOREIGN KEY (granted_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.
- `fk_role_permissions_permission`: `FOREIGN KEY (permission_id) REFERENCES core.permissions(id) ON DELETE CASCADE`.
- `fk_role_permissions_role`: `FOREIGN KEY (role_id) REFERENCES core.roles(id) ON DELETE CASCADE`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 0.

## core.roles

Роли RBAC. Роль — набор разрешений, а не отдельная сущность администратора.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(120)` | нет | — | Стабильный код роли в пространстве имён, например core.superadmin. |
| `name` | `character varying(160)` | нет | — | — |
| `description` | `text` | да | — | — |
| `is_system` | `boolean` | нет | false | Роль предоставлена системой. |
| `is_protected` | `boolean` | нет | false | Обычное управление не может свободно удалять или менять защищённые роли. |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 3; индексов: 3; пользовательских триггеров: 0.

## core.service_credentials

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `service_credentials_account_id_fkey`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id) ON DELETE RESTRICT`.
- `service_credentials_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

Проверок CHECK: 5; индексов: 4; пользовательских триггеров: 1.

## core.system_settings

Общие несекретные настройки установки: метаданные платформы, оформления и подобная конфигурация.

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `setting_key` | `character varying(160)` | нет | — | Глобально уникальный ключ общей настройки. |
| `value` | `jsonb` | нет | — | — |
| `is_public` | `boolean` | нет | false | Можно ли выдавать несекретную настройку через публичную или клиентскую конфигурацию. |
| `description` | `text` | да | — | — |
| `updated_by_account_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `fk_system_settings_updated_by`: `FOREIGN KEY (updated_by_account_id) REFERENCES core.accounts(id) ON DELETE SET NULL`.

Проверок CHECK: 3; индексов: 2; пользовательских триггеров: 0.

## platform_runtime.background_job_status

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (job_code)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 6; индексов: 1; пользовательских триггеров: 0.

## platform_runtime.entitlement_documents

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `installation_id` | `uuid` | нет | — | — |
| `document_version` | `integer` | нет | — | Версия формата подписанного документа; sequence упорядочивает снимки одной установки. |
| `sequence` | `bigint` | нет | — | — |
| `issued_at` | `timestamp with time zone` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `payload` | `jsonb` | нет | — | — |
| `signature` | `bytea` | нет | — | Байты подписи Ed25519. Закрытый ключ подписи в этой БД не хранится. |
| `signing_key_id` | `character varying(160)` | нет | — | — |
| `status` | `platform_runtime.entitlement_status` | нет | 'pending'::platform_runtime.entitlement_status | — |
| `applied_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `entitlement_documents_installation_id_fkey`: `FOREIGN KEY (installation_id) REFERENCES platform_runtime.installations(id) ON DELETE RESTRICT`.

Проверок CHECK: 7; индексов: 4; пользовательских триггеров: 1.

## platform_runtime.installations

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 5; индексов: 2; пользовательских триггеров: 1.

## commercial.audit_events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `audit_events_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 5; индексов: 3; пользовательских триггеров: 2.

## commercial.background_job_status

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (job_code)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 6; индексов: 1; пользовательских триггеров: 0.

## commercial.billing_profiles

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `billing_profiles_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 6; индексов: 3; пользовательских триггеров: 0.

## commercial.catalog_operators

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `control_user_id` | `uuid` | нет | — | — |
| `authorization_reference` | `text` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (control_user_id)`.

**Реальные внешние ключи:**

- `catalog_operators_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE CASCADE`.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 0.

## commercial.commercial_account_invitations

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `commercial_account_invitations_accepted_by_fkey`: `FOREIGN KEY (accepted_by) REFERENCES commercial.control_users(id)`.
- `commercial_account_invitations_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id)`.
- `commercial_account_invitations_invited_by_fkey`: `FOREIGN KEY (invited_by) REFERENCES commercial.control_users(id)`.

Проверок CHECK: 4; индексов: 5; пользовательских триггеров: 0.

## commercial.commercial_account_memberships

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `commercial_account_id` | `uuid` | нет | — | — |
| `role` | `character varying(24)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `commercial_account_memberships_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `commercial_account_memberships_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 3; пользовательских триггеров: 1.

## commercial.commercial_accounts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 6; индексов: 2; пользовательских триггеров: 3.

## commercial.control_auth_challenges

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `control_auth_challenges_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 5; индексов: 4; пользовательских триггеров: 0.

## commercial.control_session_refresh_history

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `token_hash` | `bytea` | нет | — | — |
| `control_session_id` | `uuid` | нет | — | — |
| `consumed_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (token_hash)`.

**Реальные внешние ключи:**

- `control_session_refresh_history_control_session_id_fkey`: `FOREIGN KEY (control_session_id) REFERENCES commercial.control_sessions(id) ON DELETE CASCADE`.

Проверок CHECK: 1; индексов: 2; пользовательских триггеров: 0.

## commercial.control_sessions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `control_sessions_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 9; индексов: 6; пользовательских триггеров: 0.

## commercial.control_user_email_verifications

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `token_hash` | `bytea` | нет | — | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `invalidated_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `control_user_email_verifications_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 3; индексов: 4; пользовательских триггеров: 0.

## commercial.control_user_password_resets

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `token_hash` | `bytea` | нет | — | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `invalidated_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `control_user_password_resets_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 3; индексов: 4; пользовательских триггеров: 0.

## commercial.control_user_recovery_codes

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `factor_id` | `uuid` | нет | — | — |
| `code_hash` | `bytea` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `consumed_at` | `timestamp with time zone` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `control_user_recovery_codes_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `control_user_recovery_codes_factor_id_fkey`: `FOREIGN KEY (factor_id) REFERENCES commercial.control_user_totp_factors(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 3; пользовательских триггеров: 0.

## commercial.control_user_totp_factors

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `control_user_totp_factors_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 3; индексов: 4; пользовательских триггеров: 0.

## commercial.control_users

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 4; индексов: 2; пользовательских триггеров: 1.

## commercial.deployment_domains

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `deployment_id` | `uuid` | нет | — | — |
| `kind` | `character varying(16)` | нет | — | — |
| `hostname` | `character varying(253)` | нет | — | — |
| `status` | `character varying(24)` | нет | 'pending'::character varying | — |
| `ownership_verified_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `disabled_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `deployment_domains_deployment_id_fkey`: `FOREIGN KEY (deployment_id) REFERENCES commercial.deployments(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 1.

## commercial.deployment_entitlement_documents

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `deployment_entitlement_documents_deployment_id_fkey`: `FOREIGN KEY (deployment_id) REFERENCES commercial.deployments(id) ON DELETE RESTRICT`.
- `deployment_entitlement_documents_signing_key_id_fkey`: `FOREIGN KEY (signing_key_id) REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 1.

## commercial.deployment_operations

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `deployment_operations_deployment_id_fkey`: `FOREIGN KEY (deployment_id) REFERENCES commercial.deployments(id) ON DELETE RESTRICT`.

Проверок CHECK: 5; индексов: 3; пользовательских триггеров: 0.

## commercial.deployments

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `deployments_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `deployments_current_release_id_fkey`: `FOREIGN KEY (current_release_id) REFERENCES commercial.releases(id) ON DELETE RESTRICT`.
- `deployments_desired_release_id_fkey`: `FOREIGN KEY (desired_release_id) REFERENCES commercial.releases(id) ON DELETE RESTRICT`.

Проверок CHECK: 6; индексов: 4; пользовательских триггеров: 2.

## commercial.external_compensation_attestations

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `external_compensation_attestations_payment_id_fkey`: `FOREIGN KEY (payment_id) REFERENCES commercial.payments(id) ON DELETE RESTRICT`.
- `external_compensation_attestations_plan_change_id_fkey`: `FOREIGN KEY (plan_change_id) REFERENCES commercial.subscription_plan_changes(id) ON DELETE RESTRICT`.
- `external_compensation_attestations_proposed_by_fkey`: `FOREIGN KEY (proposed_by) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `external_compensation_attestations_reviewed_by_fkey`: `FOREIGN KEY (reviewed_by) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 7; индексов: 3; пользовательских триггеров: 1.

## commercial.features

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(160)` | нет | — | — |
| `value_type` | `character varying(16)` | нет | — | — |
| `description` | `text` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `retired_at` | `timestamp with time zone` | да | — | — |
| `usage_mode` | `character varying(16)` | нет | 'none'::character varying | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 5; индексов: 2; пользовательских триггеров: 1.

## commercial.financial_operators

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `control_user_id` | `uuid` | нет | — | — |
| `authorization_reference` | `character varying(500)` | нет | — | — |
| `authorized_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (control_user_id)`.

**Реальные внешние ключи:**

- `financial_operators_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 1; пользовательских триггеров: 0.

## commercial.idempotency_keys

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `scope` | `character varying(120)` | нет | — | — |
| `idempotency_key` | `character varying(200)` | нет | — | — |
| `request_sha256` | `bytea` | нет | — | — |
| `status` | `character varying(24)` | нет | — | — |
| `result_reference` | `character varying(200)` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 5; индексов: 2; пользовательских триггеров: 1.

## commercial.invoice_lines

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `invoice_lines_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `invoice_lines_plan_price_id_fkey`: `FOREIGN KEY (plan_price_id) REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT`.
- `invoice_lines_subscription_item_id_fkey`: `FOREIGN KEY (subscription_item_id) REFERENCES commercial.subscription_items(id) ON DELETE RESTRICT`.

Проверок CHECK: 8; индексов: 4; пользовательских триггеров: 1.

## commercial.invoices

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `invoices_billing_profile_id_fkey`: `FOREIGN KEY (billing_profile_id) REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT`.
- `invoices_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `invoices_subscription_id_fkey`: `FOREIGN KEY (subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

Проверок CHECK: 12; индексов: 6; пользовательских триггеров: 1.

## commercial.issued_entitlement_documents

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `issued_entitlement_documents_license_id_fkey`: `FOREIGN KEY (license_id) REFERENCES commercial.licenses(id) ON DELETE RESTRICT`.
- `issued_entitlement_documents_signing_key_id_fkey`: `FOREIGN KEY (signing_key_id) REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 2; пользовательских триггеров: 1.

## commercial.license_installations

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `license_id` | `uuid` | нет | — | — |
| `installation_id` | `uuid` | нет | — | — |
| `activated_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (license_id, installation_id)`.

**Реальные внешние ключи:**

- `license_installations_license_id_fkey`: `FOREIGN KEY (license_id) REFERENCES commercial.licenses(id) ON DELETE RESTRICT`.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 1.

## commercial.licenses

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `licenses_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 6; индексов: 2; пользовательских триггеров: 2.

## commercial.maintenance_contracts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `license_id` | `uuid` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | нет | — | — |
| `status` | `character varying(24)` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `maintenance_contracts_license_id_fkey`: `FOREIGN KEY (license_id) REFERENCES commercial.licenses(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 3; пользовательских триггеров: 1.

## commercial.outbox_events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 8; индексов: 4; пользовательских триггеров: 1.

## commercial.payment_attempts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `payment_attempts_payment_id_fkey`: `FOREIGN KEY (payment_id) REFERENCES commercial.payments(id) ON DELETE RESTRICT`.
- `payment_attempts_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 6; индексов: 5; пользовательских триггеров: 1.

## commercial.payment_provider_accounts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 5; индексов: 1; пользовательских триггеров: 1.

## commercial.payment_provider_events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `payment_provider_events_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 7; индексов: 3; пользовательских триггеров: 1.

## commercial.payments

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `payments_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `payments_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 1.

## commercial.plan_features

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `plan_id` | `uuid` | нет | — | — |
| `feature_id` | `uuid` | нет | — | — |
| `value` | `jsonb` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (plan_id, feature_id)`.

**Реальные внешние ключи:**

- `plan_features_feature_id_fkey`: `FOREIGN KEY (feature_id) REFERENCES commercial.features(id) ON DELETE RESTRICT`.
- `plan_features_plan_id_fkey`: `FOREIGN KEY (plan_id) REFERENCES commercial.plans(id) ON DELETE RESTRICT`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 1.

## commercial.plan_prices

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `plan_id` | `uuid` | нет | — | — |
| `currency` | `character(3)` | нет | — | — |
| `amount_minor` | `bigint` | нет | — | — |
| `billing_period` | `character varying(24)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `plan_prices_plan_id_fkey`: `FOREIGN KEY (plan_id) REFERENCES commercial.plans(id) ON DELETE RESTRICT`.

Проверок CHECK: 3; индексов: 2; пользовательских триггеров: 1.

## commercial.plans

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(120)` | нет | — | — |
| `version` | `integer` | нет | — | — |
| `status` | `character varying(24)` | нет | 'draft'::character varying | — |
| `name` | `character varying(200)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `published_at` | `timestamp with time zone` | да | — | — |
| `retired_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 5; индексов: 2; пользовательских триггеров: 1.

## commercial.privacy_erasure_cases

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `status` | `character varying(32)` | нет | — | — |
| `reason_code` | `character varying(80)` | нет | — | — |
| `financial_evidence_count` | `integer` | нет | 0 | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `completed_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_erasure_cases_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 1; пользовательских триггеров: 1.

## commercial.privacy_export_events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `actor_id` | `uuid` | нет | — | — |
| `schema_version` | `smallint` | нет | 1 | — |
| `item_count` | `integer` | нет | — | — |
| `exported_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_export_events_actor_id_fkey`: `FOREIGN KEY (actor_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `privacy_export_events_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 1.

## commercial.privacy_holds

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `control_user_id` | `uuid` | нет | — | — |
| `reason_code` | `character varying(80)` | нет | — | — |
| `opened_at` | `timestamp with time zone` | нет | now() | — |
| `released_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_holds_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 2; пользовательских триггеров: 0.

## commercial.privacy_incident_events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `incident_id` | `uuid` | нет | — | — |
| `event_code` | `character varying(80)` | нет | — | — |
| `evidence_reference` | `character varying(500)` | да | — | — |
| `actor_user_id` | `uuid` | нет | — | — |
| `occurred_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_incident_events_actor_user_id_fkey`: `FOREIGN KEY (actor_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `privacy_incident_events_incident_id_fkey`: `FOREIGN KEY (incident_id) REFERENCES commercial.privacy_incidents(id) ON DELETE RESTRICT`.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 1.

## commercial.privacy_incidents

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_incidents_created_by_user_id_fkey`: `FOREIGN KEY (created_by_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 2; пользовательских триггеров: 0.

## commercial.privacy_lifecycle_runs

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `category` | `character varying(80)` | нет | — | — |
| `dry_run` | `boolean` | нет | — | — |
| `selected_count` | `integer` | нет | — | — |
| `deleted_count` | `integer` | нет | — | — |
| `executed_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 0.

## commercial.privacy_operators

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `control_user_id` | `uuid` | нет | — | — |
| `authorization_reference` | `character varying(500)` | нет | — | — |
| `authorized_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (control_user_id)`.

**Реальные внешние ключи:**

- `privacy_operators_control_user_id_fkey`: `FOREIGN KEY (control_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 0.

## commercial.privacy_subject_holds

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `billing_profile_id` | `uuid` | нет | — | — |
| `reason_code` | `character varying(80)` | нет | — | — |
| `opened_at` | `timestamp with time zone` | нет | now() | — |
| `released_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_subject_holds_billing_profile_id_fkey`: `FOREIGN KEY (billing_profile_id) REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 0.

## commercial.privacy_subject_requests

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `privacy_subject_requests_billing_profile_id_fkey`: `FOREIGN KEY (billing_profile_id) REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT`.
- `privacy_subject_requests_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `privacy_subject_requests_operator_user_id_fkey`: `FOREIGN KEY (operator_user_id) REFERENCES commercial.control_users(id) ON DELETE RESTRICT`.
- `privacy_subject_requests_receipt_id_fkey`: `FOREIGN KEY (receipt_id) REFERENCES commercial.receipts(id) ON DELETE RESTRICT`.

Проверок CHECK: 6; индексов: 2; пользовательских триггеров: 0.

## commercial.rate_limit_counters

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `scope` | `character varying(80)` | нет | — | — |
| `key_hash` | `bytea` | нет | — | — |
| `window_started_at` | `timestamp with time zone` | нет | — | — |
| `hits` | `integer` | нет | 1 | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (scope, key_hash, window_started_at)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 4; индексов: 2; пользовательских триггеров: 0.

## commercial.receipt_items

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `receipt_items_invoice_line_id_fkey`: `FOREIGN KEY (invoice_line_id) REFERENCES commercial.invoice_lines(id) ON DELETE RESTRICT`.
- `receipt_items_receipt_id_fkey`: `FOREIGN KEY (receipt_id) REFERENCES commercial.receipts(id) ON DELETE RESTRICT`.

Проверок CHECK: 5; индексов: 3; пользовательских триггеров: 2.

## commercial.receipts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `receipts_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `receipts_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `receipts_payment_attempt_id_fkey`: `FOREIGN KEY (payment_attempt_id) REFERENCES commercial.payment_attempts(id) ON DELETE RESTRICT`.
- `receipts_payment_id_fkey`: `FOREIGN KEY (payment_id) REFERENCES commercial.payments(id) ON DELETE RESTRICT`.
- `receipts_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.
- `receipts_refund_id_fkey`: `FOREIGN KEY (refund_id) REFERENCES commercial.refunds(id) ON DELETE RESTRICT`.

Проверок CHECK: 11; индексов: 6; пользовательских триггеров: 2.

## commercial.refund_items

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `refund_items_invoice_line_id_fkey`: `FOREIGN KEY (invoice_line_id) REFERENCES commercial.invoice_lines(id) ON DELETE RESTRICT`.
- `refund_items_refund_id_fkey`: `FOREIGN KEY (refund_id) REFERENCES commercial.refunds(id) ON DELETE RESTRICT`.

Проверок CHECK: 5; индексов: 3; пользовательских триггеров: 2.

## commercial.refunds

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `refunds_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `refunds_payment_id_fkey`: `FOREIGN KEY (payment_id) REFERENCES commercial.payments(id) ON DELETE RESTRICT`.
- `refunds_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 9; индексов: 6; пользовательских триггеров: 2.

## commercial.release_entitlements

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `release_id` | `uuid` | нет | — | — |
| `feature_id` | `uuid` | нет | — | — |
| `requirement` | `jsonb` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (release_id, feature_id)`.

**Реальные внешние ключи:**

- `release_entitlements_feature_id_fkey`: `FOREIGN KEY (feature_id) REFERENCES commercial.features(id) ON DELETE RESTRICT`.
- `release_entitlements_release_id_fkey`: `FOREIGN KEY (release_id) REFERENCES commercial.releases(id) ON DELETE RESTRICT`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 1.

## commercial.releases

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `releases_signing_key_id_fkey`: `FOREIGN KEY (signing_key_id) REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT`.

Проверок CHECK: 9; индексов: 2; пользовательских триггеров: 1.

## commercial.renewal_collection_states

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `renewal_collection_account_subscription_fk`: `FOREIGN KEY (commercial_account_id, subscription_id) REFERENCES commercial.subscriptions(commercial_account_id, id) ON DELETE RESTRICT`.
- `renewal_collection_invoice_identity_fk`: `FOREIGN KEY (commercial_account_id, subscription_id, invoice_id) REFERENCES commercial.invoices(commercial_account_id, subscription_id, id) ON DELETE RESTRICT`.
- `renewal_collection_states_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `renewal_collection_states_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `renewal_collection_states_subscription_id_fkey`: `FOREIGN KEY (subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 1.

## commercial.saved_payment_methods

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `saved_payment_methods_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `saved_payment_methods_created_from_payment_attempt_id_fkey`: `FOREIGN KEY (created_from_payment_attempt_id) REFERENCES commercial.payment_attempts(id) ON DELETE RESTRICT`.
- `saved_payment_methods_provider_account_id_fkey`: `FOREIGN KEY (provider_account_id) REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 5; индексов: 4; пользовательских триггеров: 1.

## commercial.service_credentials

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `service_credentials_deployment_id_fkey`: `FOREIGN KEY (deployment_id) REFERENCES commercial.deployments(id) ON DELETE RESTRICT`.

Проверок CHECK: 5; индексов: 4; пользовательских триггеров: 0.

## commercial.signing_keys

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (key_id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 8; индексов: 1; пользовательских триггеров: 2.

## commercial.subscription_items

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subscription_id` | `uuid` | нет | — | — |
| `plan_price_id` | `uuid` | нет | — | — |
| `quantity` | `integer` | нет | — | — |
| `valid_from` | `timestamp with time zone` | нет | — | — |
| `valid_until` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `subscription_items_plan_price_id_fkey`: `FOREIGN KEY (plan_price_id) REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT`.
- `subscription_items_subscription_id_fkey`: `FOREIGN KEY (subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

Проверок CHECK: 2; индексов: 3; пользовательских триггеров: 1.

## commercial.subscription_plan_changes

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `subscription_plan_changes_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_invoice_id_fkey`: `FOREIGN KEY (invoice_id) REFERENCES commercial.invoices(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_new_plan_price_id_fkey`: `FOREIGN KEY (new_plan_price_id) REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_old_plan_price_id_fkey`: `FOREIGN KEY (old_plan_price_id) REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_source_subscription_id_fkey`: `FOREIGN KEY (source_subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.
- `subscription_plan_changes_target_subscription_id_fkey`: `FOREIGN KEY (target_subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

Проверок CHECK: 11; индексов: 7; пользовательских триггеров: 1.

## commercial.subscriptions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
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

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `subscriptions_billing_profile_id_fkey`: `FOREIGN KEY (billing_profile_id) REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT`.
- `subscriptions_commercial_account_id_fkey`: `FOREIGN KEY (commercial_account_id) REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT`.

Проверок CHECK: 7; индексов: 6; пользовательских триггеров: 2.

## commercial.usage_counters

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `usage_period_id` | `uuid` | нет | — | — |
| `feature_id` | `uuid` | нет | — | — |
| `quantity` | `bigint` | нет | 0 | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (usage_period_id, feature_id)`.

**Реальные внешние ключи:**

- `usage_counters_feature_id_fkey`: `FOREIGN KEY (feature_id) REFERENCES commercial.features(id) ON DELETE RESTRICT`.
- `usage_counters_usage_period_id_fkey`: `FOREIGN KEY (usage_period_id) REFERENCES commercial.usage_periods(id) ON DELETE RESTRICT`.

Проверок CHECK: 1; индексов: 2; пользовательских триггеров: 1.

## commercial.usage_periods

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `subscription_id` | `uuid` | нет | — | — |
| `starts_at` | `timestamp with time zone` | нет | — | — |
| `ends_at` | `timestamp with time zone` | нет | — | — |
| `status` | `character varying(16)` | нет | 'open'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `closed_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `usage_periods_subscription_id_fkey`: `FOREIGN KEY (subscription_id) REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT`.

Проверок CHECK: 3; индексов: 3; пользовательских триггеров: 2.

## education.achievement_definitions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(80)` | нет | — | — |
| `rule_version` | `integer` | нет | — | — |
| `name` | `character varying(160)` | нет | — | — |
| `description` | `text` | нет | — | — |
| `rule` | `jsonb` | нет | — | — |
| `enabled` | `boolean` | нет | true | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 2; индексов: 2; пользовательских триггеров: 0.

## education.achievement_events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `source_type` | `character varying(20)` | нет | — | — |
| `source_id` | `uuid` | нет | — | — |
| `occurred_at` | `timestamp with time zone` | нет | — | — |
| `payload` | `jsonb` | нет | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `achievement_events_student_id_organization_id_fkey`: `FOREIGN KEY (student_id, organization_id) REFERENCES education.students(id, organization_id)`.

Проверок CHECK: 2; индексов: 4; пользовательских триггеров: 0.

## education.achievement_unlocks

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `definition_id` | `uuid` | нет | — | — |
| `source_event_id` | `uuid` | нет | — | — |
| `unlocked_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `achievement_unlocks_definition_id_fkey`: `FOREIGN KEY (definition_id) REFERENCES education.achievement_definitions(id)`.
- `achievement_unlocks_source_event_id_organization_id_studen_fkey`: `FOREIGN KEY (source_event_id, organization_id, student_id) REFERENCES education.achievement_events(id, organization_id, student_id)`.
- `achievement_unlocks_student_id_organization_id_fkey`: `FOREIGN KEY (student_id, organization_id) REFERENCES education.students(id, organization_id)`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 0.

## education.activity_assignments

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `activity_id` | `uuid` | нет | — | — |
| `group_id` | `uuid` | да | — | — |
| `student_id` | `uuid` | да | — | — |
| `config` | `jsonb` | нет | '{}'::jsonb | — |
| `opens_at` | `timestamp with time zone` | да | — | — |
| `due_at` | `timestamp with time zone` | да | — | — |
| `completion` | `jsonb` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `activity_assignments_activity_id_fkey`: `FOREIGN KEY (activity_id) REFERENCES education.activity_definitions(id)`.
- `activity_assignments_group_id_organization_id_owner_accoun_fkey`: `FOREIGN KEY (group_id, organization_id, owner_account_id) REFERENCES education.groups(id, organization_id, owner_account_id)`.
- `activity_assignments_student_id_organization_id_owner_acco_fkey`: `FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)`.

Проверок CHECK: 1; индексов: 2; пользовательских триггеров: 0.

## education.activity_attempts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `account_id` | `uuid` | нет | — | — |
| `activity_id` | `uuid` | нет | — | — |
| `assignment_id` | `uuid` | да | — | — |
| `status` | `character varying(20)` | нет | 'running'::character varying | — |
| `source` | `character varying(20)` | нет | 'browser'::character varying | — |
| `contract_version` | `integer` | нет | 1 | — |
| `exercise_text` | `text` | да | — | — |
| `started_at` | `timestamp with time zone` | нет | now() | — |
| `finished_at` | `timestamp with time zone` | да | — | — |
| `result_payload` | `jsonb` | да | — | — |
| `result_version` | `integer` | да | — | — |
| `config` | `jsonb` | нет | '{}'::jsonb | — |
| `exercise_payload` | `jsonb` | нет | '{}'::jsonb | — |
| `client_state` | `jsonb` | нет | '{}'::jsonb | — |
| `state_version` | `integer` | нет | 0 | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `activity_attempts_activity_id_fkey`: `FOREIGN KEY (activity_id) REFERENCES education.activity_definitions(id)`.
- `activity_attempts_assignment_id_fkey`: `FOREIGN KEY (assignment_id) REFERENCES education.activity_assignments(id)`.
- `activity_attempts_student_id_fkey`: `FOREIGN KEY (student_id) REFERENCES education.students(id)`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 0.

## education.activity_definitions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `code` | `character varying(60)` | нет | — | — |
| `name` | `character varying(160)` | нет | — | — |
| `kind` | `character varying(40)` | нет | — | — |
| `contract_version` | `integer` | нет | 1 | — |
| `enabled` | `boolean` | нет | true | — |
| `config` | `jsonb` | нет | '{}'::jsonb | — |
| `metrics` | `jsonb` | нет | '[]'::jsonb | — |
| `required_permission` | `character varying(180)` | нет | 'education.activities.use'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 3; индексов: 2; пользовательских триггеров: 0.

## education.activity_exercise_sets

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `code` | `character varying(80)` | нет | — | — |
| `version` | `integer` | нет | — | — |
| `activity_code` | `character varying(60)` | нет | — | — |
| `language` | `character varying(2)` | нет | — | — |
| `mode` | `character varying(20)` | нет | — | — |
| `difficulty` | `integer` | нет | — | — |
| `content` | `jsonb` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (code, version)`.

**Реальные внешние ключи:**

- `activity_exercise_sets_activity_code_fkey`: `FOREIGN KEY (activity_code) REFERENCES education.activity_definitions(code)`.

Проверок CHECK: 4; индексов: 2; пользовательских триггеров: 0.

## education.activity_results

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `attempt_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `type` | `character varying(20)` | нет | — | — |
| `version` | `integer` | нет | — | — |
| `request_hash` | `character(64)` | нет | — | — |
| `metrics` | `jsonb` | нет | — | — |
| `passed` | `boolean` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (attempt_id)`.

**Реальные внешние ключи:**

- `activity_results_attempt_id_organization_id_student_id_fkey`: `FOREIGN KEY (attempt_id, organization_id, student_id) REFERENCES education.activity_attempts(id, organization_id, student_id)`.

Проверок CHECK: 3; индексов: 2; пользовательских триггеров: 0.

## education.assessment_attempts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `version_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `assignment_id` | `uuid` | да | — | — |
| `state` | `character varying(30)` | нет | 'IN_PROGRESS'::character varying | — |
| `seed` | `bigint` | нет | 0 | — |
| `started_at` | `timestamp with time zone` | нет | now() | — |
| `submitted_at` | `timestamp with time zone` | да | — | — |
| `answers` | `jsonb` | нет | '{}'::jsonb | — |
| `result` | `jsonb` | нет | '{}'::jsonb | — |
| `score` | `integer` | да | — | — |
| `max_score` | `integer` | да | — | — |
| `percentage` | `numeric(5,2)` | да | — | — |
| `passed` | `boolean` | да | — | — |
| `manual_review_notes` | `text` | да | — | — |
| `reviewed_by_person_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `assessment_attempts_assignment_id_fkey`: `FOREIGN KEY (assignment_id) REFERENCES education.activity_assignments(id) ON DELETE SET NULL`.
- `assessment_attempts_student_id_fkey`: `FOREIGN KEY (student_id) REFERENCES education.students(id) ON DELETE CASCADE`.
- `assessment_attempts_version_id_fkey`: `FOREIGN KEY (version_id) REFERENCES education.assessment_versions(id) ON DELETE CASCADE`.

Проверок CHECK: 6; индексов: 2; пользовательских триггеров: 0.

## education.assessment_versions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `assessment_id` | `uuid` | нет | — | — |
| `version_number` | `integer` | нет | — | — |
| `title` | `character varying(200)` | нет | — | — |
| `instructions` | `text` | нет | ''::text | — |
| `time_limit_seconds` | `integer` | да | — | — |
| `passing_score_percent` | `integer` | нет | 60 | — |
| `max_attempts` | `integer` | нет | 1 | — |
| `shuffle_questions` | `boolean` | нет | false | — |
| `shuffle_options` | `boolean` | нет | false | — |
| `reveal_answers_policy` | `character varying(30)` | нет | 'after_grading'::character varying | — |
| `grading_policy` | `character varying(30)` | нет | 'highest'::character varying | — |
| `questions_snapshot` | `jsonb` | нет | '[]'::jsonb | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `assessment_versions_assessment_id_fkey`: `FOREIGN KEY (assessment_id) REFERENCES education.assessments(id) ON DELETE CASCADE`.

Проверок CHECK: 8; индексов: 3; пользовательских триггеров: 0.

## education.assessments

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `author_person_id` | `uuid` | нет | — | — |
| `title` | `character varying(200)` | нет | — | — |
| `description` | `text` | нет | ''::text | — |
| `status` | `character varying(20)` | нет | 'DRAFT'::character varying | — |
| `latest_version_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `assessments_latest_version_fkey`: `FOREIGN KEY (latest_version_id) REFERENCES education.assessment_versions(id) ON DELETE SET NULL`.

Проверок CHECK: 2; индексов: 2; пользовательских триггеров: 0.

## education.attendance

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `lesson_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `status` | `character varying(12)` | нет | 'pending'::character varying | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (lesson_id, student_id)`.

**Реальные внешние ключи:**

- `attendance_lesson_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (lesson_id, organization_id, owner_account_id) REFERENCES education.lessons(id, organization_id, owner_account_id)`.
- `attendance_student_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)`.

Проверок CHECK: 1; индексов: 1; пользовательских триггеров: 0.

## education.curriculum_lessons

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `module_id` | `uuid` | нет | — | — |
| `title` | `character varying(160)` | нет | — | — |
| `description` | `text` | нет | ''::text | — |
| `position` | `integer` | нет | — | — |
| `version` | `integer` | нет | 1 | — |
| `archived_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `curriculum_lessons_module_id_organization_id_fkey`: `FOREIGN KEY (module_id, organization_id) REFERENCES education.curriculum_modules(id, organization_id)`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 0.

## education.curriculum_modules

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `program_id` | `uuid` | нет | — | — |
| `name` | `character varying(160)` | нет | — | — |
| `description` | `text` | нет | ''::text | — |
| `position` | `integer` | нет | — | — |
| `version` | `integer` | нет | 1 | — |
| `archived_at` | `timestamp with time zone` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `curriculum_modules_program_id_organization_id_fkey`: `FOREIGN KEY (program_id, organization_id) REFERENCES education.programs(id, organization_id)`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 0.

## education.curriculum_revisions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `entity_type` | `character varying(20)` | нет | — | — |
| `entity_id` | `uuid` | нет | — | — |
| `version` | `integer` | нет | — | — |
| `payload` | `jsonb` | нет | — | — |
| `revised_by_account_id` | `uuid` | нет | — | — |
| `revised_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 1; индексов: 2; пользовательских триггеров: 0.

## education.events

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `owner_person_id` | `uuid` | нет | — | — |
| `title` | `character varying(160)` | нет | — | — |
| `kind` | `character varying(20)` | нет | — | — |
| `starts_at` | `timestamp with time zone` | нет | — | — |
| `ends_at` | `timestamp with time zone` | нет | — | — |
| `location` | `character varying(240)` | нет | ''::character varying | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 3; индексов: 2; пользовательских триггеров: 0.

## education.family_history

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `guardian_person_id` | `uuid` | нет | — | — |
| `actor_account_id` | `uuid` | да | — | — |
| `action` | `character varying(32)` | нет | — | — |
| `relationship` | `character varying(12)` | нет | — | — |
| `status` | `character varying(12)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `family_history_actor_account_id_fkey`: `FOREIGN KEY (actor_account_id) REFERENCES core.accounts(id)`.
- `family_history_guardian_person_id_fkey`: `FOREIGN KEY (guardian_person_id) REFERENCES core.persons(id)`.
- `family_history_student_id_organization_id_fkey`: `FOREIGN KEY (student_id, organization_id) REFERENCES education.students(id, organization_id)`.

Проверок CHECK: 2; индексов: 2; пользовательских триггеров: 1.

## education.file_transfers

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `account_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | да | — | — |
| `kind` | `character varying(16)` | нет | — | — |
| `resource_id` | `uuid` | да | — | — |
| `lesson_id` | `uuid` | да | — | — |
| `avatar` | `boolean` | нет | false | — |
| `name` | `character varying(240)` | нет | — | — |
| `expected_size` | `bigint` | нет | — | — |
| `expected_sha256` | `character(64)` | нет | — | — |
| `staging_key` | `character varying(160)` | нет | — | — |
| `final_key` | `uuid` | нет | — | — |
| `status` | `character varying(16)` | нет | 'pending'::character varying | — |
| `result` | `jsonb` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `expires_at` | `timestamp with time zone` | нет | (now() + '1 day'::interval) | — |
| `confirmed_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `file_transfers_account_id_fkey`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id)`.
- `file_transfers_organization_id_fkey`: `FOREIGN KEY (organization_id) REFERENCES core.organizations(id)`.
- `file_transfers_person_id_fkey`: `FOREIGN KEY (person_id) REFERENCES core.persons(id)`.

Проверок CHECK: 6; индексов: 4; пользовательских триггеров: 0.

## education.files

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `owner_person_id` | `uuid` | нет | — | — |
| `lesson_id` | `uuid` | да | — | — |
| `name` | `character varying(240)` | нет | — | — |
| `mime` | `character varying(100)` | нет | — | — |
| `purpose` | `character varying(12)` | нет | 'material'::character varying | — |
| `size_bytes` | `integer` | нет | — | — |
| `sha256` | `character(64)` | нет | — | — |
| `storage_key` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `files_lesson_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (lesson_id, organization_id, owner_account_id) REFERENCES education.lessons(id, organization_id, owner_account_id)`.

Проверок CHECK: 2; индексов: 3; пользовательских триггеров: 0.

## education.group_programs

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `group_id` | `uuid` | нет | — | — |
| `program_id` | `uuid` | нет | — | — |
| `program_version` | `integer` | нет | — | — |
| `snapshot` | `jsonb` | нет | — | — |
| `assigned_by_account_id` | `uuid` | нет | — | — |
| `assigned_at` | `timestamp with time zone` | нет | now() | — |
| `ended_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `group_programs_group_id_organization_id_fkey`: `FOREIGN KEY (group_id, organization_id) REFERENCES education.groups(id, organization_id)`.
- `group_programs_program_id_organization_id_fkey`: `FOREIGN KEY (program_id, organization_id) REFERENCES education.programs(id, organization_id)`.

Проверок CHECK: 1; индексов: 4; пользовательских триггеров: 0.

## education.group_students

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `group_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (group_id, student_id)`.

**Реальные внешние ключи:**

- `group_students_group_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (group_id, organization_id, owner_account_id) REFERENCES education.groups(id, organization_id, owner_account_id)`.
- `group_students_student_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 0.

## education.group_teachers

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `group_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `account_id` | `uuid` | нет | — | — |
| `assigned_by_account_id` | `uuid` | нет | — | — |
| `assigned_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (group_id, account_id)`.

**Реальные внешние ключи:**

- `group_teachers_group_id_organization_id_fkey`: `FOREIGN KEY (group_id, organization_id) REFERENCES education.groups(id, organization_id)`.
- `group_teachers_organization_id_account_id_fkey`: `FOREIGN KEY (organization_id, account_id) REFERENCES education.teachers(organization_id, account_id)`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 0.

## education.groups

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `owner_person_id` | `uuid` | нет | — | — |
| `name` | `character varying(160)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `archived_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 1; индексов: 3; пользовательских триггеров: 0.

## education.homework_assignments

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `author_account_id` | `uuid` | нет | — | — |
| `author_person_id` | `uuid` | нет | — | — |
| `title` | `character varying(160)` | нет | — | — |
| `description` | `text` | нет | ''::text | — |
| `instructions` | `text` | нет | ''::text | — |
| `type` | `character varying(12)` | нет | — | — |
| `group_id` | `uuid` | да | — | — |
| `lesson_id` | `uuid` | да | — | — |
| `due_at` | `timestamp with time zone` | да | — | — |
| `allow_late` | `boolean` | нет | false | — |
| `activity_code` | `character varying(60)` | да | — | — |
| `activity_config` | `jsonb` | нет | '{}'::jsonb | — |
| `max_attempts` | `integer` | нет | 3 | — |
| `attempt_policy` | `character varying(10)` | нет | 'latest'::character varying | — |
| `status` | `character varying(12)` | нет | 'DRAFT'::character varying | — |
| `version` | `integer` | нет | 1 | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `published_at` | `timestamp with time zone` | да | — | — |
| `closed_at` | `timestamp with time zone` | да | — | — |
| `archived_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `homework_assignments_activity_code_fkey`: `FOREIGN KEY (activity_code) REFERENCES education.activity_definitions(code)`.
- `homework_assignments_group_id_organization_id_fkey`: `FOREIGN KEY (group_id, organization_id) REFERENCES education.groups(id, organization_id)`.
- `homework_assignments_lesson_id_organization_id_fkey`: `FOREIGN KEY (lesson_id, organization_id) REFERENCES education.lessons(id, organization_id)`.

Проверок CHECK: 10; индексов: 4; пользовательских триггеров: 0.

## education.homework_attempt_links

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `attempt_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `recipient_id` | `uuid` | нет | — | — |
| `answer_sequence` | `integer` | нет | — | — |
| `linked_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (attempt_id)`.

**Реальные внешние ключи:**

- `homework_attempt_links_attempt_id_fkey`: `FOREIGN KEY (attempt_id) REFERENCES education.activity_attempts(id)`.
- `homework_attempt_links_recipient_id_fkey`: `FOREIGN KEY (recipient_id) REFERENCES education.homework_recipients(id)`.

Проверок CHECK: 1; индексов: 2; пользовательских триггеров: 1.

## education.homework_draft_files

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `recipient_id` | `uuid` | нет | — | — |
| `file_id` | `uuid` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (recipient_id, file_id)`.

**Реальные внешние ключи:**

- `homework_draft_files_file_id_fkey`: `FOREIGN KEY (file_id) REFERENCES education.homework_files(id)`.
- `homework_draft_files_recipient_id_fkey`: `FOREIGN KEY (recipient_id) REFERENCES education.homework_recipients(id)`.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 1.

## education.homework_files

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `assignment_id` | `uuid` | нет | — | — |
| `recipient_id` | `uuid` | да | — | — |
| `owner_person_id` | `uuid` | нет | — | — |
| `purpose` | `character varying(20)` | нет | — | — |
| `name` | `character varying(240)` | нет | — | — |
| `mime` | `character varying(100)` | нет | — | — |
| `size_bytes` | `integer` | нет | — | — |
| `sha256` | `character(64)` | нет | — | — |
| `storage_key` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `homework_files_assignment_id_fkey`: `FOREIGN KEY (assignment_id) REFERENCES education.homework_assignments(id)`.
- `homework_files_recipient_id_fkey`: `FOREIGN KEY (recipient_id) REFERENCES education.homework_recipients(id)`.

Проверок CHECK: 3; индексов: 4; пользовательских триггеров: 1.

## education.homework_idempotency

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `organization_id` | `uuid` | нет | — | — |
| `actor_account_id` | `uuid` | нет | — | — |
| `operation` | `character varying(16)` | нет | — | — |
| `idempotency_key` | `uuid` | нет | — | — |
| `resource_id` | `uuid` | нет | — | — |
| `request` | `jsonb` | нет | — | — |
| `response` | `jsonb` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (organization_id, actor_account_id, operation, idempotency_key)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 0.

## education.homework_recipients

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `assignment_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `assigned_at` | `timestamp with time zone` | нет | now() | — |
| `activity_assignment_id` | `uuid` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `homework_recipients_activity_assignment_id_fkey`: `FOREIGN KEY (activity_assignment_id) REFERENCES education.activity_assignments(id)`.
- `homework_recipients_assignment_id_organization_id_fkey`: `FOREIGN KEY (assignment_id, organization_id) REFERENCES education.homework_assignments(id, organization_id)`.
- `homework_recipients_student_id_organization_id_fkey`: `FOREIGN KEY (student_id, organization_id) REFERENCES education.students(id, organization_id)`.

Проверок CHECK: 0; индексов: 5; пользовательских триггеров: 1.

## education.homework_reviews

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `submission_id` | `uuid` | нет | — | — |
| `submission_version_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `teacher_account_id` | `uuid` | нет | — | — |
| `decision` | `character varying(10)` | нет | — | — |
| `result_status` | `character varying(20)` | да | — | — |
| `comment` | `text` | нет | ''::text | — |
| `created_at` | `timestamp with time zone` | нет | — | — |
| `idempotency_key` | `uuid` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `homework_reviews_submission_version_id_submission_id_organ_fkey`: `FOREIGN KEY (submission_version_id, submission_id, organization_id) REFERENCES education.homework_submission_versions(id, submission_id, organization_id)`.

Проверок CHECK: 4; индексов: 3; пользовательских триггеров: 1.

## education.homework_revisions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `assignment_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `version` | `integer` | нет | — | — |
| `action` | `character varying(30)` | нет | — | — |
| `payload` | `jsonb` | нет | — | — |
| `actor_account_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (assignment_id, version)`.

**Реальные внешние ключи:**

- `homework_revisions_assignment_id_organization_id_fkey`: `FOREIGN KEY (assignment_id, organization_id) REFERENCES education.homework_assignments(id, organization_id)`.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 1.

## education.homework_submission_versions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `submission_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `sequence` | `integer` | нет | — | — |
| `assignment_version` | `integer` | нет | — | — |
| `assignment_snapshot` | `jsonb` | нет | — | — |
| `text` | `text` | нет | — | — |
| `activity_attempt_id` | `uuid` | да | — | — |
| `submitted_at` | `timestamp with time zone` | нет | — | — |
| `late` | `boolean` | нет | — | — |
| `idempotency_key` | `uuid` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `homework_submission_versions_activity_attempt_id_fkey`: `FOREIGN KEY (activity_attempt_id) REFERENCES education.activity_attempts(id)`.
- `homework_submission_versions_submission_id_organization_id_fkey`: `FOREIGN KEY (submission_id, organization_id) REFERENCES education.homework_submissions(id, organization_id)`.

Проверок CHECK: 4; индексов: 5; пользовательских триггеров: 1.

## education.homework_submissions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `recipient_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `state` | `character varying(16)` | нет | 'IN_PROGRESS'::character varying | — |
| `version` | `integer` | нет | 1 | — |
| `draft_text` | `text` | нет | ''::text | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |
| `selected_activity_attempt_id` | `uuid` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `homework_submissions_recipient_id_organization_id_fkey`: `FOREIGN KEY (recipient_id, organization_id) REFERENCES education.homework_recipients(id, organization_id)`.
- `homework_submissions_selected_activity_attempt_id_fkey`: `FOREIGN KEY (selected_activity_attempt_id) REFERENCES education.activity_attempts(id)`.

Проверок CHECK: 3; индексов: 4; пользовательских триггеров: 0.

## education.homework_version_files

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `version_id` | `uuid` | нет | — | — |
| `file_id` | `uuid` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (version_id, file_id)`.

**Реальные внешние ключи:**

- `homework_version_files_file_id_fkey`: `FOREIGN KEY (file_id) REFERENCES education.homework_files(id)`.
- `homework_version_files_version_id_fkey`: `FOREIGN KEY (version_id) REFERENCES education.homework_submission_versions(id)`.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 1.

## education.lesson_reports

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `owner_person_id` | `uuid` | нет | — | — |
| `lesson_id` | `uuid` | нет | — | — |
| `topic` | `character varying(240)` | нет | — | — |
| `summary` | `text` | нет | — | — |
| `teacher_comment` | `text` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `lesson_reports_lesson_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (lesson_id, organization_id, owner_account_id) REFERENCES education.lessons(id, organization_id, owner_account_id)`.

Проверок CHECK: 3; индексов: 4; пользовательских триггеров: 0.

## education.lesson_results

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `lesson_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `status` | `character varying(20)` | нет | 'pending'::character varying | — |
| `comment` | `text` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `lesson_results_lesson_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (lesson_id, organization_id, owner_account_id) REFERENCES education.lessons(id, organization_id, owner_account_id)`.
- `lesson_results_student_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)`.

Проверок CHECK: 2; индексов: 3; пользовательских триггеров: 0.

## education.lessons

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `owner_person_id` | `uuid` | нет | — | — |
| `group_id` | `uuid` | нет | — | — |
| `title` | `character varying(160)` | нет | — | — |
| `starts_at` | `timestamp with time zone` | нет | — | — |
| `ends_at` | `timestamp with time zone` | нет | — | — |
| `location` | `character varying(240)` | нет | ''::character varying | — |
| `status` | `character varying(20)` | нет | 'scheduled'::character varying | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `completed_at` | `timestamp with time zone` | да | — | — |
| `cancelled_at` | `timestamp with time zone` | да | — | — |
| `group_program_id` | `uuid` | да | — | — |
| `curriculum_lesson_id` | `uuid` | да | — | — |
| `curriculum_snapshot` | `jsonb` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `lesson_curriculum_lesson_fk`: `FOREIGN KEY (curriculum_lesson_id, organization_id) REFERENCES education.curriculum_lessons(id, organization_id)`.
- `lesson_group_program_fk`: `FOREIGN KEY (group_program_id, organization_id) REFERENCES education.group_programs(id, organization_id)`.
- `lessons_group_id_organization_id_owner_account_id_fkey`: `FOREIGN KEY (group_id, organization_id, owner_account_id) REFERENCES education.groups(id, organization_id, owner_account_id)`.

Проверок CHECK: 4; индексов: 6; пользовательских триггеров: 1.

## education.logic_results

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `attempt_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `correct_answers` | `integer` | нет | — | — |
| `total_rounds` | `integer` | нет | — | — |
| `score` | `integer` | нет | — | — |
| `accuracy` | `numeric(5,2)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (attempt_id)`.

**Реальные внешние ключи:**

- `logic_results_attempt_id_fkey`: `FOREIGN KEY (attempt_id) REFERENCES education.activity_results(attempt_id)`.

Проверок CHECK: 5; индексов: 2; пользовательских триггеров: 0.

## education.message_reactions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `message_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `emoji` | `character varying(16)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `message_reactions_message_id_fkey`: `FOREIGN KEY (message_id) REFERENCES education.messages(id) ON DELETE CASCADE`.

Проверок CHECK: 0; индексов: 4; пользовательских триггеров: 0.

## education.messages

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `thread_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `sender_person_id` | `uuid` | нет | — | — |
| `body` | `text` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `client_key` | `character varying(128)` | да | NULL::character varying | — |
| `kind` | `character varying(20)` | нет | 'text'::character varying | — |
| `media_asset_id` | `uuid` | да | — | — |
| `reply_to_id` | `uuid` | да | — | — |
| `metadata` | `jsonb` | нет | '{}'::jsonb | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `messages_thread_id_organization_id_fkey`: `FOREIGN KEY (thread_id, organization_id) REFERENCES education.threads(id, organization_id)`.

Проверок CHECK: 1; индексов: 5; пользовательских триггеров: 0.

## education.module_migrations

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `name` | `text` | нет | — | — |
| `sha256` | `character(64)` | нет | — | — |
| `applied_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (name)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 0.

## education.notifications

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `thread_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `read_at` | `timestamp with time zone` | да | — | — |
| `message_id` | `uuid` | да | — | — |
| `kind` | `character varying(40)` | нет | 'message'::character varying | — |
| `resource_id` | `uuid` | да | — | — |
| `resource_path` | `character varying(240)` | да | — | — |
| `subject` | `character varying(160)` | да | — | — |
| `body` | `text` | да | — | — |
| `event_key` | `character varying(240)` | да | — | — |
| `parent_student_id` | `uuid` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `notifications_message_id_fkey`: `FOREIGN KEY (message_id) REFERENCES education.messages(id)`.
- `notifications_thread_id_fkey`: `FOREIGN KEY (thread_id) REFERENCES education.threads(id)`.
- `parent_notification_student`: `FOREIGN KEY (parent_student_id, organization_id) REFERENCES education.students(id, organization_id)`.

Проверок CHECK: 1; индексов: 5; пользовательских триггеров: 0.

## education.object_cleanup

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `object_key` | `character varying(160)` | нет | — | — |
| `queued_at` | `timestamp with time zone` | нет | now() | — |
| `attempts` | `integer` | нет | 0 | — |
| `last_error` | `character varying(64)` | да | — | — |
| `completed_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (object_key)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 0.

## education.parent_accounts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `organization_id` | `uuid` | нет | — | — |
| `guardian_person_id` | `uuid` | нет | — | — |
| `account_id` | `uuid` | нет | — | — |
| `activated_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (organization_id, guardian_person_id)`.

**Реальные внешние ключи:**

- `parent_accounts_account_id_fkey`: `FOREIGN KEY (account_id) REFERENCES core.accounts(id)`.
- `parent_accounts_guardian_person_id_fkey`: `FOREIGN KEY (guardian_person_id) REFERENCES core.persons(id)`.
- `parent_accounts_organization_id_fkey`: `FOREIGN KEY (organization_id) REFERENCES core.organizations(id)`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 1.

## education.parent_invites

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `guardian_person_id` | `uuid` | нет | — | — |
| `code_hash` | `character(64)` | нет | — | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `used_at` | `timestamp with time zone` | да | — | — |
| `used_account_id` | `uuid` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `created_by_account_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `parent_invites_created_by_account_id_fkey`: `FOREIGN KEY (created_by_account_id) REFERENCES core.accounts(id)`.
- `parent_invites_guardian_person_id_fkey`: `FOREIGN KEY (guardian_person_id) REFERENCES core.persons(id)`.
- `parent_invites_student_id_guardian_person_id_fkey`: `FOREIGN KEY (student_id, guardian_person_id) REFERENCES education.student_guardians(student_id, guardian_person_id)`.
- `parent_invites_student_id_organization_id_fkey`: `FOREIGN KEY (student_id, organization_id) REFERENCES education.students(id, organization_id)`.
- `parent_invites_used_account_id_fkey`: `FOREIGN KEY (used_account_id) REFERENCES core.accounts(id)`.

Проверок CHECK: 3; индексов: 4; пользовательских триггеров: 0.

## education.privacy_receipts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `item_id` | `uuid` | нет | — | — |
| `result` | `character varying(30)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (item_id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 0; индексов: 1; пользовательских триггеров: 0.

## education.profiles

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `account_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `display_name` | `character varying(160)` | да | — | — |
| `avatar_file_id` | `uuid` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `profiles_avatar_file_id_account_id_fkey`: `FOREIGN KEY (avatar_file_id, account_id) REFERENCES education.files(id, owner_account_id)`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 0.

## education.programs

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `name` | `character varying(160)` | нет | — | — |
| `description` | `text` | нет | ''::text | — |
| `version` | `integer` | нет | 1 | — |
| `archived_at` | `timestamp with time zone` | да | — | — |
| `created_by_account_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 3; индексов: 3; пользовательских триггеров: 0.

## education.question_bank

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `author_person_id` | `uuid` | нет | — | — |
| `category` | `character varying(100)` | нет | 'general'::character varying | — |
| `tags` | `jsonb` | нет | '[]'::jsonb | — |
| `difficulty` | `integer` | нет | 1 | — |
| `question_type` | `character varying(30)` | нет | — | — |
| `content` | `jsonb` | нет | — | — |
| `answer_key` | `jsonb` | нет | — | — |
| `explanation` | `text` | нет | ''::text | — |
| `default_points` | `integer` | нет | 1 | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 5; индексов: 2; пользовательских триггеров: 0.

## education.result_revisions

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `result_id` | `uuid` | нет | — | — |
| `status` | `character varying(20)` | нет | — | — |
| `comment` | `text` | да | — | — |
| `revised_at` | `timestamp with time zone` | нет | now() | — |
| `revised_by_account_id` | `uuid` | нет | — | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `result_revisions_result_id_fkey`: `FOREIGN KEY (result_id) REFERENCES education.lesson_results(id)`.

Проверок CHECK: 2; индексов: 2; пользовательских триггеров: 0.

## education.student_accounts

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `student_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `account_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `activated_at` | `timestamp with time zone` | нет | now() | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (student_id, organization_id)`.

**Реальные внешние ключи:**

- `student_accounts_student_id_fkey`: `FOREIGN KEY (student_id) REFERENCES education.students(id)`.

Проверок CHECK: 0; индексов: 3; пользовательских триггеров: 0.

## education.student_guardians

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `guardian_person_id` | `uuid` | нет | — | — |
| `relationship` | `character varying(12)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `status` | `character varying(12)` | нет | 'pending'::character varying | — |
| `confirmed_at` | `timestamp with time zone` | да | — | — |
| `confirmed_by_account_id` | `uuid` | да | — | — |
| `revoked_at` | `timestamp with time zone` | да | — | — |
| `revoked_by_account_id` | `uuid` | да | — | — |
| `updated_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (student_id, guardian_person_id)`.

**Реальные внешние ключи:**

- `student_guardians_confirmed_by_account_id_fkey`: `FOREIGN KEY (confirmed_by_account_id) REFERENCES core.accounts(id)`.
- `student_guardians_guardian_person_id_fkey`: `FOREIGN KEY (guardian_person_id) REFERENCES core.persons(id)`.
- `student_guardians_revoked_by_account_id_fkey`: `FOREIGN KEY (revoked_by_account_id) REFERENCES core.accounts(id)`.
- `student_guardians_student_id_organization_id_fkey`: `FOREIGN KEY (student_id, organization_id) REFERENCES education.students(id, organization_id)`.

Проверок CHECK: 3; индексов: 2; пользовательских триггеров: 0.

## education.student_invites

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `code_hash` | `character(64)` | нет | — | — |
| `expires_at` | `timestamp with time zone` | нет | — | — |
| `used_at` | `timestamp with time zone` | да | — | — |
| `used_account_id` | `uuid` | да | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

**Реальные внешние ключи:**

- `student_invites_student_id_organization_id_owner_account_i_fkey`: `FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id)`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 0.

## education.students

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `owner_account_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 0; индексов: 4; пользовательских триггеров: 0.

## education.teachers

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `account_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 0; индексов: 3; пользовательских триггеров: 0.

## education.thread_members

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `thread_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `person_id` | `uuid` | нет | — | — |
| `last_read_at` | `timestamp with time zone` | да | — | — |
| `last_read_message_id` | `uuid` | да | — | — |

**Первичный ключ:** `PRIMARY KEY (thread_id, person_id)`.

**Реальные внешние ключи:**

- `thread_members_thread_id_organization_id_fkey`: `FOREIGN KEY (thread_id, organization_id) REFERENCES education.threads(id, organization_id)`.

Проверок CHECK: 0; индексов: 2; пользовательских триггеров: 0.

## education.threads

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `id` | `uuid` | нет | uuidv7() | — |
| `organization_id` | `uuid` | нет | — | — |
| `subject` | `character varying(160)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |
| `context_type` | `character varying(30)` | да | — | — |
| `context_id` | `uuid` | да | — | — |
| `last_activity_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (id)`.

Внешние ключи SQL из этой таблицы отсутствуют.

Проверок CHECK: 1; индексов: 3; пользовательских триггеров: 0.

## education.typing_results

| Поле | Тип PostgreSQL | Допускает NULL | По умолчанию / вычисляемое | Пояснение к полю БД |
| --- | --- | --- | --- | --- |
| `attempt_id` | `uuid` | нет | — | — |
| `organization_id` | `uuid` | нет | — | — |
| `student_id` | `uuid` | нет | — | — |
| `duration_seconds` | `integer` | нет | — | — |
| `language` | `character varying(2)` | нет | — | — |
| `keystrokes` | `integer` | нет | — | — |
| `correct_chars` | `integer` | нет | — | — |
| `errors` | `integer` | нет | — | — |
| `wpm` | `numeric(6,1)` | нет | — | — |
| `accuracy` | `numeric(5,2)` | нет | — | — |
| `created_at` | `timestamp with time zone` | нет | now() | — |

**Первичный ключ:** `PRIMARY KEY (attempt_id)`.

**Реальные внешние ключи:**

- `typing_results_attempt_id_fkey`: `FOREIGN KEY (attempt_id) REFERENCES education.activity_attempts(id)`.
- `typing_results_student_id_fkey`: `FOREIGN KEY (student_id) REFERENCES education.students(id)`.

Проверок CHECK: 7; индексов: 2; пользовательских триггеров: 0.

