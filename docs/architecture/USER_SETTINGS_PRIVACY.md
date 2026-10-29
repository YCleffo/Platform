# User Settings / Privacy Core

## Граница ответственности

`Core/AccountSettings` владеет хранением, versioned defaults, whitelist-валидацией,
deep PATCH merge, авторизацией текущего пользователя, конкурентной записью и аудитом.
Доменные модули могут интерпретировать зарегистрированные значения, но не получают
произвольный JSON namespace и не хранят здесь Auth/RBAC/session/security state.

`locale` и `timezone` намеренно остаются в `core.accounts` и изменяются существующим
`PATCH /api/auth/me/preferences`. Это исключает второй источник истины.

## Хранение и версия

`core.account_settings` содержит максимум одну строку на Account. В `settings` хранится
только разреженный документ overrides. Отсутствующая строка означает defaults и GET не
создаёт её. Текущая application schema — `schema_version = 2`.
`SettingsSchemaRegistry::upgradeStored()` удаляет устаревшие Education-specific
overrides из v1 и сохраняет общие preferences; неизвестные будущие версии отвергаются.

PATCH выполняется как deep merge. Соседние ключи сохраняются. `null` допустим только в
зарегистрированном leaf и означает удаление override/reset к versioned default. При
пустом результате строка удаляется. Явное значение, равное default, сохраняется как
пользовательский override.

## Core registry v2

| Path | Type | Default |
|---|---|---:|
| `interface.animations` | boolean | `true` |
| `interface.transparency` | boolean | `true` |
| `notifications.browser` | boolean | `true` |
| `notifications.sound` | boolean | `true` |
| `notifications.email` | boolean | `false` |
| `privacy.show_online_status` | boolean | `false` |

Registry также поддерживает декларативные `enum`, integer range и ограниченные
типизированные list leaf для безопасного расширения будущими модулями.
Модули передают `AccountSettingDefinition[]` через DI-параметр
`accountSettingsDefinitions`. В будущем Education может зарегистрировать свои
`education.privacy.*` paths, но Core их не знает.

## API

- `GET /api/auth/me/settings` возвращает `{settings, schema_version}` с полностью
  вычисленными defaults и overrides.
- `PATCH /api/auth/me/settings` принимает непустой JSON object до 32768 байт и
  возвращает тот же эффективный контракт.
- Оба endpoint требуют authentication и всегда используют account из текущего
  principal. Идентификатор другого Account не принимается ни в path, ни в body.

Неизвестные namespace/path, некорректные типы, массивы вместо объектов, prototype-like
ключи, чрезмерная глубина и будущие schema versions отклоняются. Секретные и security
namespace отсутствуют в registry.

## Concurrency и audit

Транзакция сначала блокирует `core.accounts` через `FOR UPDATE`, затем читает текущие
overrides, выполняет merge и UPSERT/DELETE. Та же блокировка сериализует гонку первого
создания, поэтому два PATCH разных leaf не теряют изменения друг друга.

Изменение пишет `core.account_settings.updated` в общей транзакции. Audit содержит
только изменённые разрешённые paths и их override before/after; полный документ и
посторонние пользовательские данные не записываются. Идемпотентный PATCH без изменения
override не создаёт audit event.

## Не входит в контур

UI, конкретные юридические документы, module-specific бизнес-логика и отдельный
admin API не входят в этот контур. Durable consent, notification policy и
ephemeral presence описаны отдельно в `PRIVACY_COMPLIANCE_CORE.md`, `NOTIFICATIONS.md`, `PRESENCE.md`.
