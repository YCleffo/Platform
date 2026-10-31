# Документация Platform

## Порядок чтения

1. [Модель данных Platform](core-data-model.md): идентичности, владельцы данных и неизменяемая история.
2. [Бизнес-процессы Platform](processes/README.md): регистрация, оплата, смена тарифа и фоновые операции.
3. [Схема базы данных](database/README.md): DBML и полный справочник реальных объектов PostgreSQL.
4. [Конфигурация и секреты](operations/ENVIRONMENT.md) и [Обновление баз данных](operations/SAFE_DATABASE_UPGRADE.md): команды оператора.

## План развития

- [Commercial и будущий Education](roadmap/PLATFORM_ROADMAP.md).

## Frontend

| Документ | О чём |
| --- | --- |
| [Руководство по frontend](frontend/README.md) | стек, запуск, слои, сессии, account context, дизайн-система, тесты, CI, диагностика |
| [Контракт графиков и аналитики](frontend/CHARTS_CONTRACT.md) | модель данных, адаптеры, форматирование, доступность для будущих графиков |
| [README приложения](../apps/frontend/README.md) | команды запуска и проверок, прокси, ограничения |

## Архитектура

| Документ |
| --- |
| [Среда аутентификации Core и граница авторизации](architecture/AUTHENTICATION_AUTHORIZATION.md) |
| [Фоновые задания](architecture/BACKGROUND_JOBS.md) |
| [Commercial Control Plane](architecture/COMMERCIAL_CONTROL_PLANE.md) |
| [Безопасность Commercial Control Plane](architecture/COMMERCIAL_SECURITY.md) |
| [Communication в Core](architecture/COMMUNICATION.md) |
| [Архитектура backend-приложения Core](architecture/CORE_BACKEND.md) |
| [Инварианты Core](architecture/CORE_INVARIANTS.md) |
| [Архитектура баз данных](architecture/DATABASE.md) |
| [События между процессами](architecture/EVENT_CONTRACTS.md) |
| [Интернационализация Core и локализация API](architecture/INTERNATIONALIZATION.md) |
| [M2M-аутентификация сервисов](architecture/M2M_SERVICE_AUTHENTICATION.md) |
| [Правила модульности](architecture/MODULARITY.md) |
| [Политика уведомлений](architecture/NOTIFICATIONS.md) |
| [Доступ к организациям](architecture/ORGANIZATION_ACCESS.md) |
| [Присутствие и приватность](architecture/PRESENCE.md) |
| [Персональные данные в Core](architecture/PRIVACY_COMPLIANCE_CORE.md) |
| [Расширение Privacy через contributors](architecture/PRIVACY_MODULE_CONTRIBUTORS.md) |
| [Хранение, ограничения обработки и уничтожение](architecture/RETENTION_ERASURE.md) |
| [Защита транспорта, браузерная граница и секреты](architecture/SECURITY_HARDENING.md) |
| [User Settings / Privacy Core](architecture/USER_SETTINGS_PRIVACY.md) |

## Эксплуатация

| Документ |
| --- |
| [Резервирование и восстановление](operations/BACKUP_RESTORE.md) |
| [Роли PostgreSQL и минимальные привилегии](operations/DATABASE_ROLES.md) |
| [Конфигурация и секреты](operations/ENVIRONMENT.md) |
| [Компенсация внешних смен тарифа](operations/EXTERNAL_COMPENSATION.md) |
| [Критерии выпуска](operations/PRODUCTION_READINESS_PROGRESS.md) |
| [Провижининг локального Docker-развёртывания](operations/PROVISIONING.md) |
| [Безопасное обновление баз данных](operations/SAFE_DATABASE_UPGRADE.md) |

## Политики и инвентари персональных данных

| Документ |
| --- |
| [Граница персональных данных Control Plane](privacy/CONTROL_PLANE_PRIVACY.md) |
| [Инвентарь персональных данных Core](privacy/CORE_DATA_INVENTORY.md) |
| [Доказательства резидентности данных](privacy/DATA_RESIDENCY.md) |
| [Согласие на публичное распространение](privacy/DISTRIBUTION_CONSENT.md) |
| [Матрица безопасности развёртывания ИСПДн](privacy/ISPDN_SECURITY_MATRIX.md) |
| [Свидетельства уничтожения персональных данных](privacy/PERSONAL_DATA_DESTRUCTION_EVIDENCE.md) |
| [Ответ на запрос об обработке](privacy/PROCESSING_INFORMATION.md) |
| [Обработчики, получатели и трансграничная передача](privacy/PROCESSORS_AND_TRANSFERS.md) |
| [Политика производственной обработки](privacy/PRODUCTION_PROCESSING_POLICY.md) |

## Безопасность

| Документ |
| --- |
| [Аутентификация, внешние identity и MFA](security/AUTHENTICATION_AND_MFA.md) |

## Справочники

- [Справочник фактической базы данных](database/SCHEMA_REFERENCE.md).
- [Покрытие таблиц Core и Runtime](core-database-coverage.md).
