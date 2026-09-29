# Архитектура базы данных — Core v2 и Commercial Phase 4.0

## Phase 4.6 signed deployment documents

Commercial migration 024 adds immutable `commercial.deployment_entitlement_documents`.
It stores exact canonical v1 bytes (`bytea`), SHA-256 digest, Ed25519 signature,
signing key ID, source fingerprint, installation ID, validity window and a
unique `(deployment_id, sequence)`. The issuer locks the deployment row and
inserts the document and `entitlement.issued` v1 outbox event atomically.
Core retains its existing `platform_runtime.entitlement_documents` schema:
verified exact bytes are encoded in `payload.canonicalDocumentBase64`, and
its trigger advances `installations.current_entitlement_sequence` on apply.
No private signing key is stored in Core.

Yii runtime использует одну базу данных PostgreSQL. Модули владеют отдельными схемами PostgreSQL:

```text
platform database
├── public.*       technical migration history only
├── core.*         universal Core — 25 tables after migration 018
├── platform_runtime.*  installation identity and signed entitlement snapshots — 2 tables
├── education.*    later
├── games.*        later
├── chat.*         later
├── storage.*      later
├── search.*       later
└── ...
```

## Зафиксированная версия Core v2

Схема `core.*` содержит 26 базовых таблиц после миграции 020. Миграция 017 добавляет
`core.account_settings`, миграция 018 — историю authenticated согласий, а миграции 019–020
развивают её в `core.privacy_consents` и добавляют `core.legal_representations`.
Исторический артефакт `Core_v1_FINAL.dbml` не меняется;
актуальная модель — `docs/database/core/Core_v2.dbml`. Runtime/CI проверяют наличие
обязательных объектов и применённых миграций, а не фиксированное число таблиц.
Добавление Games, Education,
Search или брендинга **не означает**, что в Core нужно добавлять таблицы конкретного продукта.
Для общих настроек установки используется `core.system_settings`.
Идентичность установки и локальные подписанные коммерческие разрешения находятся в
`platform_runtime.*`, описанной отдельно в `docs/database/runtime/Platform_Runtime_v1.dbml`.
Symfony Commercial DB — отдельная база `platform_commercial` со схемой `commercial`.
Phase 1 создаёт 21 таблицу identity/customer, catalog, deployment, licensing,
release и reliability. Phase 2 добавляет 4 таблицы verification/session/rate
limit/session-history и расширяет identity/onboarding. Phase 3 добавляет
subscription lifecycle, effective entitlements, Usage Core, immutable invoice
snapshots, provider-neutral Payment Core, YooKassa webhook inbox и Fiscalization
Core; после Phase 4.0 схема содержит `commercial.* = 39` таблиц и 20 миграций. Реализованы
payment provider metadata, payments, payment attempts, hash-only provider events,
immutable payment/refund receipt obligations, renewal periods и refund item
snapshots. Payment methods, plan switching и usage-based billing пока остаются целевыми сущностями. Подробности:
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
