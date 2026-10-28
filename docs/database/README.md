# База данных: что действительно существует

Срез 2026-10-28 получен чтением каталогов PostgreSQL после применения всех
миграций: Core 32/32, Commercial 40/40. В срезе 50 таблиц `core`, 3
`platform_runtime`, 60 `commercial`. Это инвентарь конкретной ревизии.

| Файл | Назначение |
| --- | --- |
| [Core_v2.dbml](core/Core_v2.dbml) | Актуальная физическая модель Core |
| [Core_v1_FINAL.dbml](core/Core_v1_FINAL.dbml) | Совместимый старый путь к той же актуальной модели |
| [Runtime DBML](runtime/Platform_Runtime_v1.dbml) | Installation, entitlement documents и background job status |
| [Commercial DBML](commercial/Commercial_ControlPlane_v2.dbml) | Только фактически созданные таблицы |
| [Core catalog](core/schema.catalog.json) | Столбцы, enums, defaults, constraints, indexes, triggers, functions и migration hashes |
| [Commercial catalog](commercial/schema.catalog.json) | Metadata отдельной Commercial DB |
| [Справочник всех полей](SCHEMA_REFERENCE.md) | Все таблицы, столбцы и физические связи |
| [Процессы](../processes/README.md) | Как данные изменяются в бизнес-сценариях |

`.dbdiagram` рядом с DBML хранит настройки представления редактора. Миграции
остаются источником истины. Генератор читает уже созданные PostgreSQL objects,
не разбирает SQL регулярными выражениями. Запланированных таблиц и выдуманных
cross-database FK в актуальных DBML нет.

DBML передаёт поля, enum, nullable, defaults, PK, обычные indexes и реальные FK
с delete/update actions. Частичные/вычисляемые indexes, INCLUDE, deferred checks,
constraint triggers и функции требуют PostgreSQL-семантики; точный SQL хранится
в table notes и JSON. DBML нельзя использовать как production migration.

## Обновление и проверка

Вначале примените миграции к изолированным test DB. Из корня проекта:

```powershell
python scripts/database/export_schema.py --plane core
python scripts/database/export_schema.py --plane commercial
python scripts/documentation/build-reference.py

# Проверка против PostgreSQL:
python scripts/database/export_schema.py --plane core --check
python scripts/database/export_schema.py --plane commercial --check

# Проверка сохранённого среза без Docker:
python scripts/database/export_schema.py --plane core --from-snapshot --check
python scripts/database/export_schema.py --plane commercial --from-snapshot --check
python scripts/documentation/build-reference.py --check

npm ci --prefix scripts/documentation --ignore-scripts
npm run check:dbml --prefix scripts/documentation
```

Генератор сверяет applied migration names с текущими файлами, записывает
SHA-256 исходников и отказывается публиковать неполный набор. `check:dbml`
использует настоящий parser DBML и сверяет поля, nullable, таблицы и FK.
Полное совпадение текста проверяет `export_schema.py --check`.

Для другого локального PostgreSQL предусмотрены `--psql`, `--host`, `--port`,
`--database`, `--user`; пароль передаётся средствами libpq. Старые версии модели
доступны через Git. Назначение доменов описано в [модели Core](../core-data-model.md).
