# Platform

Platform состоит из Yii Core и Symfony Commercial Control Plane. Core управляет идентичностями, доступом, организациями и персональными данными. Commercial управляет каталогом, подписками, платежами и жизненным циклом установок. У приложений раздельные базы и учётные данные.

[Документация](docs/README.md) · [Бизнес-процессы](docs/processes/README.md) · [База данных](docs/database/README.md)

## Запуск

Нужны Docker Compose, PowerShell и Python 3. Запуск из корня репозитория:

```powershell
./scripts/first-start.ps1
```

Скрипт создаёт `.env`, генерирует отсутствующие секреты, собирает образы, применяет миграции и запускает постоянные сервисы проекта `platform`. Существующие пароли и данные сохраняются. Для уже настроенной установки:

```powershell
docker compose --profile control up -d --wait
docker compose --profile control ps
```

Core: `http://localhost:8080`, Commercial: `http://localhost:8090`, Mailpit: `http://localhost:8025`. Порты задаются в `.env`. Mailpit — локальный SMTP-приёмник; для внешней доставки настройте SMTP и `CONTROL_MAILER_DSN`.

Основной Compose содержит только рабочие сервисы и их зависимости. Все они сгруппированы в `platform`. Логи каждого контейнера ограничены двумя файлами по 10 МБ. Резервные копии и тестовые окружения автоматически не создаются.

## Frontend

SPA на Vue 3 + TypeScript (strict) + Vite, подключается к Commercial Control Plane по
реальному API. Запускается отдельно от Compose (без своей БД/Redis/контейнера):

```powershell
cd apps/frontend
npm install
npm run dev        # http://localhost:5173
```

Dev-прокси: `/api/control/*` → Commercial (`:8090`), `/api/*` → Core (`:8080`);
цели задаются в `apps/frontend/.env` (пример — `.env.example`).

Проверки frontend:

```powershell
cd apps/frontend
npm run check:tokens   # токены соответствуют design/design-tokens.json
npm run typecheck
npm run lint
npm run test           # Vitest, без БД
npm run build
```

Токены хранятся только в памяти (после перезагрузки нужен повторный вход). Подробности:
[руководство по frontend](docs/frontend/README.md), [README приложения](apps/frontend/README.md),
[контракт графиков](docs/frontend/CHARTS_CONTRACT.md).

## Конфигурация и обновление

`./scripts/init-env.ps1` дополняет `.env` недостающими ключами. Обязательные секреты не имеют запасных значений в Compose. Внешние OAuth и платёжные credentials выдаются провайдерами и заполняются оператором. Изменение существующего пароля PostgreSQL требует синхронной смены пароля роли; простое редактирование `.env` этого не делает.

[Обновление БД](docs/operations/SAFE_DATABASE_UPGRADE.md) · [Ротация секретов](docs/operations/ENVIRONMENT.md)

## Проверки

`.github/workflows/ci.yml` запускает Core, Commercial, межсервисные и инфраструктурные проверки в GitHub Actions. `compose.ci.yaml` дополняет основной файл только для CI; сценарии `scripts/ci` отказываются запускаться без `CI=true`. На локальных рабочих базах интеграционные тесты и reset-команды не запускаются.

CI можно запустить вручную: **Actions → Platform CI → Run workflow**. Сообщение коммита с `[skip ci]` пропускает автоматический запуск при push, но не ручной запуск.

Проверка сохранённых схем и документации без дополнительных контейнеров:

```powershell
python scripts/database/export_schema.py --plane core --from-snapshot --check
python scripts/database/export_schema.py --plane commercial --from-snapshot --check
python scripts/documentation/build-reference.py --check
python scripts/documentation/build-coverage.py --check
python scripts/documentation/check-links.py
npm ci --prefix scripts/documentation --ignore-scripts
npm run check:dbml --prefix scripts/documentation
```

Состояние требований к промышленной эксплуатации описано в [критериях выпуска](docs/operations/PRODUCTION_READINESS_PROGRESS.md).
