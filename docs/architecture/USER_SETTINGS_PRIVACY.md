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
создаёт её. `schema_version = 1`; `SettingsSchemaRegistry::upgradeStored()` является
явной точкой будущего перехода v1 → v2 и отвергает неизвестные будущие версии.

PATCH выполняется как deep merge. Соседние ключи сохраняются. `null` допустим только в
зарегистрированном leaf и означает удаление override/reset к versioned default. При
пустом результате строка удаляется. Явное значение, равное default, сохраняется как
пользовательский override.

## Registry v1

| Path | Type | Default |
|---|---|---:|
| `interface.animations` | boolean | `true` |
| `interface.transparency` | boolean | `true` |
| `notifications.browser` | boolean | `true` |
| `notifications.sound` | boolean | `true` |
| `notifications.email` | boolean | `false` |
| `privacy.show_online_status` | boolean | `true` |
| `privacy.profile_visibility.students` | boolean | `true` |
| `privacy.profile_visibility.parents` | boolean | `true` |
| `privacy.profile_visibility.colleagues` | boolean | `true` |

Registry также поддерживает декларативные `enum`, integer range и ограниченные
типизированные list leaf для безопасного расширения будущими модулями.

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

UI, consent management, legal/privacy policy, notification delivery, presence engine,
module-specific бизнес-логика и отдельный admin API в этой фазе не реализуются.
