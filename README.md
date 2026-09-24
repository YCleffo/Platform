# Platform

**Platform** — модульная backend-платформа для построения прикладных систем поверх общего защищённого Core.

Core отвечает за идентичность пользователей и аккаунтов, аутентификацию, авторизацию, безопасность, конфигурацию, локализацию, аудит и инфраструктурные события. Предметные области подключаются поверх Core отдельными модулями и не должны размывать его границы.

Проект развивается как долгоживущая платформа, а не как одноразовое приложение под конкретный продукт.

---

## Основные принципы

- `Person != Account`
- Core не зависит от предметных модулей
- PostgreSQL — основной transactional source of truth
- Redis используется только для временного состояния, rate limiting, cache и locks
- security-critical данные не должны попадать в публичные API, логи и telemetry
- authentication и authorization разделены
- все чувствительные операции имеют явный lifecycle
- миграции являются частью контракта платформы
- изменения Core должны быть обратно совместимыми либо оформляться явной миграцией
- прикладные модули зависят от Core, но Core не зависит от них

Направление зависимостей:

```text
Education
Games
Search
Chat
Storage
Calls
Devices
    │
    └──────────────► Core
```

Обратная зависимость запрещена.

---

# Что находится в репозитории

```text
Platform/
├── .github/
│   └── workflows/
│
├── apps/
│   ├── backend/              # Yii3 Core API
│   ├── frontend/             # frontend application
│   ├── agent/                # platform agent
│   └── control-plane/        # Symfony Commercial Control Plane
│
├── contracts/                # межмодульные и внешние контракты
│
├── docs/
│   ├── architecture/
│   ├── database/
│   ├── security/
│   ├── core-data-model.md
│   └── core-database-coverage.md
│
├── infra/
│   └── postgres/             # PostgreSQL infrastructure и migration runner
│
├── scripts/                  # development / backup / recovery scripts
│
├── compose.yaml
├── .env.example
└── README.md
```

---

# Backend

Backend построен на **Yii3** и работает внутри **FrankenPHP**.

Основные области Core:

```text
Core/
├── Account
├── Audit
├── Auth
├── Client
├── Communication
├── Geo
├── Internationalization
├── Messaging
├── Module
├── Network
├── Person
├── Rbac
├── Security
├── Settings
├── Shared
└── Time
```

Архитектура внутри подсистем разделяется на API, Application и Infrastructure слои там, где это необходимо.

---

# Возможности Core

## Identity

Core разделяет физического человека и его системную учётную запись.

```text
Person
   │
   └── Account
          │
          ├── Credentials
          ├── Roles
          ├── Sessions
          └── Authentication methods
```

Один `Person` не является синонимом `Account`.

Это позволяет поддерживать:

- человеческие аккаунты;
- сервисные аккаунты;
- различные authentication methods;
- отдельный lifecycle человека и системной учётной записи.

---

## Authentication

В Core реализован полноценный authentication runtime.

Поддерживаются:

- password authentication;
- access / refresh sessions;
- activation flow;
- password reset;
- session revocation;
- security stamp;
- session expiration;
- MFA;
- TOTP;
- recovery codes;
- MFA recovery;
- WebAuthn / Passkeys;
- external identities;
- Google OIDC;
- Telegram OIDC;
- безопасная привязка и отвязка внешних identity;
- recent authentication policies;
- authentication rate limiting.

Authentication API находится преимущественно в:

```text
apps/backend/src/Core/Auth/
```

---

## Passkeys / WebAuthn

Реализован runtime для WebAuthn / Passkeys:

- registration ceremony;
- authentication ceremony;
- discoverable credentials;
- credential ownership;
- replay protection;
- challenge lifecycle;
- sign counter validation;
- user verification;
- RP ID / Origin validation;
- credential rename;
- credential revoke;
- защита последнего authentication method;
- интеграция с MFA.

---

## MFA

Поддерживаются:

- TOTP;
- encrypted TOTP secrets;
- recovery codes;
- MFA challenges;
- MFA-aware authentication;
- recovery lifecycle;
- защита external-only аккаунтов;
- повторная external authentication при чувствительных recovery-сценариях.

---

## RBAC

Core содержит централизованную систему ролей и permissions.

Базовые системные роли:

```text
core.superadmin
core.admin
```

Поддерживаются:

- roles;
- permissions;
- role → permission;
- account → role;
- permission-based authorization;
- централизованный superadmin bypass;
- защита критических системных ролей.

---

## Configuration Runtime

Core предоставляет runtime-конфигурацию для платформы:

- module registry;
- module lifecycle;
- module settings;
- system settings;
- feature flags;
- ownership validation;
- permission boundaries;
- audit изменений.

Секретоподобные значения запрещено хранить в обычном settings runtime.

---

## Internationalization

Core содержит централизованную инфраструктуру локализации.

Поддерживаются:

- `ru-RU`;
- `en-US`;
- нормализация locale;
- `Accept-Language`;
- client locale;
- account locale;
- локализованные API errors;
- локализованные transactional email templates;
- timezone handling.

---

## Security

Security является частью архитектуры Core, а не отдельным middleware «на потом».

Используются:

- opaque access tokens;
- SHA-256 token hashing;
- Argon2id password hashing;
- authenticated encryption для чувствительных payload;
- Sodium;
- rate limiting;
- security stamp;
- session lifecycle;
- client/device context;
- trusted proxy handling;
- audit events;
- secure HTTP headers;
- cache prevention для sensitive API;
- generic authentication errors;
- protection against credential enumeration.

Security-critical API не должен раскрывать:

- password hashes;
- raw tokens;
- TOTP secrets;
- recovery codes;
- security stamps;
- internal exception traces;
- database errors;
- filesystem paths;
- secrets из environment.

---

# Database

Основная база данных — **PostgreSQL 18**.

Core использует отдельную схему:

```text
core
```

Техническая история SQL-миграций находится в:

```text
public.platform_migrations
```

`platform_migrations` не относится к бизнес-таблицам Core.

`platform_runtime.installations` хранит единственную идентичность этой установки,
а `platform_runtime.entitlement_documents` — локальные подписанные snapshots
разрешений. Они описаны отдельно от Core в
`docs/database/runtime/Platform_Runtime_v1.dbml`. Commercial DB Symfony —
отдельная база; платёжные и subscription таблицы в Core не входят.

---

## Core schema

Core содержит **23 базовые таблицы**:

```text
persons
person_contacts

accounts

auth_identifiers
auth_password_credentials
auth_providers
auth_external_identities
auth_passkeys
auth_totp_factors
auth_recovery_codes
auth_sessions
auth_challenges

roles
permissions
role_permissions
account_roles

modules
module_settings
feature_flags
system_settings

audit_events
outbox_events
inbox_events
```

Последующие миграции могут расширять эти таблицы, добавлять constraints, indexes и runtime-возможности без нарушения базового Core contract.

---

# Миграции

SQL-миграции находятся в:

```text
apps/backend/migrations/sql/
```

Migration runner:

```text
infra/postgres/migrate.sh
```

Каждая миграция:

1. применяется только один раз;
2. выполняется транзакционно;
3. записывается в `public.platform_migrations`;
4. должна завершиться полностью либо полностью откатиться.

Применить отсутствующие миграции:

```bash
docker compose run --rm db-migrate
```

Проверить состояние на Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\db-status.ps1
```

---

# Docker

Локальная среда полностью контейнеризирована.

Основные services:

| Service      | Назначение                                 |
| ------------ | ------------------------------------------ |
| `postgres`   | PostgreSQL 18                              |
| `redis`      | cache, locks, rate limits, temporary state |
| `mailpit`    | локальный SMTP и просмотр писем            |
| `db-migrate` | SQL migration runner                       |
| `backend`    | Yii3 API + FrankenPHP                      |

Проверить состояние:

```bash
docker compose ps
```

Посмотреть логи backend:

```bash
docker compose logs -f backend
```

---

# Быстрый старт

## Требования

Необходимо установить:

- Git;
- Docker;
- Docker Compose.

Для работы с PostgreSQL также удобно использовать DBeaver или другой SQL-клиент.

PHP, Composer и PostgreSQL устанавливать непосредственно в host OS не требуется — development runtime работает через Docker.

---

## Linux

Рекомендуемая development-среда для backend — Linux.

```bash
git clone https://github.com/YCleffo/Platform.git
cd Platform
```

Создать environment:

```bash
cp .env.example .env
```

Заполнить обязательные secrets в `.env`.

Затем:

```bash
docker compose pull

docker compose build backend

docker compose run --rm --no-deps backend \
  composer install --no-interaction --prefer-dist

docker compose run --rm --no-deps backend \
  composer yii-config-rebuild

docker compose up -d
```

Проверить:

```bash
docker compose ps
```

Backend:

```text
http://127.0.0.1:8080
```

Health endpoint:

```text
http://127.0.0.1:8080/health
```

Mailpit:

```text
http://127.0.0.1:8025
```

---

## Windows

Для Windows предусмотрен bootstrap script:

```powershell
Set-Location C:\Platform

powershell -ExecutionPolicy Bypass `
  -File .\scripts\first-start.ps1
```

Скрипт:

- проверяет Docker;
- поднимает PostgreSQL и Redis;
- применяет миграции;
- проверяет Core schema;
- собирает backend;
- устанавливает Composer dependencies;
- перестраивает Yii configuration;
- запускает backend;
- проверяет соединение Yii → PostgreSQL;
- проверяет `/health`.

---

# Environment

Шаблон:

```text
.env.example
```

Локальный файл:

```text
.env
```

`.env` не должен попадать в Git.

Основные группы параметров:

```text
POSTGRES_*
REDIS_*
SMTP_*
MAIL_*

APP_*

AUTH_*

GEOIP_*

SECURITY_*
```

Secrets нельзя:

- коммитить;
- записывать в документацию;
- добавлять в test fixtures;
- выводить в exception response;
- сохранять в публичных логах.

---

# DBeaver

Стандартное локальное подключение:

```text
Host:     localhost
Port:     5432
Database: platform
User:     platform_app
Password: значение POSTGRES_PASSWORD из .env
```

После подключения:

```text
platform
└── Schemas
    ├── core
    │   └── ...
    │
    └── public
        └── platform_migrations
```

---

# Тестирование

Backend использует:

- Codeception;
- PHPUnit;
- Psalm;
- Composer Dependency Analyser;
- PHP CS Fixer;
- Rector.

Основные test suites:

```text
tests/
├── Api/
├── Console/
├── Functional/
└── Unit/
```

Все тестовые suites запускаются только в изолированном `backend-test`; development-контейнер `backend` для API/интеграционных тестов не используется.

Symfony Commercial Control Plane (`apps/control-plane`) — отдельное приложение
на Symfony 7.4/PHP 8.3 с собственной БД `platform_commercial`, схемой
`commercial` и отдельным Docker network. Yii/Diary DB и Commercial DB не
разделяют credentials. Phase 3.5 содержит 14 миграций и 31 Commercial таблицу:
central Control User, hash-only verification, rotating server-side sessions,
Commercial Account с атомарным owner membership, billing onboarding и
published plan catalog, draft/active subscriptions и immutable subscription
items. Effective entitlements вычисляются как derived read model из активной
подписки и plan features; Usage Core ведёт атомарные hard-quota counters в
границах subscription period. Billing Core атомарно выпускает initial invoice с
immutable snapshots billing profile, плана, версии, цены и количества. Mutable
`commercial.entitlements` table отсутствует.
Control User != Diary User; Commercial Account != Diary Organization; Control
Plane Auth != Diary Auth. Invoice не является payment; Payment Core, провайдеры,
renewal и usage-based billing остаются отложенными.
См. `docs/architecture/COMMERCIAL_CONTROL_PLANE.md` и
`docs/architecture/COMMERCIAL_SECURITY.md`.

Тесты Control Plane запускаются только через `control-plane-test` и
`control-postgres-test`. Установите локальный `CONTROL_TEST_PASSWORD` и
выполните:

```bash
bash ./scripts/control-test-start.sh
bash ./scripts/control-test-run.sh
APP_ENV=test bash ./scripts/control-test-reset.sh
bash ./scripts/control-test-stop.sh
```

PowerShell:

```powershell
.\scripts\control-test-start.ps1
.\scripts\control-test-run.ps1
$env:APP_ENV = 'test'; .\scripts\control-test-reset.ps1
.\scripts\control-test-stop.ps1
```

Reset отказывается работать вне изолированной Commercial test DB. Он не
удаляет development-данные. Доступны `/api/control/auth/*`, account onboarding,
read-only `/api/control/catalog/plans`, draft subscription create/read и
`GET /api/control/accounts/{accountId}/entitlements`. Activation является
только внутренним application service; customer `/activate` endpoint
отсутствует. Payment и provisioning API не реализованы. `GET /health` остаётся
минимальным.

Linux/macOS:

```bash
bash ./scripts/test-start.sh
bash ./scripts/test-run.sh
bash ./scripts/test-reset.sh
bash ./scripts/test-stop.sh
```

PowerShell:

```powershell
.\scripts\test-start.ps1
.\scripts\test-run.ps1
.\scripts\test-reset.ps1
.\scripts\test-stop.ps1
```

`test-stop` останавливает и удаляет только тестовые контейнеры, сохраняя
`platform_postgres_test_data` и `platform_redis_test_data` для следующего запуска.
`test-reset` удаляет эти два тестовых тома и создаёт тестовую инфраструктуру с нуля.
Development-контейнеры и тома обе команды не затрагивают. GitHub Actions создаёт
собственные тестовые контейнеры на своём runner; локальный Docker Desktop ему не нужен.

Для отдельной suite передайте её имя в test-run, например:

```bash
bash ./scripts/test-run.sh Api
bash ./scripts/test-run.sh Functional
bash ./scripts/test-run.sh Unit
bash ./scripts/test-run.sh Console
```

```powershell
.\scripts\test-run.ps1 Api
.\scripts\test-run.ps1 Functional
.\scripts\test-run.ps1 Unit
.\scripts\test-run.ps1 Console
```

Static analysis:

```bash
bash ./scripts/test-start.sh
docker compose --profile test exec -T backend-test \
  vendor/bin/psalm
```

Проверка Composer dependencies:

```bash
docker compose --profile test exec -T backend-test composer validate --strict

docker compose --profile test exec -T backend-test composer check-platform-reqs

docker compose --profile test exec -T backend-test composer audit
```

---

# Backup и восстановление

Создать backup локальной PostgreSQL:

```powershell
powershell -ExecutionPolicy Bypass `
  -File .\scripts\backup-db.ps1
```

Восстановление:

```powershell
powershell -ExecutionPolicy Bypass `
  -File .\scripts\restore-db.ps1
```

Изменение PostgreSQL password:

```powershell
powershell -ExecutionPolicy Bypass `
  -File .\scripts\change-postgres-password.ps1
```

---

# Полный reset development database

> **Внимание:** команда удаляет локальные PostgreSQL и Redis volumes.

```powershell
powershell -ExecutionPolicy Bypass `
  -File .\scripts\reset-dev-db.ps1
```

Не используй её, если локальные данные необходимо сохранить.

Перед reset рекомендуется сделать backup.

---

# Остановка проекта

Остановить containers без удаления данных:

```bash
docker compose down
```

Запустить снова:

```bash
docker compose up -d
```

Не используй:

```bash
docker compose down -v
```

если необходимо сохранить локальные Docker volumes.

---

# Документация

Основная техническая документация находится в:

```text
docs/
```

В частности:

```text
docs/core-data-model.md
docs/core-database-coverage.md
docs/architecture/
docs/database/
docs/security/
```

Документация должна обновляться вместе с изменениями архитектурных контрактов.

---

# Development workflow

Перед началом работы:

```bash
git fetch --prune
git status
```

После изменения backend:

```bash
bash ./scripts/test-start.sh
docker compose --profile test exec -T backend-test vendor/bin/psalm
bash ./scripts/test-run.sh
bash ./scripts/test-stop.sh
```

Перед commit необходимо убедиться, что в Git не попали:

```text
.env
vendor/
runtime/
tests/_output/
node_modules/
local backups
database dumps
logs
secrets
tokens
credentials
```

---

# Архитектурные границы

Core должен оставаться универсальным.

В него входят вещи уровня платформы:

```text
Identity
Accounts
Authentication
Authorization
Security
Configuration
Internationalization
Audit
Messaging contracts
Time
Client context
```

В Core не должны попадать сущности конкретного продукта:

```text
Students
Lessons
Homework
Courses
Games
Chats
Calls
Files
Search documents
Billing domain
```

Такие области реализуются отдельными модулями или сервисами.

---

# Состояние проекта

Platform находится в активной разработке.

Текущий репозиторий уже содержит рабочий Core runtime и API, включая:

- PostgreSQL schema и migrations;
- Yii3 backend;
- authentication;
- sessions;
- RBAC;
- MFA / TOTP;
- recovery flows;
- Passkeys / WebAuthn;
- external identities / OIDC;
- configuration runtime;
- internationalization;
- security audit;
- transactional outbox/inbox;
- Docker development environment;
- automated tests;
- static analysis.

Новые подсистемы должны добавляться поверх существующих контрактов без превращения Core в монолит предметной области.

---

## Главное правило

> **Core — фундамент платформы.**
>
> Если возможность относится к идентичности, безопасности, системной конфигурации или общему runtime — ей может быть место в Core.
>
> Если возможность описывает конкретный продукт или предметную область — она должна жить выше Core.
