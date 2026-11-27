# PLATFORM_18 — Infrastructure & Release Preparation Acceptance Report

**VERDICT: READY_FOR_FINAL_AUDIT**  
**Date:** 2026-11-27  
**Scope:** Подготовка существующей Platform к промышленному развёртыванию после завершения `PLATFORM_13–17` без разработки новых продуктовых фич и без выполнения production deploy.

---

## 1. Каноническая архитектура и оставшиеся compatibility-адаптеры (`PLATFORM_13–17`)

| Подсистема | Каноническая реализация | Compatibility-адаптеры и legacy-данные |
| --- | --- | --- |
| **Identity & ACL** | Core (`core.accounts`, `core.sessions`, `core.memberships`, RBAC, MFA/TOTP/WebAuthn); Commercial (`commercial.*`) | Изолированные тестовые фикстуры и guard `TestRuntimeGuard` (отключены в `prod`) |
| **Activity & Assessments** | `App\Activity\Application\ActivityEngineService`, `AssessmentActivityPlugin`, `TypingActivityPlugin`, таблицы `activity.*`, UI `AssessmentConstructor.vue`, `ActivityRunnerView.vue`, `TypingArenaPlugin.vue`, `UnifiedAttemptResultPanel.vue` | Таблицы `education.assessments`, `education.assessment_versions`, `education.assessment_attempts`, `education.question_bank` сохранены; `AssessmentService` сохраняет fallback SQL только при `$identity === null \|\| $engine === null` (unit-тесты); `ActivityResultView::forAttempt` сохраняет read-only fallback на `education.activity_results` / `education.typing_results`; `TypingActivityHandler.php` сохранён только для `ActivityEngineHandlersTest.php` |
| **Media & Storage** | `App\Media\Application\MediaService` (`media.*`), `App\Storage\Application\*` (`storage.*`), SeaweedFS S3 (`S3Objects`, `S3StorageFileAdapter`), UI `mediaController.ts`, `useControlledMediaElement.ts`, `CloudDriveView.vue`, `MediaPreviewDialog.vue` | `HomeworkFiles.vue` и `StudentDocumentsView.vue` сохраняют Storage S3 presigned API (`/api/storage/...`) для версий/конфликтов/квот при интеграции общего предпросмотра; legacy-клиент `drive.ts` |
| **Messenger (Chat)** | `App\Chat\Application\ChatService` (`chat.*`), `EducationChatContextPolicy`, `ChatMediaAccessAuthorizer`, UI `UniversalMessenger.vue` | Legacy-маршруты `/api/education/threads*` и `/api/education/parent/threads*` работают поверх `chat.*`; таблицы `education.threads`, `education.thread_members`, `education.messages`, `education.message_reactions` физически не удалены до очистки GDPR-контрибьюторов |
| **Workspace (Dashboard, Search, Trash, Operations)** | `WorkspaceDashboardService` (8 провайдеров), `WorkspaceSearchService` (7 провайдеров), `WorkspaceTrashService` (4 провайдера, `workspace.trash_items`), `WorkspaceOperationService` (`workspace.operations`), UI `WorkspaceDashboardView.vue`, `GlobalSearchView.vue`, `CommandPalette.vue`, `TrashView.vue`, `OperationsCenterDrawer.vue` | `ParentSectionView.vue` использует `WorkspaceDashboardResponse` как read-only сводку |
| **Realtime** | `App\Realtime\Application\RealtimeService`, `WebSocketServer` (`realtime:serve` на `:8084`, Redis Pub/Sub + `realtime.events`), UI `realtimeClient.ts` | Нет дублирующих WS-серверов |
| **Notifications** | `App\Notifications\Application\NotificationsService` (`notifications.*`), UI `NotificationsCenterView.vue` | `education/NotificationsView.vue` (доменная лента Education `/notifications`, `/student/notifications`) |

---

## 2. Фактическое состояние инфраструктуры

- **Стек (сохранён без замены технологий):** PostgreSQL `18.6` (`infra/postgres/Dockerfile`, две раздельные БД: Core `platform` и Commercial `platform_commercial`), Redis `8-alpine`, SeaweedFS `4.53` (`compose.s3.yaml`, `compose.storage.yaml`), PHP `8.3.36` FrankenPHP `1.13.4` (`apps/backend/docker/Dockerfile`, `apps/control-plane/docker/Dockerfile`), Vue 3 (`apps/frontend/dist`), WebSocket (`backend-realtime`), фоновые workers (`backend-email-worker`, `backend-scheduler`, `control-scheduler`, `control-outbox-worker`).
- **Исправленная неисправность healthcheck:** `platform-backend-realtime` ранее находился в статусе `unhealthy`, так как наследовал `HEALTHCHECK` базового образа FrankenPHP (`localhost:2019/metrics`), не запуская Caddy. В `compose.yaml` добавлена целевая TCP-проверка `127.0.0.1:8084`.
- **Изоляция окружений и релизная сборка:**
  - Добавлен [`compose.prod.yaml`](file:///c:/Platform/compose.prod.yaml): переключает `backend` и workers на `target: prod` (`APP_ENV=prod`, `APP_DEBUG=0`, `XDEBUG_MODE=off`, `LOCAL_MAIL_ENABLED=false`), убирает dev-монтирования `./apps/backend:/app`, отключает `mailpit` и `backend-fast`, снимает host-порты PostgreSQL/Redis (`ports: !reset []`), задаёт `stop_grace_period`.
  - Добавлен шаблон [`.env.prod.example`](file:///c:/Platform/.env.prod.example) без реальных секретов.
  - Добавлен пример обратного прокси [`infra/proxy/Caddyfile.example`](file:///c:/Platform/infra/proxy/Caddyfile.example) (HTTPS, раздача `apps/frontend/dist`, проксирование `/api/control*` → `127.0.0.1:8090`, `/ws*` → `127.0.0.1:8084`, `/api*` → `127.0.0.1:8080`, блокировка внешнего доступа к `/metrics` и `/health/isolation`).
- **Лимиты, заголовки и endpoints:**
  - `SecurityHeadersMiddleware` выставляет `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, `Content-Security-Policy`, `Cache-Control: no-store` для `/api/auth` и `/api/core`.
  - `RequestSizeLimitMiddleware` ограничивает тело HTTP-запроса `5 MiB`; PHP `security.ini` задаёт `upload_max_filesize=10M`, `post_max_size=12M`, `zend.exception_ignore_args=On`; S3 presigned uploads ограничены `S3_UPLOAD_MAX_BYTES` (по умолчанию 5 GiB, `.env.example` / `.env.prod.example` — 10 GiB).
  - `/health/isolation` возвращает `404`, если не заданы `E2E_ISOLATION_PROOF` и `TestRuntimeGuard`; `/metrics` защищён bearer-токеном `METRICS_TOKEN`.
  - Все секретные файлы (`.env`, `apps/backend/.env`, `apps/frontend/.env.local`, `apps/frontend/.env.development.local`, `runtime/s3/s3.env`) подтверждённо находятся в `.gitignore`.

---

## 3. Изменённые и добавленные файлы

- [`compose.yaml`](file:///c:/Platform/compose.yaml) — добавлен `healthcheck` для `backend-realtime` (`tcp://127.0.0.1:8084`).
- [`compose.prod.yaml`](file:///c:/Platform/compose.prod.yaml) — production/staging override для Compose.
- [`.env.prod.example`](file:///c:/Platform/.env.prod.example) — шаблон переменных окружения для staging/production (без секретов).
- [`infra/proxy/Caddyfile.example`](file:///c:/Platform/infra/proxy/Caddyfile.example) — шаблон внешнего reverse proxy (HTTPS + SPA + API + WebSocket + блокировка внутренних endpoints).
- [`docs/operations/ENVIRONMENT.md`](file:///c:/Platform/docs/operations/ENVIRONMENT.md) — актуализирована ссылка на `.env.prod.example`, `compose.prod.yaml` и `infra/proxy/Caddyfile.example`.
- [`docs/operations/SAFE_DATABASE_UPGRADE.md`](file:///c:/Platform/docs/operations/SAFE_DATABASE_UPGRADE.md) — добавлен раздел `Откат (Rollback)` с явным предупреждением о несовместимых миграциях БД.
- [`docs/audits/PLATFORM_18_ACCEPTANCE.md`](file:///c:/Platform/docs/audits/PLATFORM_18_ACCEPTANCE.md) — текущий отчёт приёмки.

---

## 4. Проверенные процедуры (фактические результаты)

| Проверка | Команда / Метод | Результат |
| --- | --- | --- |
| **Валидация Compose (dev + prod)** | `docker compose --profile control config --quiet` и `docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.example --profile control config --quiet` | **PASS** (`DEV_COMPOSE_OK`, `PROD_COMPOSE_OK`, 0 ошибок) |
| **Здоровье всех контейнеров стека** | `docker compose up -d --no-build --pull never --wait --wait-timeout 45 backend-realtime` + `docker ps` | **PASS** — все 13 контейнеров `platform-*` в статусе `healthy` (включая `platform-backend-realtime`) |
| **HTTP Healthchecks (Core + Commercial + Storage)** | `curl.exe -fsS` на `8083/health/live`, `8083/health/ready`, `8083/health/storage`, `8082/health/ready`, `8090/health/live`, `8090/health/ready` | **PASS** — все 6 ответов `{"status":"ok"}` |
| **PostgreSQL + Схема Core + Workers** | `docker exec platform-backend php yii db:check` + `ops:health backend-scheduler`, `ops:health backend-email-worker` + `control:ops:health control-scheduler`, `control:ops:health control-outbox-worker` | **PASS** — `Core tables: 50`, все 4 worker healthcheck вернули `0` |
| **Redis подключение** | `docker exec platform-redis redis-cli ping` + `Predis\Client->ping()` из `platform-backend` | **PASS** (`PONG`) |
| **Production-образ и Frontend dist** | Проверка `platform-backend-fast` (`NO_TESTS NO_ENV www-data`) и `apps/frontend/dist/index.html` | **PASS** |
| **Синхронизация схемы БД и DBML** | `export_schema.py --plane core --check`, `--plane commercial --check`, `check-dbml.cjs` | **PASS** — Core 53 таблицы, Commercial 62 таблицы, все 11 DBML без дрифта |
| **Windows Agent (сборка и unit-тесты)** | `dotnet build Platform.Agent.Service`, `dotnet build Platform.Agent.Ui`, `dotnet run --project apps/agent/Platform.Agent.Tests` | **PASS** — 0 warnings/errors, все unit-сценарии Agent пройдены |

---

## 5. Невыполненные проверки и Production Gates

1. **Полная регрессия и полный CI** — не запускались согласно границам этапа; оставлены на этап финальной независимой приёмки владельцем.
2. **Учение по восстановлению БД + SeaweedFS S3 на новом целевом окружении** — в рамках текущего прогона `scripts/storage/backup.py backup/restore` не запускался (последняя зафиксированная локальная проверка в `docs/operations/STORAGE_02.md` — `2026-11-15`; требуется повторное подтверждение с PITR и offsite-хранением ключа на целевом контуре).
3. **Нативная приёмка Windows Agent и Credential Provider (`VM_NATIVE_VERIFIED`, `PRELOGIN_VERIFIED`)** — **NOT_VERIFIED**: требует выделенной Windows x64 VM с интерактивным LogonUI/Winlogon, сборкой `Platform.CredentialProvider.vcxproj` через MSVC v143/v145 x64 + Windows SDK и прохождением матрицы `A → B → A` (компиляция .NET и unit-тесты не подтверждают работу Credential Provider на реальной системе).
4. **Внешние интеграции (требуют реальных учётных данных и проверки владельцем):**
   - Внешний SMTP-провайдер (`SMTP_*`, `CONTROL_MAILER_DSN`, отключённый `LOCAL_MAIL_ENABLED=false`).
   - YooKassa (`YOOKASSA_SECRET_KEY`, `CONTROL_YOOKASSA_RETURN_URL`, `CONTROL_YOOKASSA_WEBHOOK_IPS`, `configure` мерчанта, боевые webhook/фискальные чеки).
   - OAuth-провайдеры (Google, Telegram) и HTTPS-домены для WebAuthn (`AUTH_PUBLIC_BASE_URL`, `AUTH_WEBAUTHN_RP_ID`, `AUTH_WEBAUTHN_ORIGINS`).
   - Внешний/публичный S3 endpoint с TLS (`S3_PUBLIC_ENDPOINT=https://...`, CORS под production-домен).
   - Разделение ролей PostgreSQL (`scripts/operations/provision-db-role.sh` / `scripts/ci/db-role-gate.sh`) на боевой БД вместо локального суперпользователя.
   - Сканирование CVE итоговых release-образов и утверждение остаточного риска (`docs/operations/IMAGE_SECURITY.md`).
   - Промышленный получатель алертов Alertmanager и мониторинг (`compose.observability.yaml`).

---

## 6. Риски обновления и эксплуатации

- **Отсутствие `ext-pcntl` в базовом образе PHP:** долгоживущие PHP-процессы (`scheduler:run`, `communication:process-email --loop`, `realtime:serve`) не перехватывают `SIGTERM` через `pcntl_signal`; при `docker stop` полагаются на `stop_grace_period` (`15s–30s` в `compose.prod.yaml`) и идемпотентность/атомарность батчей.
- **Откат несовместимых миграций:** откат одного только Docker-образа при изменении схемы БД недостаточен — требуется предварительный `pg_dump -Fc` + S3 snapshot (`scripts/storage/backup.py`) до запуска миграций.
- **Локальный `.env` на машине разработчика:** в локальном `compose.yaml` используются dev-параметры (`target: dev`, локальные порты `127.0.0.1:8083/8082/8090`, Mailpit); перед сборкой/развёртыванием на staging/production обязательно использовать `compose.prod.yaml` и заново сгенерированные секреты по `.env.prod.example`.
