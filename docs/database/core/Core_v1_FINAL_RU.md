# Platform Core v1 FINAL — подробное описание DBML

Документ составлен **по текущему `Platform_Core_v1_FINAL.dbml`**.

Здесь для каждой таблицы указано:
- что это за таблица и зачем она нужна;
- что означает **каждое поле**;
- тип данных с английским названием;
- ключи, `NULL / NOT NULL`, `DEFAULT`, `UNIQUE`;
- индексы;
- все связи с **явным направлением**: `дочерняя таблица.поле → родительская таблица.id`;
- поведение при удалении (`CASCADE`, `RESTRICT`, `SET NULL`).

## 0. Как читать типы и ограничения

| DBML / PostgreSQL | English name | Что означает |
|---|---|---|
| `uuid` | Universally Unique Identifier | 128-битный уникальный идентификатор. |
| `varchar(n)` | Variable-length character string | Строка переменной длины, максимум `n` символов. |
| `text` | Unlimited-length text | Текст практически без прикладного ограничения по длине. |
| `date` | Calendar date | Дата без времени и часового пояса. |
| `timestamptz` | Timestamp with time zone | Дата и время с учётом часового пояса PostgreSQL. |
| `boolean` | Boolean | Логическое значение `true / false`. |
| `smallint` | 16-bit signed integer | Небольшое целое число. |
| `integer` | 32-bit signed integer | Обычное целое число. |
| `bigint` | 64-bit signed integer | Большое целое число. |
| `bytea` | Binary data / binary string | Набор байтов: хэши, ключи, бинарные идентификаторы. |
| `jsonb` | Binary JSON | JSON в бинарном формате PostgreSQL с возможностью индексации. |
| `inet` | IP network address | IPv4/IPv6 адрес. |
| `text[]` | Array of text strings | Массив строк PostgreSQL. |
| `Enum` | Enumerated type | Тип с закрытым набором допустимых значений. |
| `pk` | Primary Key | Главный уникальный ключ строки. |
| `FK` | Foreign Key | Внешний ключ на другую таблицу. |
| `not null` | NOT NULL | Значение обязательно. |
| `unique` | UNIQUE | Значение/комбинация значений не может повторяться. |
| `default` | DEFAULT | Значение, используемое автоматически, если оно не передано. |
| `increment` | Identity / auto-increment | Числовой идентификатор увеличивается автоматически. |
| `CASCADE` | ON DELETE CASCADE | При удалении родителя связанные дочерние строки удаляются автоматически. |
| `RESTRICT` | ON DELETE RESTRICT | Родителя нельзя удалить, пока на него ссылаются дочерние строки. |
| `SET NULL` | ON DELETE SET NULL | При удалении родителя FK в дочерней строке становится `NULL`. |

> Важное направление: запись `child.parent_id → parent.id` означает, что **child хранит внешний ключ**, а `parent` является родительской таблицей.

---

# 1. ENUM-типы

## `core_person_status`
Статус физического человека (`core.persons.status`).

| Значение | Смысл |
|---|---|
| `active` | Человек активен в системе. |
| `inactive` | Временно неактивен, но не архивирован. |
| `archived` | Архивная запись. |

## `core_contact_type`
Тип контакта физического человека.

| Значение | Смысл |
|---|---|
| `email` | Email-адрес. |
| `phone` | Телефон. |

## `core_account_type`
Тип системной учётной записи.

| Значение | Смысл |
|---|---|
| `human` | Учётная запись человека. |
| `service` | Учётная запись сервиса/интеграции. |
| `system` | Внутренняя системная учётная запись. |

## `core_account_status`
Жизненный цикл Account.

| Значение | Смысл |
|---|---|
| `pending_verification` | Требуется подтверждение идентификатора/регистрации. |
| `pending_approval` | Ожидает одобрения. |
| `active` | Можно использовать. |
| `disabled` | Отключён. |
| `archived` | Архивирован. |

## `core_auth_identifier_type`
Тип идентификатора входа.

| Значение | Смысл |
|---|---|
| `username` | Логин. |
| `email` | Email как идентификатор Account. |
| `phone` | Телефон как идентификатор Account. |

## `core_totp_algorithm`
Алгоритм HMAC для TOTP.

| Значение | Смысл |
|---|---|
| `sha1` | HMAC-SHA1. |
| `sha256` | HMAC-SHA256. |
| `sha512` | HMAC-SHA512. |

## `core_audit_actor_type`
Кто инициировал аудируемое действие.

| Значение | Смысл |
|---|---|
| `anonymous` | Неавторизованный субъект. |
| `account` | Обычный Account. |
| `system` | Внутренняя система. |
| `service` | Сервисная учётная запись/процесс. |

## `core_outbox_status`
Статус исходящего внутреннего события.

| Значение | Смысл |
|---|---|
| `pending` | Ждёт обработки. |
| `processing` | Сейчас обрабатывается worker'ом. |
| `published` | Успешно опубликовано. |
| `failed` | Ошибка, возможен повтор. |
| `dead` | Окончательно отправлено в dead-letter состояние. |

## `core_inbox_status`
Статус события у consumer'а.

| Значение | Смысл |
|---|---|
| `received` | Получено. |
| `processing` | Обрабатывается. |
| `processed` | Успешно обработано. |
| `failed` | Ошибка обработки. |
| `dead` | Дальнейшие автоматические попытки прекращены. |

---
# 1. `core.persons`

Главная таблица **физических людей**. Это не логин и не учётная запись. Person может существовать вообще без Account: например ребёнок, которому не разрешён самостоятельный вход.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | Уникальный ID физического человека. |
| `status` | ENUM — `core_person_status` | NOT NULL, DEFAULT `active` | Текущий статус Person: active / inactive / archived. |
| `given_name` | VARCHAR(120) — Variable-length character string | NOT NULL | Имя. |
| `family_name` | VARCHAR(120) — Variable-length character string | NOT NULL | Фамилия. |
| `middle_name` | VARCHAR(120) — Variable-length character string | NULL allowed | Отчество/среднее имя, если оно есть. |
| `display_name` | VARCHAR(255) — Variable-length character string | NULL allowed | Готовая строка отображаемого имени для UI, если её надо хранить отдельно. |
| `birth_date` | DATE — Calendar date | NULL allowed | Дата рождения без времени. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда Person создан. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда Person последний раз изменён. |
| `archived_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда Person архивирован. `NULL` = не архивирован. |

## Индексы

- `idx_persons_status` по `status` — быстрый отбор active/inactive/archived.
- `idx_persons_name` по `(family_name, given_name)` — поиск/сортировка по фамилии и имени.
- `idx_persons_birth_date` по `birth_date` — поиск/фильтр по дате рождения.

## Важная логика

- Person является источником истины о самом человеке.
- Контакты вынесены в отдельную таблицу `core.person_contacts`.
- Account вынесен отдельно в `core.accounts`.

---

# 2. `core.person_contacts`

Контакты **физического Person**: email и телефоны. Это не обязательно логины. У одного человека может быть 0..N контактов.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID конкретного контакта. |
| `person_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Какому Person принадлежит контакт. |
| `contact_type` | ENUM — `core_contact_type` | NOT NULL | Тип контакта: email или phone. |
| `value` | VARCHAR(320) — Variable-length character string | NOT NULL | Исходное значение для отображения пользователю. |
| `normalized_value` | VARCHAR(320) — Variable-length character string | NOT NULL | Нормализованное значение для поиска и сравнения, например email в lower-case или телефон в едином формате. |
| `label` | VARCHAR(80) — Variable-length character string | NULL allowed | Человекочитаемая метка: «Личный», «Рабочий» и т.п. |
| `is_primary` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Является ли этот контакт главным среди контактов данного типа. |
| `verified_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Время подтверждения контакта. `NULL` = не подтверждён. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда контакт добавлен. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда контакт изменён. |

## Индексы

- `uq_person_contacts_value` UNIQUE по `(person_id, contact_type, normalized_value)` — один и тот же контакт нельзя повторно добавить одному Person.
- `idx_person_contacts_person_type` по `(person_id, contact_type)` — быстро получить телефоны/email человека.
- `idx_person_contacts_normalized` по `normalized_value` — быстрый поиск по нормализованному контакту.
- В migration: partial UNIQUE на `(person_id, contact_type)` при `is_primary = true` — только один главный email и один главный телефон.

## Связи — направление

- **`core.person_contacts.person_id → core.persons.id`**. Направление: `person_contacts` (дочерняя) → `persons` (родительская). Кардинальность: много контактов → один Person. `ON DELETE CASCADE`: удалили Person → его контакты удаляются.

## Важная логика

- Телефон родителя хранится у Person родителя, а связь родитель ↔ ребёнок будет в Education.

---

# 3. `core.accounts`

Системная **учётная запись**. Отвечает за возможность взаимодействовать с системой. `Person != Account`: один описывает человека, другой — системную identity.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID Account. |
| `person_id` | UUID — Universally Unique Identifier | NULL allowed, UNIQUE, FK | Связанный физический Person. Для `human` логически обязателен; для `service/system` может быть `NULL`. UNIQUE даёт максимум один Account на Person. |
| `account_type` | ENUM — `core_account_type` | NOT NULL, DEFAULT `human` | Тип Account: human / service / system. |
| `status` | ENUM — `core_account_status` | NOT NULL, DEFAULT `pending_verification` | Состояние Account. |
| `label` | VARCHAR(160) — Variable-length character string | NULL allowed | Служебное имя Account, особенно полезно для service/system. |
| `security_stamp` | UUID — Universally Unique Identifier | NOT NULL, DEFAULT `uuidv4()` | Случайная версия безопасности Account. Меняется при security-событиях и инвалидирует старые сессии. |
| `locked_until` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | До какого момента временно запрещён вход. |
| `last_login_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Время последнего успешного входа. |
| `created_by_account_id` | UUID — Universally Unique Identifier | NULL allowed, FK self-reference | Какой Account создал этот Account. |
| `approved_by_account_id` | UUID — Universally Unique Identifier | NULL allowed, FK self-reference | Какой Account одобрил этот Account. |
| `approved_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда Account был одобрен. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда Account создан. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Последнее изменение. |
| `disabled_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда Account отключён. |
| `archived_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда Account архивирован. |

## Индексы

- `uq_accounts_person` UNIQUE по `person_id` — один Person максимум с одним Account.
- `idx_accounts_status` — фильтрация по статусу.
- `idx_accounts_type` — фильтрация по типу.
- `idx_accounts_approved_by` — поиск Account, одобренных конкретным Account.

## Связи — направление

- **`core.accounts.person_id → core.persons.id`**. Направление: Account → Person. По `UNIQUE person_id`: Person 0..1 ← Account. `ON DELETE RESTRICT`: Person нельзя физически удалить, пока на него ссылается Account.
- **`core.accounts.created_by_account_id → core.accounts.id`**. Self-reference: создаваемый Account → Account-создатель. `ON DELETE SET NULL`.
- **`core.accounts.approved_by_account_id → core.accounts.id`**. Self-reference: одобренный Account → Account, который одобрил. `ON DELETE SET NULL`.

## Важная логика

- В migration должен быть CHECK: `account_type='human' -> person_id IS NOT NULL`.
- `security_stamp` сравнивается с `auth_sessions.security_stamp`.

---

# 4. `core.auth_identifiers`

Идентификаторы, по которым Account можно находить для входа или восстановления: username, email, phone. Намеренно отделены от `person_contacts`.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID auth-идентификатора. |
| `account_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Какому Account принадлежит identifier. |
| `identifier_type` | ENUM — `core_auth_identifier_type` | NOT NULL | username / email / phone. |
| `value` | VARCHAR(320) — Variable-length character string | NOT NULL | Исходное отображаемое значение. |
| `normalized_value` | VARCHAR(320) — Variable-length character string | NOT NULL | Нормализованное значение для уникальности и поиска. |
| `is_primary` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Главный identifier этого типа для Account. |
| `can_login` | BOOLEAN — Boolean | NOT NULL, DEFAULT `true` | Можно ли использовать для входа. |
| `can_recover` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Можно ли использовать для восстановления доступа. |
| `verified_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда identifier подтверждён. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда identifier добавлен. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда identifier изменён. |

## Индексы

- `uq_auth_identifiers_type_value` UNIQUE по `(identifier_type, normalized_value)` — один и тот же email/phone/username не может принадлежать двум Account в рамках типа.
- `idx_auth_identifiers_account` — быстро получить identifiers Account.
- `idx_auth_identifiers_account_type` — быстро получить identifiers Account конкретного типа.
- В migration: partial UNIQUE на `(account_id, identifier_type)` при `is_primary = true`.

## Связи — направление

- **`core.auth_identifiers.account_id → core.accounts.id`**. Направление: auth identifier → Account. Много identifiers → один Account. `ON DELETE CASCADE`.

## Важная логика

- `can_recover=true` реально использовать только после проверки `verified_at` в application policy.

---

# 5. `core.auth_password_credentials`

Парольная credential Account. Строка существует только тогда, когда Account вообще имеет пароль.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID парольной credential. |
| `account_id` | UUID — Universally Unique Identifier | NOT NULL, UNIQUE, FK | Account, которому принадлежит пароль. UNIQUE = максимум один активный password credential row на Account. |
| `password_hash` | TEXT — Unlimited-length text | NOT NULL | Хэш пароля. Сырой пароль никогда не хранится. |
| `password_algorithm` | VARCHAR(32) — Variable-length character string | NOT NULL | Информационное имя алгоритма, например `argon2id`. |
| `must_change_password` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Нужно ли заставить пользователя сменить пароль при следующем входе. |
| `password_changed_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда пароль последний раз менялся. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда password credential создана. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Последнее изменение. |

## Индексы

- `uq_auth_password_credentials_account` UNIQUE по `account_id` — отношение Account 1 → 0..1 password credential.

## Связи — направление

- **`core.auth_password_credentials.account_id → core.accounts.id`**. Направление: password credential → Account. `ON DELETE CASCADE`.

## Важная логика

- Если Account использует только Passkey или внешний OAuth/OIDC, строки здесь может не быть.

---

# 6. `core.auth_providers`

Справочник внешних провайдеров авторизации: Google, Telegram, Microsoft и другие.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID provider. |
| `code` | VARCHAR(50) — Variable-length character string | NOT NULL, UNIQUE | Стабильный машинный код: `google`, `telegram`, `microsoft` и т.п. |
| `protocol` | VARCHAR(50) — Variable-length character string | NOT NULL | Тип протокола: `oidc`, `oauth2`, `custom` и т.п. |
| `display_name` | VARCHAR(120) — Variable-length character string | NOT NULL | Отображаемое название. |
| `enabled` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Включён ли provider. |
| `allow_login` | BOOLEAN — Boolean | NOT NULL, DEFAULT `true` | Разрешён ли вход через provider. |
| `allow_registration` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Можно ли создавать новый Account через provider. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда provider зарегистрирован. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда настройки provider изменены. |

## Индексы

- `uq_auth_providers_code` UNIQUE по `code`.
- `idx_auth_providers_enabled` — быстро получить включённые providers.

## Важная логика

- Client secret/private key здесь не хранится; секреты должны жить в env/secrets.
- `protocol` сделан VARCHAR намеренно, чтобы не менять ENUM при добавлении нового протокола.

---

# 7. `core.auth_external_identities`

Привязки наших Account к аккаунтам внешних провайдеров.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID привязки. |
| `account_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Наш Account. |
| `provider_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Внешний provider. |
| `provider_subject` | VARCHAR(255) — Variable-length character string | NOT NULL | Стабильный ID пользователя на стороне provider, например OIDC `sub`. |
| `email` | VARCHAR(320) — Variable-length character string | NULL allowed | Email, сообщённый provider. |
| `email_verified` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Считает ли provider этот email подтверждённым. |
| `display_name` | VARCHAR(255) — Variable-length character string | NULL allowed | Имя, полученное от provider. |
| `avatar_url` | TEXT — Unlimited-length text | NULL allowed | URL аватара от provider. |
| `claims` | JSONB — Binary JSON | NOT NULL, DEFAULT `{}` | Дополнительные claims provider. |
| `linked_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда внешний аккаунт привязали. |
| `last_login_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Последний успешный вход через эту identity. |

## Индексы

- `uq_external_identity_provider_subject` UNIQUE по `(provider_id, provider_subject)` — один внешний пользователь нельзя привязать к двум нашим Account.
- `uq_external_identity_account_provider` UNIQUE по `(account_id, provider_id)` — один наш Account максимум с одной identity конкретного provider.
- `idx_external_identities_account` — identity по Account.
- `idx_external_identities_provider` — identity по provider.
- `idx_external_identities_email` — поиск по email, полученному от provider.

## Связи — направление

- **`core.auth_external_identities.account_id → core.accounts.id`**. Направление: external identity → наш Account. `ON DELETE CASCADE`.
- **`core.auth_external_identities.provider_id → core.auth_providers.id`**. Направление: external identity → auth provider. `ON DELETE RESTRICT`: provider нельзя удалить, пока есть привязки.

## Важная логика

- Account нельзя автоматически merge'ить только потому, что provider прислал совпадающий email.

---

# 8. `core.auth_passkeys`

WebAuthn / Passkey credentials. Один Account может иметь несколько ключей: например ноутбук, телефон и отдельный security key.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID Passkey. |
| `account_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Владелец Passkey. |
| `credential_id` | BYTEA — Binary data | NOT NULL, UNIQUE | Бинарный WebAuthn credential ID. |
| `public_key` | BYTEA — Binary data | NOT NULL | Публичный ключ Passkey. Приватный ключ сервер не хранит. |
| `sign_count` | BIGINT — 64-bit signed integer | NOT NULL, DEFAULT `0` | Счётчик WebAuthn authenticator, если используется выбранной реализацией. |
| `aaguid` | UUID — Universally Unique Identifier | NULL allowed | Authenticator Attestation GUID устройства/аутентификатора. |
| `transports` | TEXT[] — Array of text strings | NOT NULL, DEFAULT empty array | Транспорты: internal / usb / nfc / ble и т.п. |
| `label` | VARCHAR(160) — Variable-length character string | NULL allowed | Пользовательское имя ключа, например «Pixel 9» или «YubiKey». |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда Passkey зарегистрирован. |
| `last_used_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Последнее использование. |
| `revoked_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда Passkey отозван. `NULL` = активен. |

## Индексы

- `uq_auth_passkeys_credential` UNIQUE по `credential_id`.
- `idx_auth_passkeys_account` — Passkey конкретного Account.
- `idx_auth_passkeys_revoked` — фильтр активных/отозванных ключей.

## Связи — направление

- **`core.auth_passkeys.account_id → core.accounts.id`**. Направление: Passkey → Account. Много Passkey → один Account. `ON DELETE CASCADE`.

---

# 9. `core.auth_totp_factors`

TOTP-факторы MFA: Google Authenticator, 2FAS, Microsoft Authenticator и совместимые приложения.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID TOTP-фактора. |
| `account_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Account-владелец. |
| `label` | VARCHAR(160) — Variable-length character string | NULL allowed | Метка фактора. |
| `secret_encrypted` | BYTEA — Binary data | NOT NULL | Зашифрованный TOTP secret. Ключ шифрования находится вне БД. |
| `algorithm` | ENUM — `core_totp_algorithm` | NOT NULL, DEFAULT `sha1` | Алгоритм TOTP. |
| `digits` | SMALLINT — 16-bit signed integer | NOT NULL, DEFAULT `6` | Количество цифр одноразового кода. |
| `period_seconds` | SMALLINT — 16-bit signed integer | NOT NULL, DEFAULT `30` | Длина TOTP-временного шага в секундах. |
| `verified_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда пользователь подтвердил корректность настроенного TOTP. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда фактор создан. |
| `last_used_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Последнее успешное использование. |
| `revoked_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда фактор отозван. |

## Индексы

- `idx_auth_totp_account` — TOTP-факторы Account.
- `idx_auth_totp_revoked` — фильтрация отозванных факторов.

## Связи — направление

- **`core.auth_totp_factors.account_id → core.accounts.id`**. Направление: TOTP factor → Account. Много факторов → один Account. `ON DELETE CASCADE`.

## Важная логика

- В migration будут CHECK для допустимых `digits` и `period_seconds`.
- Требование MFA для ролей — policy приложения, а не смысл самой таблицы.

---

# 10. `core.auth_recovery_codes`

Одноразовые резервные коды восстановления MFA.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID recovery code. |
| `account_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Account-владелец. |
| `code_hash` | BYTEA — Binary data | NOT NULL, UNIQUE | Криптографический хэш кода. Сам код в БД не хранится. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда код создан. |
| `used_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда код использован. `NULL` = ещё не использован. |

## Индексы

- `uq_auth_recovery_codes_hash` UNIQUE по `code_hash`.
- `idx_auth_recovery_codes_account` — коды Account.
- `idx_auth_recovery_codes_used` — быстрый отбор использованных/неиспользованных кодов.

## Связи — направление

- **`core.auth_recovery_codes.account_id → core.accounts.id`**. Направление: recovery code → Account. `ON DELETE CASCADE`.

---

# 11. `core.auth_sessions`

Активные и исторические пользовательские сессии. Позволяет делать «активные устройства», отзыв одной сессии и «выйти со всех устройств».

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID сессии. |
| `account_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Account-владелец сессии. |
| `session_token_hash` | BYTEA — Binary data | NOT NULL, UNIQUE | Хэш случайного opaque session token. Сам token в БД не хранится. |
| `refresh_token_hash` | BYTEA — Binary data | NULL allowed, UNIQUE | Хэш refresh token, если конкретному клиенту он нужен. |
| `security_stamp` | UUID — Universally Unique Identifier | NOT NULL | Snapshot `accounts.security_stamp` на момент создания сессии. |
| `authentication_method` | VARCHAR(80) — Variable-length character string | NOT NULL | Как был выполнен вход: password / google / telegram / passkey / ... |
| `mfa_verified_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда в рамках аутентификации подтверждён MFA. |
| `client_type` | VARCHAR(40) — Variable-length character string | NOT NULL | Тип клиента: web / mobile / agent / ... |
| `device_name` | VARCHAR(160) — Variable-length character string | NULL allowed | Отображаемое имя устройства/браузера. |
| `ip_address` | INET — IP network address | NULL allowed | IP клиента. |
| `user_agent` | TEXT — Unlimited-length text | NULL allowed | HTTP User-Agent. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Начало сессии. |
| `last_seen_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Последняя активность. |
| `expires_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL | Когда сессия истекает. |
| `revoked_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда сессия была принудительно отозвана. |
| `revoke_reason` | VARCHAR(255) — Variable-length character string | NULL allowed | Причина отзыва. |

## Индексы

- `uq_auth_sessions_token` UNIQUE по `session_token_hash`.
- `uq_auth_sessions_refresh` UNIQUE по `refresh_token_hash`.
- `idx_auth_sessions_account` — сессии Account.
- `idx_auth_sessions_expires` — поиск истёкших сессий.
- `idx_auth_sessions_account_expires` — активные/истёкшие сессии конкретного Account.

## Связи — направление

- **`core.auth_sessions.account_id → core.accounts.id`**. Направление: Session → Account. Много сессий → один Account. `ON DELETE CASCADE`.

## Важная логика

- В migration: CHECK `expires_at > created_at`.
- Если `session.security_stamp != account.security_stamp`, сессия считается недействительной.

---

# 12. `core.auth_challenges`

Одноразовые security/auth операции: подтверждение email, reset пароля, invitation, magic login и другие сценарии.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID challenge. |
| `account_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Связанный Account. Может быть `NULL`, если Account ещё неизвестен/не создан. |
| `challenge_type` | VARCHAR(80) — Variable-length character string | NOT NULL | Расширяемый тип challenge: email_verification / password_reset / invitation / magic_login / ... |
| `secret_hash` | BYTEA — Binary data | NOT NULL, UNIQUE | Хэш одноразового секрета. |
| `target_normalized` | VARCHAR(320) — Variable-length character string | NULL allowed | Нормализованная цель, например email/phone. |
| `payload` | JSONB — Binary JSON | NOT NULL, DEFAULT `{}` | Дополнительные безопасные данные конкретного сценария. |
| `attempts` | SMALLINT — 16-bit signed integer | NOT NULL, DEFAULT `0` | Количество попыток. |
| `max_attempts` | SMALLINT — 16-bit signed integer | NOT NULL, DEFAULT `5` | Максимальное число попыток. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда challenge создан. |
| `expires_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL | Когда challenge перестаёт действовать. |
| `consumed_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда challenge успешно использован. |
| `cancelled_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда challenge отменён. |

## Индексы

- `uq_auth_challenges_secret` UNIQUE по `secret_hash`.
- `idx_auth_challenges_account` — challenges Account.
- `idx_auth_challenges_type_expires` — поиск активных/истёкших challenges конкретного типа.

## Связи — направление

- **`core.auth_challenges.account_id → core.accounts.id`**. Направление: Challenge → Account. `account_id` nullable. `ON DELETE CASCADE` для challenges, связанных с удаляемым Account.

## Важная логика

- В migration: `attempts >= 0`, `max_attempts > 0`, `expires_at > created_at`.

---

# 13. `core.roles`

RBAC-роли. Role — это набор полномочий. Отдельной сущности «Admin» в Core нет.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID роли. |
| `code` | VARCHAR(120) — Variable-length character string | NOT NULL, UNIQUE | Стабильный машинный код, например `core.superadmin` / `core.admin`. |
| `name` | VARCHAR(160) — Variable-length character string | NOT NULL | Человекочитаемое название. |
| `description` | TEXT — Unlimited-length text | NULL allowed | Описание роли. |
| `is_system` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Создана ли роль системой. |
| `is_protected` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Защищена ли роль от обычного удаления/изменения. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда создана. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда изменена. |

## Индексы

- `uq_roles_code` UNIQUE по `code`.
- `idx_roles_system` — быстрый отбор системных ролей.

## Важная логика

- Seed-роли: `core.superadmin`, `core.admin`.
- `core.superadmin` получает bypass в PermissionService.

---

# 14. `core.modules`

Реестр подключённых модулей платформы. Core знает **о факте существования модуля**, но не знает внутренности его таблиц.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID модуля. |
| `code` | VARCHAR(100) — Variable-length character string | NOT NULL, UNIQUE | Машинный код: education / devices / storage / chat / ... |
| `name` | VARCHAR(160) — Variable-length character string | NOT NULL | Название. |
| `description` | TEXT — Unlimited-length text | NULL allowed | Описание. |
| `version` | VARCHAR(40) — Variable-length character string | NOT NULL | Версия модуля. |
| `settings_schema_version` | INTEGER — 32-bit signed integer | NOT NULL, DEFAULT `1` | Версия схемы его настроек. |
| `enabled` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Включён ли модуль. |
| `installed_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда модуль зарегистрирован/установлен. |
| `enabled_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда последний раз включён. |
| `disabled_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда последний раз выключен. |

## Индексы

- `uq_modules_code` UNIQUE по `code`.
- `idx_modules_enabled` — список включённых/выключенных модулей.

## Важная логика

- `Education/Devices/Storage/Chat` не становятся частью Core только из-за записи в этой таблице.

---

# 15. `core.permissions`

Каталог всех permission-кодов системы.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID permission. |
| `module_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Какому модулю принадлежит permission. `NULL` = permission самого Core. |
| `code` | VARCHAR(180) — Variable-length character string | NOT NULL, UNIQUE | Машинный permission-code, например `core.accounts.manage` или `education.lessons.read`. |
| `name` | VARCHAR(180) — Variable-length character string | NOT NULL | Отображаемое название. |
| `description` | TEXT — Unlimited-length text | NULL allowed | Описание. |
| `is_system` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Системный permission. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда создан. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда изменён. |

## Индексы

- `uq_permissions_code` UNIQUE по `code`.
- `idx_permissions_module` — permissions конкретного модуля.

## Связи — направление

- **`core.permissions.module_id → core.modules.id`**. Направление: Permission → Module. Много permissions → один Module. `module_id` может быть NULL для Core. `ON DELETE SET NULL`: удаление записи Module не удаляет permission, а отвязывает его.

---

# 16. `core.role_permissions`

Связующая таблица many-to-many между Roles и Permissions.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `role_id` | UUID — Universally Unique Identifier | PK part, NOT NULL, FK | Роль. |
| `permission_id` | UUID — Universally Unique Identifier | PK part, NOT NULL, FK | Permission. |
| `granted_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда permission выдан роли. |
| `granted_by_account_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Какой Account выдал permission. `NULL` для seed/system операции. |

## Индексы

- `pk_role_permissions` составной PRIMARY KEY `(role_id, permission_id)` — одну permission нельзя дважды добавить одной роли.
- `idx_role_permissions_permission` — найти все роли, содержащие конкретный permission.

## Связи — направление

- **`core.role_permissions.role_id → core.roles.id`**. Направление: role_permissions → Role. `ON DELETE CASCADE`.
- **`core.role_permissions.permission_id → core.permissions.id`**. Направление: role_permissions → Permission. `ON DELETE CASCADE`.
- **`core.role_permissions.granted_by_account_id → core.accounts.id`**. Направление: запись назначения permission → Account, который назначил. `ON DELETE SET NULL`.

## Важная логика

- Итоговая модель: `Role ← role_permissions → Permission`.

---

# 17. `core.account_roles`

Назначение ролей конкретным Account.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `account_id` | UUID — Universally Unique Identifier | PK part, NOT NULL, FK | Account, которому назначена роль. |
| `role_id` | UUID — Universally Unique Identifier | PK part, NOT NULL, FK | Назначенная Role. |
| `assigned_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда роль назначена. |
| `assigned_by_account_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Какой Account назначил роль. `NULL` для seed/system. |
| `expires_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда назначение истекает. `NULL` = бессрочно. |

## Индексы

- `pk_account_roles` составной PRIMARY KEY `(account_id, role_id)`.
- `idx_account_roles_role` — все Account конкретной роли.
- `idx_account_roles_expires` — поиск истекающих назначений.

## Связи — направление

- **`core.account_roles.account_id → core.accounts.id`**. Направление: назначение роли → Account. `ON DELETE CASCADE`.
- **`core.account_roles.role_id → core.roles.id`**. Направление: назначение роли → Role. `ON DELETE CASCADE`.
- **`core.account_roles.assigned_by_account_id → core.accounts.id`**. Направление: назначение роли → Account, который назначил. `ON DELETE SET NULL`.

## Важная логика

- Итоговая модель: `Account ← account_roles → Role`.
- Это текущее состояние ролей; полную историю изменений фиксирует Audit.

---

# 18. `core.module_settings`

Несекретные настройки конкретного модуля.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID настройки. |
| `module_id` | UUID — Universally Unique Identifier | NOT NULL, FK | Модуль-владелец. |
| `setting_key` | VARCHAR(160) — Variable-length character string | NOT NULL | Ключ настройки. |
| `value` | JSONB — Binary JSON | NOT NULL | Значение настройки произвольной JSON-структуры. |
| `description` | TEXT — Unlimited-length text | NULL allowed | Описание. |
| `updated_by_account_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Кто последний изменил настройку. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда создана. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда изменена. |

## Индексы

- `uq_module_settings_module_key` UNIQUE по `(module_id, setting_key)` — у модуля один ключ имеет одно текущее значение.

## Связи — направление

- **`core.module_settings.module_id → core.modules.id`**. Направление: ModuleSetting → Module. `ON DELETE CASCADE`: удалили запись Module → его настройки удалились.
- **`core.module_settings.updated_by_account_id → core.accounts.id`**. Направление: ModuleSetting → Account, который обновил. `ON DELETE SET NULL`.

## Важная логика

- Секреты сюда не помещать.

---

# 19. `core.feature_flags`

Feature flags для безопасного включения функций полностью или частично.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID feature flag. |
| `module_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Модуль-владелец. `NULL` = глобальный flag. |
| `code` | VARCHAR(160) — Variable-length character string | NOT NULL, UNIQUE | Глобально уникальный код flag. |
| `enabled` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Глобально включён ли flag. |
| `rollout_percent` | SMALLINT — 16-bit signed integer | NOT NULL, DEFAULT `100` | Процент rollout: 0..100. |
| `config` | JSONB — Binary JSON | NOT NULL, DEFAULT `{}` | Дополнительная конфигурация feature flag. |
| `description` | TEXT — Unlimited-length text | NULL allowed | Описание. |
| `updated_by_account_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Кто последний изменил flag. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда создан. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда изменён. |

## Индексы

- `uq_feature_flags_code` UNIQUE по `code`.
- `idx_feature_flags_module` — flags конкретного модуля.

## Связи — направление

- **`core.feature_flags.module_id → core.modules.id`**. Направление: FeatureFlag → Module. `module_id` может быть NULL. `ON DELETE CASCADE`: module-owned flags удаляются вместе с Module; глобальные flags не затрагиваются.
- **`core.feature_flags.updated_by_account_id → core.accounts.id`**. Направление: FeatureFlag → Account, который изменил. `ON DELETE SET NULL`.

## Важная логика

- В migration: CHECK `rollout_percent BETWEEN 0 AND 100`.

---

# 20. `core.system_settings`

Глобальные несекретные настройки **всей установки Platform**, а не конкретного модуля.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | UUID — Universally Unique Identifier | PK, NOT NULL, DEFAULT `uuidv7()` | ID настройки. |
| `setting_key` | VARCHAR(160) — Variable-length character string | NOT NULL, UNIQUE | Уникальный глобальный ключ. |
| `value` | JSONB — Binary JSON | NOT NULL | Значение. |
| `is_public` | BOOLEAN — Boolean | NOT NULL, DEFAULT `false` | Можно ли безопасно отдавать настройку клиенту как публичную. |
| `description` | TEXT — Unlimited-length text | NULL allowed | Описание. |
| `updated_by_account_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Кто изменил настройку. |
| `created_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда создана. |
| `updated_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда изменена. |

## Индексы

- `uq_system_settings_key` UNIQUE по `setting_key`.

## Связи — направление

- **`core.system_settings.updated_by_account_id → core.accounts.id`**. Направление: SystemSetting → Account, который изменил. `ON DELETE SET NULL`.

## Важная логика

- `is_public=true` не означает «секрет можно показать»; секреты вообще не должны находиться в этой таблице.

---

# 21. `core.audit_events`

Append-only журнал значимых действий и изменений. Нужен для безопасности, расследования, админки и истории изменений.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | BIGINT — 64-bit signed integer | PK, NOT NULL, auto-increment | Внутренний последовательный ID. |
| `event_id` | UUID — Universally Unique Identifier | NOT NULL, UNIQUE, DEFAULT `uuidv7()` | Глобальный ID audit-события. |
| `occurred_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда произошло событие. |
| `actor_type` | ENUM — `core_audit_actor_type` | NOT NULL | Кто инициировал действие: anonymous/account/system/service. |
| `actor_account_id` | UUID — Universally Unique Identifier | NULL allowed, FK | Account-инициатор, если actor_type = account. |
| `action` | VARCHAR(180) — Variable-length character string | NOT NULL | Стабильный код действия. |
| `resource_type` | VARCHAR(180) — Variable-length character string | NULL allowed | Тип ресурса, например `education.lesson`. |
| `resource_id` | VARCHAR(160) — Variable-length character string | NULL allowed | ID ресурса в строковом виде. |
| `request_id` | UUID — Universally Unique Identifier | NULL allowed | ID конкретного HTTP/command request. |
| `correlation_id` | UUID — Universally Unique Identifier | NULL allowed | ID сквозной цепочки связанных операций. |
| `ip_address` | INET — IP network address | NULL allowed | IP инициатора. |
| `user_agent` | TEXT — Unlimited-length text | NULL allowed | User-Agent. |
| `success` | BOOLEAN — Boolean | NOT NULL, DEFAULT `true` | Успешно ли действие. |
| `error_code` | VARCHAR(120) — Variable-length character string | NULL allowed | Машинный код ошибки. |
| `before_data` | JSONB — Binary JSON | NULL allowed | Безопасный snapshot важных данных до изменения. |
| `after_data` | JSONB — Binary JSON | NULL allowed | Безопасный snapshot после изменения. |
| `metadata` | JSONB — Binary JSON | NOT NULL, DEFAULT `{}` | Дополнительный безопасный контекст. |

## Индексы

- `uq_audit_events_event` UNIQUE по `event_id`.
- `idx_audit_events_time` — события по времени.
- `idx_audit_events_actor` — действия конкретного Account.
- `idx_audit_events_resource` по `(resource_type, resource_id)` — история конкретного ресурса.
- `idx_audit_events_correlation` — вся цепочка операции.
- `idx_audit_events_action` — события конкретного типа действия.

## Связи — направление

- **`core.audit_events.actor_account_id → core.accounts.id`**. Направление: AuditEvent → Account-инициатор. `ON DELETE SET NULL`: сам аудит сохраняется даже если Account физически удалён.

## Важная логика

- Таблица append-only: уже записанное событие не должно редактироваться обычной бизнес-логикой.
- Пароли, session tokens, TOTP secrets и другие секреты нельзя писать в `before_data`, `after_data`, `metadata`.

---

# 22. `core.outbox_events`

Transactional Outbox. Гарантирует, что бизнес-изменение в PostgreSQL и намерение отправить событие фиксируются атомарно в одной транзакции.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | BIGINT — 64-bit signed integer | PK, NOT NULL, auto-increment | Внутренний последовательный ID. |
| `event_id` | UUID — Universally Unique Identifier | NOT NULL, UNIQUE, DEFAULT `uuidv7()` | Глобальный ID события. |
| `producer_module` | VARCHAR(100) — Variable-length character string | NOT NULL | Какой модуль создал событие. |
| `event_type` | VARCHAR(220) — Variable-length character string | NOT NULL | Тип события, например `education.homework.created`. |
| `aggregate_type` | VARCHAR(160) — Variable-length character string | NULL allowed | Тип агрегата-источника. |
| `aggregate_id` | VARCHAR(160) — Variable-length character string | NULL allowed | ID агрегата-источника. |
| `payload` | JSONB — Binary JSON | NOT NULL | Полезная нагрузка события. |
| `headers` | JSONB — Binary JSON | NOT NULL, DEFAULT `{}` | Служебные заголовки/metadata доставки. |
| `correlation_id` | UUID — Universally Unique Identifier | NULL allowed | ID общей бизнес-операции. |
| `causation_id` | UUID — Universally Unique Identifier | NULL allowed | ID события/команды, которая породила это событие. |
| `status` | ENUM — `core_outbox_status` | NOT NULL, DEFAULT `pending` | Состояние доставки. |
| `attempts` | INTEGER — 32-bit signed integer | NOT NULL, DEFAULT `0` | Количество попыток публикации. |
| `occurred_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда бизнес-событие произошло. |
| `available_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Не раньше какого момента worker может публиковать. |
| `published_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда успешно опубликовано. |
| `locked_by` | VARCHAR(160) — Variable-length character string | NULL allowed | Какой worker взял запись. |
| `locked_until` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | До какого момента lock считается действующим. |
| `last_error` | TEXT — Unlimited-length text | NULL allowed | Последняя ошибка публикации. |

## Индексы

- `uq_outbox_events_event` UNIQUE по `event_id`.
- `idx_outbox_events_dispatch` по `(status, available_at)` — главный индекс worker'а.
- `idx_outbox_events_aggregate` по `(aggregate_type, aggregate_id)` — события конкретного агрегата.
- `idx_outbox_events_correlation` — события одной сквозной операции.

## Важная логика

- Прямого FK к доменным таблицам нет намеренно: Outbox универсален для всех модулей.
- Бизнес-изменение + INSERT в outbox должны быть одной PostgreSQL transaction.
- Worker позже публикует событие в Centrifugo/внутреннюю шину/consumer.
- В migration: CHECK `attempts >= 0`.
- Успешно published строки можно удалять retention-job после заданного периода; pending/failed/dead нельзя чистить преждевременно.

---

# 23. `core.inbox_events`

Inbox / idempotency ledger для consumers. Не даёт одному consumer обработать одно и то же событие дважды.

## Поля

| Поле | Type (English) | Ограничения / default | Что хранит |
|---|---|---|---|
| `id` | BIGINT — 64-bit signed integer | PK, NOT NULL, auto-increment | Внутренний последовательный ID. |
| `event_id` | UUID — Universally Unique Identifier | NOT NULL | ID пришедшего события. |
| `consumer` | VARCHAR(180) — Variable-length character string | NOT NULL | Уникальный код обработчика/consumer. |
| `status` | ENUM — `core_inbox_status` | NOT NULL, DEFAULT `received` | Состояние обработки. |
| `attempts` | INTEGER — 32-bit signed integer | NOT NULL, DEFAULT `0` | Количество попыток обработки. |
| `received_at` | TIMESTAMPTZ — Timestamp with time zone | NOT NULL, DEFAULT `now()` | Когда событие получено. |
| `processed_at` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | Когда успешно обработано. |
| `locked_by` | VARCHAR(160) — Variable-length character string | NULL allowed | Какой worker взял обработку. |
| `locked_until` | TIMESTAMPTZ — Timestamp with time zone | NULL allowed | До какого момента lock действителен. |
| `last_error` | TEXT — Unlimited-length text | NULL allowed | Последняя ошибка. |

## Индексы

- `uq_inbox_events_event_consumer` UNIQUE по `(event_id, consumer)` — один consumer не может зарегистрировать одно и то же событие дважды.
- `idx_inbox_events_consumer_status` — очередь конкретного consumer.
- `idx_inbox_events_status_received` — поиск необработанных/ошибочных событий по времени.

## Важная логика

- Прямых FK к Outbox нет: событие может прийти не только из локального outbox, но и извне.
- В migration: CHECK `attempts >= 0`.
- `processed` можно чистить retention-job; `failed/dead` хранить дольше для диагностики.

---

# Итоговая карта связей

Ниже направление всегда читается **слева направо: дочерняя таблица, содержащая FK → родительская таблица**.

```text
core.person_contacts.person_id
    → core.persons.id

core.accounts.person_id
    → core.persons.id

core.accounts.created_by_account_id
    → core.accounts.id

core.accounts.approved_by_account_id
    → core.accounts.id

core.auth_identifiers.account_id
    → core.accounts.id

core.auth_password_credentials.account_id
    → core.accounts.id

core.auth_external_identities.account_id
    → core.accounts.id

core.auth_external_identities.provider_id
    → core.auth_providers.id

core.auth_passkeys.account_id
    → core.accounts.id

core.auth_totp_factors.account_id
    → core.accounts.id

core.auth_recovery_codes.account_id
    → core.accounts.id

core.auth_sessions.account_id
    → core.accounts.id

core.auth_challenges.account_id
    → core.accounts.id

core.account_roles.account_id
    → core.accounts.id

core.account_roles.role_id
    → core.roles.id

core.account_roles.assigned_by_account_id
    → core.accounts.id

core.role_permissions.role_id
    → core.roles.id

core.role_permissions.permission_id
    → core.permissions.id

core.role_permissions.granted_by_account_id
    → core.accounts.id

core.permissions.module_id
    → core.modules.id

core.module_settings.module_id
    → core.modules.id

core.module_settings.updated_by_account_id
    → core.accounts.id

core.feature_flags.module_id
    → core.modules.id

core.feature_flags.updated_by_account_id
    → core.accounts.id

core.system_settings.updated_by_account_id
    → core.accounts.id

core.audit_events.actor_account_id
    → core.accounts.id
```

## Главная логическая схема

```text
Person
├── Person Contacts
└── Account
    ├── Auth Identifiers
    ├── Password Credential
    ├── External Identities ──→ Auth Providers
    ├── Passkeys
    ├── TOTP Factors
    ├── Recovery Codes
    ├── Sessions
    ├── Challenges
    └── Account Roles ──→ Roles
                           └── Role Permissions ──→ Permissions ──→ Modules

Modules
├── Module Settings
└── Feature Flags

System Settings
Audit Events
Outbox Events
Inbox Events
```

# Почему именно 23 таблицы Core

Core хранит только универсальный фундамент платформы:
- Person / contacts;
- Account / Auth / MFA / Sessions;
- RBAC;
- Module registry и настройки;
- Audit;
- Transactional Outbox / Inbox.

В Core **не входят** Student, Teacher, Parent, Lesson, Homework, File, Device, Chat Message, Search History и другие доменные сущности. Они будут жить в отдельных схемах/модулях (`education.*`, `storage.*`, `chat.*`, `search.*`, `devices.*` и т.д.).
