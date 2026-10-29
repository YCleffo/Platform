# Документация Platform

## Порядок чтения

1. [Модель данных Platform](core-data-model.md): identities, владельцы данных и неизменяемая история.
2. [Бизнес-процессы Platform](processes/README.md): диаграммы регистрации, оплаты, смены тарифа и фоновых операций.
3. [Схема базы данных](database/README.md): три DBML и полный справочник реальных объектов PostgreSQL.
4. [Конфигурация и секреты](operations/ENVIRONMENT.md) и [Обновление баз данных](operations/SAFE_DATABASE_UPGRADE.md): команды оператора.

## Архитектура

| Документ |
| --- |
| [Среда аутентификации Core и граница авторизации](architecture/AUTHENTICATION_AUTHORIZATION.md) |
| [Background jobs](architecture/BACKGROUND_JOBS.md) |
| [Commercial Control Plane](architecture/COMMERCIAL_CONTROL_PLANE.md) |
| [Безопасность Commercial Control Plane](architecture/COMMERCIAL_SECURITY.md) |
| [Communication в Core](architecture/COMMUNICATION.md) |
| [Архитектура backend-приложения Core](architecture/CORE_BACKEND.md) |
| [Platform Core invariants](architecture/CORE_INVARIANTS.md) |
| [Архитектура баз данных](architecture/DATABASE.md) |
| [События между процессами](architecture/EVENT_CONTRACTS.md) |
| [Интернационализация и безопасность Core](architecture/INTERNATIONAL_SECURITY_CORE.md) |
| [Интернационализация Core и локализация API](architecture/INTERNATIONALIZATION.md) |
| [M2M service authentication foundation](architecture/M2M_SERVICE_AUTHENTICATION.md) |
| [Правила модульности](architecture/MODULARITY.md) |
| [Notification Policy](architecture/NOTIFICATIONS.md) |
| [Organization access foundation](architecture/ORGANIZATION_ACCESS.md) |
| [Presence and Privacy](architecture/PRESENCE.md) |
| [Персональные данные в Core](architecture/PRIVACY_COMPLIANCE_CORE.md) |
| [Расширение Privacy через contributors](architecture/PRIVACY_MODULE_CONTRIBUTORS.md) |
| [Структура репозитория](architecture/PROJECT_TREE.md) |
| [Хранение, ограничения обработки и уничтожение](architecture/RETENTION_ERASURE.md) |
| [Защита транспорта, браузерная граница и секреты](architecture/SECURITY_HARDENING.md) |
| [User Settings / Privacy Core](architecture/USER_SETTINGS_PRIVACY.md) |

## Эксплуатация

| Документ |
| --- |
| [Резервирование и восстановление](operations/BACKUP_RESTORE.md) |
| [Конфигурация и секреты](operations/ENVIRONMENT.md) |
| [External plan-change compensation](operations/EXTERNAL_COMPENSATION.md) |
| [Критерии выпуска](operations/PRODUCTION_READINESS_PROGRESS.md) |
| [Local Docker deployment provisioning](operations/PROVISIONING.md) |
| [Обновление баз данных](operations/SAFE_DATABASE_UPGRADE.md) |

## Политики и инвентари персональных данных

| Документ |
| --- |
| [Control Plane personal data boundary](privacy/CONTROL_PLANE_PRIVACY.md) |
| [Core personal data inventory](privacy/CORE_DATA_INVENTORY.md) |
| [Data residency evidence](privacy/DATA_RESIDENCY.md) |
| [Consent for public distribution](privacy/DISTRIBUTION_CONSENT.md) |
| [ISPDn deployment security matrix](privacy/ISPDN_SECURITY_MATRIX.md) |
| [Свидетельства уничтожения персональных данных](privacy/PERSONAL_DATA_DESTRUCTION_EVIDENCE.md) |
| [Processing information response](privacy/PROCESSING_INFORMATION.md) |
| [Processors, recipients and outbound transfer](privacy/PROCESSORS_AND_TRANSFERS.md) |
| [Production processing policy](privacy/PRODUCTION_PROCESSING_POLICY.md) |

## Безопасность

| Документ |
| --- |
| [Аутентификация, внешние identity и MFA](security/AUTHENTICATION_AND_MFA.md) |

## Справочники

- [Справочник фактической базы данных](database/SCHEMA_REFERENCE.md).
- [Покрытие таблиц Core и Runtime](core-database-coverage.md).
- [Core](database/core/README.md).
