# Архитектура баз данных

Требования: CORE-01/02, COMM-01/02, CAT-01/02, BILL-01/02/04,
PAY-03/04/05/06 и ENT-01/02/03. Границы закреплены в
[CORE_INVARIANTS.md](CORE_INVARIANTS.md).

Yii Core владеет PostgreSQL DB со схемами `core` и `platform_runtime`.
Symfony Commercial Control Plane владеет отдельной DB со схемой `commercial`.
У каждой установки Core своя installation identity. Development, штатные tests
и нагрузочный стенд используют разные volumes и credentials.

Процессы не получают credentials чужой БД, не делают cross-database joins
и не создают общую распределённую транзакцию. Согласованность достигается
versioned API, durable operations, idempotency, reconciliation и подписанными
документами. [График границ](../processes/svg/01-boundaries.svg).

## Миграции и структура

| Контракт | Источник | Техническая история |
| --- | --- | --- |
| Core / Runtime | `apps/backend/migrations/sql/*.sql` | `public.platform_migrations` |
| Commercial | `apps/control-plane/migrations/Version*.php` | `public.doctrine_migration_versions` |

Core runner применяет SQL-файл и запись истории в одной транзакции. Doctrine
управляет Commercial migrations. Expand/migrate/contract выполняется отдельными
релизами. DBML не является migration runner. Технические таблицы `public` не
относятся к бизнес-модели.

На срезе 2026-10-28 применены 32 Core и 40 Commercial миграций.
[DBML, каталоги и справочник](../database/README.md) генерируются из PostgreSQL.
Будущие продуктовые schemas в текущий инвентарь не добавляются.

## Деньги и права

Catalog publication замораживает terms; invoice и subscription snapshots
сохраняют старые условия. Upgrade/downgrade и proration реализованы. Usage
counters существуют; наличие counter не доказывает автоматическое денежное
начисление overage.

`platform_runtime.installations` хранит identity и sequence,
`entitlement_documents` — проверенные документы, `background_job_status` —
heartbeats. Commercial хранит exact canonical bytes и подпись выданного документа.
Core проверяет trust, подпись, installation, validity и sequence до применения.
Приватного signing key в Core нет.

Redis не является durable финансовой БД. Audit и outbox не хранят credentials
или произвольные полные HTTP payload. Метрики CPU/latency относятся к observability.

[Аудит](../audit/2026-10-28/REPORT.md) отделяет local runtime/load evidence от
production readiness. [Нагрузочный стенд](../../scripts/load/README.md) использует
отдельный Compose project. [Backup и restore](../operations/BACKUP_RESTORE.md)
требуют реального восстановления. HA и PITR — отдельные операционные проверки.
