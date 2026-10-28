# Core и совместимые пути DBML

[Core_v2.dbml](Core_v2.dbml) генерируется из фактического PostgreSQL catalog.
[Core_v1_FINAL.dbml](Core_v1_FINAL.dbml) сохранён как совместимый путь к той же
актуальной схеме. Отдельного устаревшего набора таблиц здесь больше нет.

Полный [справочник столбцов](../SCHEMA_REFERENCE.md) и
[инструкция обновления](../README.md) относятся к обоим файлам.
Исторические версии сохранены в Git. SQL-миграции — `apps/backend/migrations/sql`.
