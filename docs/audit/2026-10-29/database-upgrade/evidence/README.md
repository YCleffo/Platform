# Evidence: Safe Database Upgrade

Результаты 2026-10-29 относятся к изолированным восстановленным копиям.
Оригинальные `platform` / `platform_commercial` не обновлялись. Значений
клиентских строк, токенов, паролей и сырых application/SQL logs здесь нет.

| Файл | Что подтверждает |
| --- | --- |
| [checks.json](checks.json) | Итоговые gates, границы и незасчитанные попытки |
| [snapshot-only.json](snapshot-only.json) | Core 31→33 / Commercial 25→40 на исходных данных, роли, HTTP/restart |
| [legacy-fixtures.json](legacy-fixtures.json) | Отдельный restore с синтетическими старыми записями и финансовой историей |
| [data-integrity.json](data-integrity.json) | Counts/SHA-256 каждой таблицы; исходные и синтетические baseline различаются |
| [core-32-drift.json](core-32-drift.json) | Восемь CHECK-контрактов, которые один переход 31→32 не обновлял |
| [core-migration-sources.json](core-migration-sources.json) | 33 проверенных Core migration files и SHA-256 |
| [commercial-migration-sources.json](commercial-migration-sources.json) | 40 Commercial migration files и SHA-256 |
| [source-manifest.json](source-manifest.json) | Точные bytes rehearsal-инструментов и новой 033; прежние 32 SQL не изменялись |
| [core-regression.json](core-regression.json) | Полный свежий Core run: 508 tests / 14 050 assertions, фиксированный seed |
| [core-regression-source-manifest.json](core-regression-source-manifest.json) | 898 файлов frozen regression context; dependency lock проверялся при build |
| [archive-boundary.json](archive-boundary.json) | Ограниченная проверка путей упаковки и отсутствие прежнего ZIP по старому пути |
| [originals-final-state.json](originals-final-state.json) | Финальное read-only подтверждение 31/25 и прежних повышенных ролей оригиналов |

Сырые dumps, credentials, private diagnostics и volumes сохранены локально в
ignored `runtime/database-upgrade/<run-id>/`. Их не следует добавлять в Git или
публичный source archive. Containers собственных rehearsal/regression проектов
остановлены; сохранённые копии не являются установленными production backups.

Полный Core regression использует test-only роль для guarded TestReset.
Ограниченные runtime SQL-роли проверены отдельно rollback-only use cases и
запретительными probes. Эти два вида доказательств нельзя подменять друг другом.
