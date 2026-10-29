# Архитектура баз данных

| Владелец | База | Схемы | Миграции |
| --- | --- | --- | --- |
| Yii Core | `platform` | `core`, `platform_runtime` | `apps/backend/migrations/sql` |
| Symfony Commercial | `platform_commercial` | `commercial` | `apps/control-plane/migrations` |

Core хранит Person, Account, организации, права, сессии, коммуникации и персональные данные. `platform_runtime` хранит идентичность установки и проверенные документы прав. Commercial хранит собственные identities, коммерческие аккаунты, каталог, подписки, счета, платежи, возвраты и операции установки.

Базы не имеют общих транзакций, cross-database FK или прямых запросов друг к другу. Совпадение email и UUID не связывает identities. Межсервисные операции используют аутентифицированные API, версионированные события и подписанные документы.

PostgreSQL определяет durable state. Redis хранит временные счётчики, блокировки и кэш. Потеря Redis не должна менять финансовую историю или подписанные права.

Уникальность, CHECK, deferred constraint triggers и append-only guards являются частью модели. Они сохраняются в JSON-каталогах; DBML отражает физические таблицы и FK, но не заменяет исполнение миграций.

[DBML и каталоги](../database/README.md) · [Справочник столбцов](../database/SCHEMA_REFERENCE.md) · [Обновление](../operations/SAFE_DATABASE_UPGRADE.md) · [Инварианты](CORE_INVARIANTS.md)
