# Структура репозитория

| Путь | Фактическое назначение |
| --- | --- |
| `apps/backend` | Yii3 Core API, console, SQL migrations, unit/functional/API tests |
| `apps/control-plane` | Symfony Commercial API, migrations, workers, integration/cross-plane tests |
| `apps/frontend` | Заготовка; Vue-приложение не инициализировано |
| `apps/agent` | Заготовка Windows-агента; работающего .NET-приложения нет |
| `contracts/openapi` | HTTP-контракты |
| `contracts/events` | Schema и fixtures событий |
| `infra/postgres` | SQL migration runner |
| `infra/provisioner` | Infrastructure boundary, migrator и worker images |
| `infra/cross-plane` | Образ с обоими runtime для проверки границ |
| `deployment` | Настройки развёртывания; secrets не коммитятся |
| `scripts` | Tests, backup/restore, restart и documentation tools |
| `scripts/load` | Изолированная нагрузка и synthetic fixtures |
| `docs/database` | Generated DBML, catalogs и справочник полей |
| `docs/processes` | Mermaid и готовые SVG процессов |
| `docs/audit` | Датированные результаты и границы проверки |
| `compose.yaml` | Development и штатные test profiles |
| `compose.load.yaml` | Самостоятельный disposable load project |

Core включает Account, Auth, Person, Organization, RBAC, Module, Settings,
Communication, Messaging, Audit, Privacy, Internationalization и инфраструктуру
Security/Client/Network/Geo/Time. PlatformRuntime отделён от Core.
Commercial включает Identity, Account, Catalog, Subscription, Billing, Payment,
Fiscalization, Refund, Usage, Entitlement, Deployment, Provisioning, M2M и Privacy.

Education/Games/Chat и другие продуктовые модули не считаются реализованными
только из-за упоминания в архитектуре.
