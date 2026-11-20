# Схема базы данных

DBML получен из каталога PostgreSQL после применения миграций. JSON сохраняет точные типы, значения по умолчанию, ограничения, индексы, триггеры и функции. Имена полей не используются для выдумывания связей.

| Область | Диаграмма | Полный каталог |
| --- | --- | --- |
| Core | [Core_v2.dbml](core/Core_v2.dbml) | [schema.catalog.json](core/schema.catalog.json) |
| Runtime | [Platform_Runtime_v1.dbml](runtime/Platform_Runtime_v1.dbml) | Общий с Core |
| Commercial | [Commercial_ControlPlane_v2.dbml](commercial/Commercial_ControlPlane_v2.dbml) | [schema.catalog.json](commercial/schema.catalog.json) |
| Education, явно включаемый | [Education_v1.dbml](education/Education_v1.dbml) | [schema.catalog.json](education/schema.catalog.json) |
| DeviceManagement | [DeviceManagement_v1.dbml](device_management/DeviceManagement_v1.dbml) | [schema.catalog.json](device_management/schema.catalog.json) |
| Storage | [Storage_v1.dbml](storage/Storage_v1.dbml) | [schema.catalog.json](storage/schema.catalog.json) |

Core DBML описывает схему `core`; общий JSON Core содержит также `platform_runtime`. Оба файла генерируются из фактического PostgreSQL после миграций, а не поддерживаются вручную.

Текущий сохранённый срез: **Core 50 / Runtime 3 / Commercial 62 / Education 53 / DeviceManagement 11 / Storage 15 таблиц**.
Education включает технический журнал `education.module_migrations`; бизнес-таблиц 52. DeviceManagement и Storage включают собственные технические журналы `module_migrations`; бизнес-таблиц 10 и 14 соответственно.
Опциональный Education устанавливается отдельными миграциями в схему `education`;
его каталог, DBML и покрытие публикуются отдельно и не входят в срез Core/Runtime.
Проверка среза подтверждает сохранённый каталог и хеши миграций Education, но не
установку модуля в конкретном окружении.
Источник: [миграции Education](../../apps/backend/migrations/education/),
[инструкция модуля](../architecture/EDUCATION_BACKEND.md),
[покрытие Education](../education-database-coverage.md).
Следующий шаг: [этап 3 плана масштабирования](../roadmap/SCALING_PLAN.md).

[Справочник таблиц и полей](SCHEMA_REFERENCE.md) · [Модель данных](../core-data-model.md) · [Бизнес-процессы](../processes/README.md)

Для обновления по существующим рабочим PostgreSQL-контейнерам:

```powershell
python scripts/database/export_schema.py --plane core
python scripts/database/export_schema.py --plane commercial
python scripts/database/export_schema.py --plane education
python scripts/documentation/build-reference.py
python scripts/documentation/build-coverage.py
python scripts/documentation/build-education-coverage.py
npm run check:dbml --prefix scripts/documentation
python scripts/documentation/check-links.py
```

Для изолированного стенда: `python scripts/database/export_schema.py --plane education --container platform-education-local-postgres-test-1 --database platform_test --user education_fixture`. Срез Education обновлён из этого стенда после миграций 001–013; он не подтверждает обновление рабочей БД. Внешние таблицы Core на диаграмме представлены только реальными столбцами, на которые ссылаются FK, и не входят в число таблиц Education.

Экспорт читает метаданные и историю миграций, не меняя данные БД. `--check` проверяет совпадение без записи; `--from-snapshot --check` сверяет сохранённые DBML без подключения к PostgreSQL. Состав миграций и их SHA-256 входят в каталог.

Поля `person_id`, `account_id` и `deployment_id` в разных базах не означают один и тот же объект. На диаграммах показаны только реальные внешние ключи SQL. Проверки CHECK, частичные индексы и отложенные ограничения доступны в заметках DBML и полном JSON.
