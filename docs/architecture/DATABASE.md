# Архитектура базы данных — этап 1

Yii runtime использует одну базу данных PostgreSQL. Модули владеют отдельными схемами PostgreSQL:

```text
platform database
├── public.*       technical migration history only
├── core.*         universal Core v1 — exactly 23 tables
├── platform_runtime.*  installation identity and signed entitlement snapshots — 2 tables
├── education.*    later
├── games.*        later
├── chat.*         later
├── storage.*      later
├── search.*       later
└── ...
```

## Зафиксированная версия Core v1

Схема `core.*` содержит ровно 23 основные таблицы. Добавление Games, Education,
Search или брендинга **не означает**, что в Core нужно добавлять таблицы конкретного продукта.
Для общих настроек установки используется `core.system_settings`.
Идентичность установки и локальные подписанные коммерческие разрешения находятся в
`platform_runtime.*`, описанной отдельно в `docs/database/runtime/Platform_Runtime_v1.dbml`.
Будущая Symfony Commercial DB — отдельная база; её планы, платежи, подписки и реестр
лицензий сюда не входят.

## Подпись entitlement documents

Документы подписываются асимметрично, предпочтительно Ed25519. Приватный ключ
хранится только в будущем Commercial Control Plane: он не попадает в Yii/Core,
On-Prem Docker image или Git. Локальный runtime получает только доверенные публичные
ключи для проверки. PostgreSQL обеспечивает порядок применения через монотонный
`current_entitlement_sequence`; проверка подписи и доверия к ключу должна выполняться
до перевода документа в `applied`.

## Крупные таблицы в будущем

Таблицы посещаемости, снимков участников занятий, сообщений чата, событий аналитики,
аудита/событий и аналогичные со временем могут стать крупными. PostgreSQL рассчитан на такую нагрузку.
Начинаем с обычных таблиц и корректных индексов; партиционирование добавим, когда его оправдают реальные объёмы и планы запросов.

## Техническая телеметрия

Prometheus/OpenTelemetry/Grafana — независимая инфраструктура. Данные о CPU, задержках,
трассировках и метриках запросов не записываются в `core.audit_events`.

## Резервное копирование и восстановление

`scripts/backup-db.ps1` создаёт бинарный PostgreSQL custom-format dump с расширением `.dump`
через `pg_dump -Fc`. Это не plain SQL: его нельзя восстанавливать командой `psql < file.sql`.
Для проверки содержимого используется `pg_restore --list backup.dump`, для восстановления —
`pg_restore --clean --if-exists --no-owner --dbname=<database> backup.dump` с заранее проверенными
параметрами подключения и целевой базой.
