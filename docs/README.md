# Документация Platform

Начните с [бизнес-процессов](processes/README.md), [актуальной БД](database/README.md)
и [отчёта проверки](audit/2026-10-28/REPORT.md). Общий release verdict находится в
[журнале готовности](operations/PRODUCTION_READINESS_PROGRESS.md).

Phase и датированные записи описывают историю; они не заменяют текущие migrations,
contracts и runtime evidence. Privacy/operations templates не означают, что production policy уже утверждена.

## Все документы

| Раздел | Документ |
| --- | --- |
| architecture | [Среда аутентификации Core и граница авторизации](architecture/AUTHENTICATION_AUTHORIZATION.md) |
| architecture | [Background jobs](architecture/BACKGROUND_JOBS.md) |
| architecture | [Commercial Control Plane — текущая реализация](architecture/COMMERCIAL_CONTROL_PLANE.md) |
| architecture | [Commercial Control Plane security boundary](architecture/COMMERCIAL_SECURITY.md) |
| architecture | [Communication в Core](architecture/COMMUNICATION.md) |
| architecture | [Core consent evidence](architecture/CONSENT.md) |
| architecture | [Архитектура backend-приложения Core](architecture/CORE_BACKEND.md) |
| architecture | [Platform Core invariants](architecture/CORE_INVARIANTS.md) |
| architecture | [Архитектура баз данных](architecture/DATABASE.md) |
| architecture | [Data subject requests and processing blocks (Phase 4.1 B1)](architecture/DATA_SUBJECT_REQUESTS.md) |
| architecture | [Controlled personal data erasure (B4)](architecture/ERASURE.md) |
| architecture | [Cross-process Event Contracts v1](architecture/EVENT_CONTRACTS.md) |
| architecture | [Интернационализация Core и локализация API](architecture/INTERNATIONALIZATION.md) |
| architecture | [Интернационализация и безопасность Core](architecture/INTERNATIONAL_SECURITY_CORE.md) |
| architecture | [Legal holds](architecture/LEGAL_HOLD.md) |
| architecture | [Generic legal representation](architecture/LEGAL_REPRESENTATION.md) |
| architecture | [M2M service authentication foundation](architecture/M2M_SERVICE_AUTHENTICATION.md) |
| architecture | [Правила модульности](architecture/MODULARITY.md) |
| architecture | [Notification Policy](architecture/NOTIFICATIONS.md) |
| architecture | [Organization access foundation](architecture/ORGANIZATION_ACCESS.md) |
| architecture | [Personal data correction (Phase 4.1 B2)](architecture/PERSONAL_DATA_CORRECTION.md) |
| architecture | [Personal data export (Phase 4.1 B2)](architecture/PERSONAL_DATA_EXPORT.md) |
| architecture | [Presence and Privacy](architecture/PRESENCE.md) |
| architecture | [Privacy Core boundary](architecture/PRIVACY_COMPLIANCE_CORE.md) |
| architecture | [Privacy incidents](architecture/PRIVACY_INCIDENTS.md) |
| architecture | [Privacy contributor contracts](architecture/PRIVACY_MODULE_CONTRIBUTORS.md) |
| architecture | [Processing termination](architecture/PROCESSING_TERMINATION.md) |
| architecture | [Структура репозитория](architecture/PROJECT_TREE.md) |
| architecture | [Retention and erasure boundary](architecture/RETENTION_ERASURE.md) |
| architecture | [Retention evaluation](architecture/RETENTION_EVALUATION.md) |
| architecture | [Защита транспорта, браузерная граница и секреты](architecture/SECURITY_HARDENING.md) |
| architecture | [User Settings / Privacy Core](architecture/USER_SETTINGS_PRIVACY.md) |
| audit/2026-10-28 | [Проверка Platform — 2026-10-28](audit/2026-10-28/REPORT.md) |
| audit/2026-10-28/evidence | [Evidence аудита 2026-10-28](audit/2026-10-28/evidence/README.md) |
| audit | [Phase 4.1 Core privacy file audit](audit/PHASE_4_1_CORE_PRIVACY_FILE_AUDIT.md) |
| . | [Модель данных Platform](core-data-model.md) |
| . | [Покрытие таблиц Core и Runtime](core-database-coverage.md) |
| database | [База данных: что действительно существует](database/README.md) |
| database | [Справочник фактической базы данных](database/SCHEMA_REFERENCE.md) |
| database/core | [Поля и связи Core на русском языке](database/core/Core_v1_FINAL_RU.md) |
| database/core | [Core и совместимые пути DBML](database/core/README.md) |
| operations | [Core and Control Plane backup and restore drill](operations/BACKUP_RESTORE.md) |
| operations | [External plan-change compensation](operations/EXTERNAL_COMPENSATION.md) |
| operations | [Production readiness: состояние работ](operations/PRODUCTION_READINESS_PROGRESS.md) |
| operations | [Local Docker deployment provisioning](operations/PROVISIONING.md) |
| privacy | [Control Plane personal data boundary](privacy/CONTROL_PLANE_PRIVACY.md) |
| privacy | [Core personal data inventory (Phase 4.1 review)](privacy/CORE_DATA_INVENTORY.md) |
| privacy | [Data residency evidence](privacy/DATA_RESIDENCY.md) |
| privacy | [Consent for public distribution](privacy/DISTRIBUTION_CONSENT.md) |
| privacy | [ISPDn deployment security matrix](privacy/ISPDN_SECURITY_MATRIX.md) |
| privacy | [Personal data destruction evidence (B4)](privacy/PERSONAL_DATA_DESTRUCTION_EVIDENCE.md) |
| privacy | [Processing information response](privacy/PROCESSING_INFORMATION.md) |
| privacy | [Processors, recipients and outbound transfer](privacy/PROCESSORS_AND_TRANSFERS.md) |
| privacy | [Production processing policy](privacy/PRODUCTION_PROCESSING_POLICY.md) |
| processes | [Бизнес-процессы Platform](processes/README.md) |
| security | [Аутентификация, внешние identity и MFA](security/AUTHENTICATION_AND_MFA.md) |
