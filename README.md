# Platform — этап 1: универсальное Core v1

Это первый реально запускаемый кусок новой **универсальной Platform**.
Сейчас задача простая: поднять PostgreSQL + Redis, применить Core v1 и спокойно
посмотреть всю схему через DBeaver до того, как мы начнём писать API.

## Что уже готово

- PostgreSQL 18 в Docker;
- Redis 8 в Docker;
- автоматический runner SQL-миграций;
- схема `core` ровно из **23 таблиц**;
- UUIDv7 через нативный `uuidv7()` PostgreSQL 18;
- FK, `CHECK`, `UNIQUE`, partial `UNIQUE`, индексы;
- seed ролей `core.superadmin` и `core.admin`;
- комментарии к БД, которые можно смотреть прямо в DBeaver;
- актуальный DBML и русское подробное описание;
- скрипты проверки, backup и полного dev-reset;
- каркас папок под frontend, agent, infra, contracts и будущие модули.

## 1. Куда распаковать

Распакуй архив **прямо в `C:\`**. Должно получиться:

```text
C:\Platform\
├── apps\
├── infra\
├── docs\
├── scripts\
├── compose.yaml
├── .env.example
└── README.md
```

## 2. Что должно быть установлено

На первом этапе достаточно:

- Docker Desktop;
- DBeaver;
- Git.

PHP, Composer, Node и .NET для просмотра Core пока не нужны.

## 3. Первый запуск — одна команда

Открой PowerShell:

```powershell
Set-Location C:\Platform
powershell -ExecutionPolicy Bypass -File .\scripts\first-start.ps1
```

Скрипт сам:

1. создаст `.env` из `.env.example`, если `.env` ещё нет;
2. скачает Docker images;
3. поднимет PostgreSQL и Redis;
4. применит миграции;
5. проверит, что в `core` ровно 23 таблицы;
6. напечатает параметры для DBeaver.

## 4. DBeaver

Параметры подключения:

```text
Host:     localhost
Port:     5432
Database: platform
User:     platform_app
Password: read POSTGRES_PASSWORD from .env
```

Значение `POSTGRES_PASSWORD` генерируется автоматически при первом запуске в `.env`. Используй значение из твоего `.env`.

После подключения смотри:

```text
platform
└── Schemas
    ├── core
    │   ├── persons
    │   ├── person_contacts
    │   ├── accounts
    │   ├── ...
    │   ├── audit_events
    │   ├── outbox_events
    │   └── inbox_events
    │
    └── public
        └── platform_migrations
```

`public.platform_migrations` — **техническая** таблица runner'а.
Она не входит в Core. В `core` должно быть ровно **23 base tables**.

## 5. Полезные команды

```powershell
# Контейнеры + список Core-таблиц + история миграций
powershell -ExecutionPolicy Bypass -File .\scripts\db-status.ps1

# Backup локальной БД
powershell -ExecutionPolicy Bypass -File .\scripts\backup-db.ps1

# Полностью снести LOCAL volumes и развернуть Core заново
powershell -ExecutionPolicy Bypass -File .\scripts\reset-dev-db.ps1

# Остановить контейнеры, но НЕ удалять данные
docker compose down

# Запустить обратно
docker compose up -d postgres redis

# Проверить/доприменить миграции
docker compose run --rm db-migrate
```

## 6. Core v1 — 23 таблицы

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

Education, Games, Search, Chat, Storage, Calls, Devices и прочие вещи в Core не
пихаем. Они будут отдельными модулями/схемами и смогут зависеть от Core.
**Core от них не зависит.**

## 7. Почему на первом шаге миграции SQL

Сейчас важнее проверить сам контракт БД и посмотреть его глазами в DBeaver.
Поэтому первый runnable-этап не притворяется готовым Yii-приложением.

В архиве уже есть `scripts/bootstrap-backend.ps1` для следующего шага. Он создаёт
backend из официального `yiisoft/app-api` и добавляет PostgreSQL + migrations
packages. Запускать его для проверки базы **не нужно**.

## 8. Главные правила

- `Person != Account`.
- Core универсален и не знает предметную область конкретного продукта.
- PostgreSQL — основной transactional source of truth.
- Redis — временное состояние/cache/locks, не бизнес-истина.
- `core.audit_events` — важная бизнес/security история, а не техническая телеметрия.
- Observability хранится отдельно.
- Направление зависимостей: `Education/Games/... -> Core`, но не наоборот.
