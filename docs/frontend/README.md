# Frontend Platform

Frontend Platform — SPA на **Vue 3 + TypeScript (strict)** и **Vite**. Работает с
реальным **Commercial Control Plane** (Symfony) по контракту
`contracts/openapi/control-plane.yaml`. Моков и фиктивных данных в runtime нет.

Код: [`apps/frontend`](../../apps/frontend/README.md). Контракт будущих графиков:
[CHARTS_CONTRACT.md](CHARTS_CONTRACT.md).

## Стек

Vue 3 (Composition API, `<script setup>`), TypeScript strict (`vue-tsc`), Vite 7,
Vue Router 4, Pinia 3, vue-i18n 11 (`en`/`ru`), `@lucide/vue`, ESLint 9,
Vitest + `@vue/test-utils` + happy-dom.

## Быстрый старт

Нужен Node.js 20.19+ (проверено на 24).

```bash
cd apps/frontend
npm install
npm run dev      # http://localhost:5173
```

Dev-прокси Vite (цели задаются в `apps/frontend/.env`, пример — `.env.example`):

| Префикс | Куда проксируется | Переменная |
| --- | --- | --- |
| `/api/control/*` | Commercial Control Plane (`:8090`) | `VITE_COMMERCIAL_PROXY_TARGET` |
| `/api/*` | Yii Core (`:8080`) | `VITE_CORE_PROXY_TARGET` |

Адреса `localhost` не зашиты в компонентах — только в конфиге прокси.

## Слои и зависимости

```text
src/
  app/        router, layouts, theme — собирает приложение
  core/       auth (сессия + store), account (store), api (композиция клиента)
  modules/    commercial/{auth,onboarding,catalog,account}
  shared/     api, ui, composables, validation, formatting, errors, i18n, styles, types
```

Правила:

- `shared` не импортирует `modules`/`core`; `shared/ui` не знает об API.
- `core` не зависит от страниц модулей.
- `modules` используют публичные контракты `core`/`shared`.
- HTTP-клиент создаётся в `core/api`; сам транспорт `shared/api/http.ts` не знает о сессии.
- Один владелец у каждого состояния.

## Сессии и авторизация

- Access/refresh токены хранятся **только в памяти** (`core/auth/session.ts`). Никакого
  `localStorage`/`sessionStorage`/IndexedDB. После перезагрузки нужен повторный вход.
- Один владелец сессии с **epoch**: вход, выход и смена пользователя увеличивают epoch и
  инвалидируют устаревшие запросы и refresh. Запоздавший refresh после logout не
  восстанавливает сессию.
- Жизненный цикл: `anonymous → authenticating → mfa_required → authenticated → signing_out`.
  `authenticated` невозможен без загруженного `me`; при сбое `me` токены откатываются.
- 401 → один refresh на группу запросов (single-flight) → максимум один ретрай. Отдельно
  обрабатываются: повторный 401, `expired` refresh, `stale` (сессия сменилась) и сетевой
  `unavailable` (без разлогина).
- MFA challenge живёт в памяти; прямой `/mfa` без challenge перенаправляет на `/login`.
- Logout очищает auth-состояние, account-состояние и тосты; данные пользователя A не
  появляются у пользователя B.

## Account context и рабочее пространство аккаунта

`core/account/accountStore.ts` — единый контекст: список (пагинация `limit`/`offset`/
`total`), детали выбранного аккаунта (`selectedAccountId`, `accountDetail`,
`detailLoading`, `detailError`, `loadAccount`, `invalidateAccount`,
`clearAccountContext`), защита от устаревших ответов и очистка по epoch. URL —
источник `accountId`; store владеет загруженными данными. При смене аккаунта прежние
данные не показываются.

Маршруты:

- `/accounts/:accountId` — обзор аккаунта;
- `/accounts/:accountId/members` — участники (только просмотр).

`AccountLayout` загружает детали, показывает заголовок, breadcrumbs, переключатель
аккаунтов и вкладки; `AccountOverviewView` — сведения и статус; `AccountMembersView` —
список участников с пагинацией. Токены живут в памяти: после полной перезагрузки
защищённый URL уводит на `/login?redirect=...` и после входа возвращает на нужный
аккаунт.

### Read API аккаунта

- `GET /api/control/accounts/{accountId}` — сведения + `role` текущего пользователя и
  `permissions` (`read_account`, `view_members`, `manage_members`,
  `manage_ownership`, `manage_billing`, `manage_technical`), вычисленные на сервере.
- `GET /api/control/accounts/{accountId}/memberships` — активные участники, пагинация
  `limit`/`offset`/`total`, поля `id`, `user_id`, `email`, `role`, `created_at`.

Право просмотра участников — `membership.read` у `owner` и `admin`. `billing` и
`technical` его не имеют. Frontend скрывает вкладку по `permissions.view_members`, но
сервер повторно проверяет доступ.

### Browser validation

- `scripts/verify-responsive.mjs` — **read-only smoke**: без авторизации и мутаций.
  Публичные экраны, guard-редиректы (включая `/accounts/*` и `/settings/security`),
  overflow 320–1440, темы, keyboard, RU.
- `scripts/e2e-account-workspace.mjs` — **мутирующий E2E**. По умолчанию отказывает и
  завершается с `NOT_VERIFIED` (exit 2) до любых HTTP-запросов. Требует одновременно:
  `E2E_RUNTIME=isolated-ci`, `E2E_ISOLATED_DB=1`, `E2E_ALLOW_MUTATIONS=1`, явные
  `APP_URL`/`CONTROL_API_URL`/`MAILPIT_URL`, `E2E_ALLOWED_HOSTS` и `E2E_PASSWORD`
  (без дефолтных credentials).
- CI проверяет сам отказ: `node scripts/e2e-account-workspace.mjs --check-guard`.
- Мутирующий E2E не запускается на локальной рабочей БД; при отсутствии изолированного
  runtime он помечается `NOT_VERIFIED`.

## Security Center и управление доступом

Маршрут `/settings/security` — защищённые пользовательские настройки Control Plane (не
Commercial Account). Используются реальные API:

- `GET /auth/mfa/status` — включена ли MFA и сколько recovery-кодов осталось;
- `POST /auth/mfa/totp/setup` — pending factor: `factor_id`, `secret`, `otpauth_uri`;
- `POST /auth/mfa/totp/confirm` — подтверждение кода и одноразовые `recovery_codes`;
- `POST /auth/reauthenticate` — step-up; backend обновляет
  `primary_authenticated_at`/`mfa_authenticated_at` текущей сессии;
- `GET /auth/sessions` — текущая сессия и метод входа.

QR строится локально (`qrcode.vue`); `otpauth_uri` и секрет не передаются сторонним
сервисам, не логируются и не попадают в persistent storage. Recovery-коды показываются
один раз и очищаются при закрытии диалога. «MFA включена» показывается только после
успешного `confirm`.

### Step-up

`useSensitiveAction` выполняет чувствительное действие; при
`recent_authentication_required`/`recent_mfa_required` открывает
`ReauthenticateDialog` (пароль; при включённой MFA — TOTP или recovery-код) и после
успеха повторяет действие ровно один раз. `mfa_enrollment_required` показывает ссылку в
Security Center. Серверные проверки не обходятся.

### Управление участниками

`PATCH`/`DELETE /accounts/{id}/memberships/{membershipId}` требуют серверных permissions
и `requireSensitiveMfa`. UI:

- `owner` управляет владением и всеми ролями; `admin` — только не-владельцами и не может
  назначать `owner`; `billing`/`technical` не получают действий;
- изменение роли и удаление идут через подтверждение и step-up;
- нет оптимистичных обновлений: после успеха перезагружаются участники и account
  permissions; при потере доступа — переход на `/cabinet`;
- последний owner защищён backend (409), UI показывает отказ;
- self-removal очищает account context и уводит из недоступного аккаунта;
- приглашений по email нет.

## Дизайн-система

- Канонический источник — `apps/frontend/design/design-tokens.json` (авторский файл).
- `npm run generate:tokens` → `src/shared/styles/tokens.css` (цвета, тени, отступы,
  радиусы, размеры и типографика из `Text Styles`, mobile + desktop). Файл не редактируется вручную.
- `npm run check:tokens` падает, если CSS рассинхронизирован с JSON.
- Семантика (`--bg`, `--surface`, `--card`, `--border`, `--text-1`, `--accent`, статусы)
  в `theme.css`; темы Light/Dark/System без вспышки (inline-скрипт в `index.html`).
- Стиль border-first: карточки и поля отделены границей, тени умеренные.
- Запрещены хардкод-цвета, радиусы и размеры шрифтов вне токенов.

## Компоненты

`shared/ui`: `AppButton`, `AppIconButton`, `AppInput`, `AppPasswordInput`, `AppBadge`,
`AppCard`, `AppDivider`, `AppEmptyState`, `AppErrorState`, `AppSkeleton`, `AppHeader`,
`AppBottomNav`, `AppDialog`, `AppFormShell`, `AppToast`.

`AppDialog` — focus trap, возврат фокуса, scroll lock со счётчиком, Escape, backdrop,
`persistent`, mobile bottom-sheet. `AppToast` — очередь до 4, success/error/warning/info,
ручное закрытие, очистка при смене сессии.

## Локализация

Словари `en`/`ru` в `src/shared/i18n/messages`. Неизвестные коды API ошибок дают
безопасное локализованное сообщение, а не сырой серверный текст. Паритет ключей EN/RU
проверяется тестом.

## Проверки

```bash
cd apps/frontend
npm run check:tokens   # токены соответствуют design-tokens.json
npm run typecheck      # vue-tsc (strict)
npm run lint           # eslint
npm run test           # Vitest: unit + component, без БД
npm run build          # typecheck + production-сборка
```

Браузерные проверки (Playwright + установленный Chrome/Edge; нужен `npm run dev`):

```bash
node scripts/verify-responsive.mjs  # маршруты/guard, overflow 320–1440, console, темы, keyboard, RU
node scripts/screenshot.mjs         # скриншоты доступных состояний в screenshots/
```

Аутентифицированный кабинет и диалог создания аккаунта требуют работающего backend и в
браузерных скриптах помечаются `NOT_VERIFIED`; клавиатурная доступность диалога покрыта
unit-тестами.

## CI

Задача `frontend-qa` в `.github/workflows/ci.yml`: Node 24, `npm ci`,
`npm run check:tokens`, `typecheck`, `lint`, `test`, `build`. Без `continue-on-error`;
существующие backend/cross-plane проверки не отключены.

## Диагностика

- **TS2307 «Cannot find module '*.vue'» в IDE.** Обычный TS-сервер без Vue language
  service не резолвит SFC. В `env.d.ts` есть `declare module '*.vue'`, в
  `tsconfig.app.json` — `baseUrl` и `paths`. Перезапустите TS server в IDE
  (VSCode: «TypeScript: Restart TS Server») или установите расширение Vue (Volar).
  Источник истины — `npm run typecheck`, а не подсветка IDE.
- **Каталог показывает ошибку сети.** Backend не запущен либо неверна цель прокси в
  `.env`. Проверьте `VITE_COMMERCIAL_PROXY_TARGET`.
- **`check:tokens` падает.** Кто-то правил `tokens.css` вручную. Выполните
  `npm run generate:tokens`.
- **Переход на `/cabinet` кидает на `/login`.** Нет сессии (токены только в памяти) —
  это ожидаемо после перезагрузки страницы.
- **`/mfa` сразу уводит на `/login`.** Нет активного MFA challenge — это корректно.

## Правила разработки

- Не хранить токены в persistent storage.
- Не добавлять mock API и фиктивные данные.
- Не ослаблять проверки MFA/авторизации.
- Использовать существующий HTTP transport и Idempotency-Key для мутаций.
- Не хардкодить цвета/радиусы/типографику — только токены.
- Не оставлять мёртвый код и конкурирующие реализации CSS.

## Приглашения

Управление — `/accounts/:accountId/invitations` (вкладка при `permissions.manage_members`): список с пагинацией, создание (email + роль `admin`/`billing`/`technical`, без `owner`), resend/revoke, step-up через `useSensitiveAction`, состояния loading/empty/error/expired/revoked/pending. Показывается «queued», а не «delivered» (backend не отслеживает доставку).

Получатель — `/invitation` (публичный): токен читается из fragment `#token=…`, хранится только в памяти и удаляется из адресной строки; preview без мутаций; accept/decline — явные действия. После accept обновляется список аккаунтов и открывается Workspace; после decline membership не создаётся. Переход на login/register сохраняет `redirect=/invitation` без токена; после перезагрузки без fragment предлагается открыть ссылку из письма.

E2E: мутирующий runner требует `E2E_RUNTIME=isolated-ci`, `E2E_ISOLATED_DB=1`, `E2E_ALLOW_MUTATIONS=1`, `E2E_ISOLATION_PROOF` (не короче 32 символов), allowlisted нелокальные хосты и `E2E_PASSWORD`; loopback/private хосты отклоняются. `--check-guard` выполняется в CI, а полный mutating E2E на локальной БД не запускается (`NOT_VERIFIED`).
