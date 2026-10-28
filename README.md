# Platform

Модульная backend-платформа: Yii3 Core и отдельный Symfony Commercial Control
Plane. Frontend и Windows-агент пока представлены заготовками.

[Документация](docs/README.md) · [Бизнес-процессы и SVG](docs/processes/README.md) ·
[База данных и DBML](docs/database/README.md) · [Аудит 2026-10-28](docs/audit/2026-10-28/REPORT.md)

## Границы системы

Core владеет людьми, учётными записями, auth/MFA/passkeys, RBAC, организациями,
настройками, privacy, аудитом и инфраструктурными событиями. Commercial владеет
центральными пользователями, коммерческими клиентами, каталогом тарифов,
подписками, usage, invoices, payments, refunds, receipts и deployments.

`Person != Account`; `Control User != Core Account`; `Commercial Account !=
Core Organization`. PostgreSQL — durable источник истины. Redis — временное
состояние. Базы и credentials двух приложений разделены. Tenant получает
коммерческие права через authenticated M2M и проверенные signed documents.

Перед изменениями обязательны [AGENTS.md](AGENTS.md) и
[CORE_INVARIANTS.md](docs/architecture/CORE_INVARIANTS.md). HTTP-контракты находятся
в `contracts/openapi`, события — в `contracts/events`.

## Быстрый старт Core на Windows

Требуются Git и Docker Desktop с Linux containers. Создайте локальную
конфигурацию из `.env.example`; секреты не коммитятся и не выводятся в логи.

```powershell
Set-Location C:\Platform
powershell -ExecutionPolicy Bypass -File .\scripts\init-env.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\first-start.ps1
```

Bootstrap поднимает PostgreSQL/Redis, применяет Core migrations, устанавливает
locked Composer dependencies и проверяет API. Core: `http://127.0.0.1:8080`,
Mailpit: `http://127.0.0.1:8025`. Для существующего PostgreSQL volume изменение
пароля в `.env` не меняет пароль роли — используйте соответствующий скрипт
`change-platform-db-password.ps1` или `change-control-db-password.ps1`.

Commercial запускается профилем `control` после настройки отдельного
`CONTROL_POSTGRES_*`, application/token secrets, MFA encryption key и нужных
provider/SMTP policies. Примените `control-migrate`, затем запускайте API,
scheduler и outbox worker. Настройка инфраструктуры и обязательных runtime
параметров: [Provisioning](docs/operations/PROVISIONING.md),
[Workers](docs/architecture/BACKGROUND_JOBS.md), [Security](docs/architecture/COMMERCIAL_SECURITY.md).
Не используйте синтетические credentials нагрузочного стенда для production.

## Проверка проекта

Штатные tests работают в отдельных DB `platform_test` и
`platform_commercial_test`, с guard против destructive reset вне test runtime.

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\test-start.ps1
docker compose --profile test exec -T backend-test vendor/bin/codecept run --seed 2120741368
docker compose --profile test exec -T backend-test vendor/bin/psalm --no-cache --no-progress

# CONTROL_TEST_PASSWORD должен быть задан в окружении тестов.
powershell -ExecutionPolicy Bypass -File .\scripts\control-test-start.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\control-test-run.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\cross-plane-test-run.ps1
```

Cross-plane suite выбирается через `phpunit.cross-plane.xml`; пустой прогон
без выбранной группы не является успехом. Независимые процессы и DB connections
проверяют гонки, повторные financial effects и worker recovery.

[Нагрузочные проверки](scripts/load/README.md) используют `compose.load.yaml`,
собственные DB/volumes и 10 000 синтетических identities. Они проверяют реальные
HTTP endpoints, identity ответа, ошибки и latency. VU не равен WebSocket-соединению
или доказанной production-ёмкости. Числа и ограничения — в датированном отчёте.

## База данных и документация

Core SQL migrations: `apps/backend/migrations/sql`; Commercial Doctrine migrations:
`apps/control-plane/migrations`. DBML и справочник полей генерируются из
фактической PostgreSQL, а не из предполагаемой целевой модели.

```powershell
python scripts/database/export_schema.py --plane core
python scripts/database/export_schema.py --plane commercial
python scripts/documentation/build-reference.py
python scripts/documentation/build-coverage.py
```

Полный порядок проверок и визуализации: [database README](docs/database/README.md).
[Графики](docs/processes/README.md) объясняют регистрацию, auth, каталог, покупку,
смену тарифа, продление, возврат, права, provisioning, privacy и почту.

## Эксплуатация и готовность

[Журнал готовности](docs/operations/PRODUCTION_READINESS_PROGRESS.md) остаётся
источником release verdict. Локальные tests и нагрузка не доказывают production
HA, DNS/TLS, PITR, restore, доставку внешней почты или работу реального платёжного
провайдера. [Backup/restore](docs/operations/BACKUP_RESTORE.md) и
[external compensation](docs/operations/EXTERNAL_COMPENSATION.md) описаны отдельно.
