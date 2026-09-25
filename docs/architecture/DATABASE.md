# Архитектура базы данных — Core v1 и Commercial Phase 3.7

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
Symfony Commercial DB — отдельная база `platform_commercial` со схемой `commercial`.
Phase 1 создаёт 21 таблицу identity/customer, catalog, deployment, licensing,
release и reliability. Phase 2 добавляет 4 таблицы verification/session/rate
limit/session-history и расширяет identity/onboarding. Phase 3 добавляет
subscription lifecycle, effective entitlements, Usage Core и immutable invoice
snapshots, provider-neutral Payment Core и YooKassa webhook inbox; после Phase 3.7
схема содержит `commercial.* = 35` таблиц. Реализованы payment provider metadata,
payments, payment attempts и hash-only provider events. Payment methods, refunds и receipts пока
остаются только в целевой DBML v2. Подробности:
`docs/architecture/COMMERCIAL_CONTROL_PLANE.md`.
Её планы, платежи, подписки и реестр
лицензий сюда не входят.

`commercial.control_users` — отдельная центральная identity. Это не
`core.accounts`; Commercial Account также не является Diary Organization.
Auth, session и membership Control Plane не переиспользуют Diary Auth/RBAC.

## Подпись entitlement documents

Документы в будущей фазе будут подписываться асимметрично Ed25519. Сейчас
Commercial Control Plane хранит только metadata ключей и secret reference;
приватный ключ должен находиться во внешнем Vault/KMS/HSM. Он не попадает в Yii/Core,
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
