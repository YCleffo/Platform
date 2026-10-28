# Модель данных Platform

Структура определяется миграциями и [PostgreSQL-каталогами](database/README.md).
Все поля, nullable, defaults и реальные FK описаны в
[справочнике](database/SCHEMA_REFERENCE.md). DBML не заменяет SQL guards.

## Пять разных идентичностей

`core.persons` описывает человека. `core.accounts` — учётную запись с отдельным
типом, статусом, credentials и sessions. Для human Account нужен Person;
service/system Account не использует human login. Контакт Person не становится
login identifier автоматически.

`commercial.control_users` — пользователи центрального кабинета со своими
verification, sessions и MFA. `commercial.commercial_accounts` — коммерческие
клиенты; доступ задаётся membership. `core.organizations` — организации внутри
конкретного runtime. Соответствие по email, UUID или имени не подразумевается.

## Владельцы данных

| Владелец | Данные | Граница записи |
| --- | --- | --- |
| Person / Account | Люди, контакты, account lifecycle, настройки | Core API и application services |
| Auth / RBAC | Identifiers, credentials, challenges, sessions, passkeys, MFA, roles и permissions | Auth use cases и permission checks |
| Organization | Организации и memberships | Permissions вместе с organization scope |
| Settings / Module | Реестр модулей, настройки, feature flags | Namespace, permission и secret policy |
| Privacy | Consent, representations, subject requests, deadlines, holds, retention, incidents, erasure и evidence | Оркестраторы и contributors |
| Audit / Messaging | Аудит, outbox и inbox | Транзакция бизнес-операции, затем worker |
| PlatformRuntime | Installation identity, verified documents, worker status | Bootstrap, подпись, sequence и scheduler |
| Commercial | Каталог, subscriptions, usage, invoices, payments, receipts, refunds, deployments и licensing | Отдельное Symfony-приложение и БД |

Карта находится в [покрытии](core-database-coverage.md). Таблица не получает
публичный CRUD только потому, что она существует в БД.

## Неизменяемая история

Опубликованный тариф и его цены/features задают исторический договор. Новые
условия создают новую версию. Subscription items и issued invoice lines
сохраняют snapshots; новая версия каталога не пересчитывает старый счёт.
Деньги хранятся в точных минимальных единицах валюты. Повтор settlement,
webhook, refund или worker restart не создаёт новый финансовый эффект.

PostgreSQL хранит durable историю. Redis хранит временное состояние. Потеря
Redis не означает потерю платежа. Commercial не читает Core DB, Core не получает
commercial signing private key. Entitlement document связывает installation
и commercial state через проверенный контракт, а не SQL join.

[Графики процессов](processes/README.md) показывают регистрацию, письма, auth/MFA,
permissions, каталог, покупку, plan changes, renewal, refund, provisioning,
privacy и workers. Стрелка процесса означает действие, FK означает связь строк.

Результаты — в [аудите](audit/2026-10-28/REPORT.md), release verdict — в
[журнале готовности](operations/PRODUCTION_READINESS_PROGRESS.md).
