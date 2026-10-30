# Frontend (Platform)

Пользовательский интерфейс Platform на **Vue 3 + TypeScript (strict)**, собранный на
**Vite**. Подключается к **Commercial Control Plane** (Symfony) по реальному
HTTP-контракту `contracts/openapi/control-plane.yaml`. Backend не мокается.

Подробное руководство по frontend: [docs/frontend/README.md](../../docs/frontend/README.md).

## Архитектура и направление зависимостей

```text
src/
  app/            # router, layouts, theme — собирает приложение
  core/           # auth (сессия+store), account (store), api (композиция клиента)
  modules/        # commercial/{auth,onboarding,catalog,account}
  shared/         # api (транспорт+guards), ui, composables, validation,
                  # formatting, errors, i18n, styles, types
```

Правила: `shared` не импортирует `modules`/`core`; `shared/ui` не знает об API;
`core` не зависит от страниц модулей; `modules` используют публичные контракты
`core`/`shared`; `app` связывает маршруты. HTTP-клиент создаётся в `core/api`, а сам
транспорт (`shared/api/http.ts`) ничего не знает о сессии.

## Сессии и авторизация

- Токены — **только в памяти** (`core/auth/session.ts`), без localStorage/IndexedDB.
- Один владелец сессии с **epoch**: любая смена (вход, выход, смена пользователя)
  инвалидирует устаревшие ответы и refresh. Refresh после logout не воскрешает сессию.
- Явный жизненный цикл: `anonymous` → `authenticating` → `mfa_required` →
  `authenticated` → `signing_out`. `authenticated` невозможен без загруженного `me`;
  при сбое `me` токены откатываются.
- 401 → один refresh на группу запросов (single-flight) → один ретрай. Повторный 401,
  отклонённый/просроченный refresh и сетевые сбои обрабатываются раздельно.
- `logout`/смена пользователя очищает auth- и account-состояние, тосты и устаревшие
  запросы. Данные пользователя A не появляются у пользователя B.
- MFA challenge живёт только в памяти; прямой переход на `/mfa` без challenge
  перенаправляет на `/login`.

## Аккаунты

`core/account/accountStore.ts` — единый account-context: список, выбранный аккаунт,
пагинация (`limit`/`offset`/`total` на существующем API), загрузка, ошибки,
инвалидация по epoch и защита от устаревших ответов. Создание аккаунта не
перезагружает весь кабинет — диалог сообщает об успехе, страница обновляет первую
страницу.

## Дизайн-система

- Канонический источник токенов — `design/design-tokens.json` (авторский файл).
- `npm run generate:tokens` → `src/shared/styles/tokens.css` (не редактировать вручную).
- `npm run check:tokens` завершается ошибкой, если CSS рассинхронизирован с JSON.
- Семантика (`--bg`, `--surface`, `--card`, `--border`, `--text-1`, `--accent`, …)
  в `theme.css`; темы Light/Dark/System без вспышки (inline-скрипт в `index.html`).
- Типографика Inter в `typography.css`; border-first стиль.

## Технологии

Vue 3 (Composition API, `<script setup>`), TypeScript strict (`vue-tsc`), Vite 7,
Vue Router 4, Pinia 3, vue-i18n 11 (`en`/`ru`), `@lucide/vue`, ESLint 9, Vitest +
`@vue/test-utils` + happy-dom.

## Запуск

Node.js 20.19+ (проверено на 24).

```bash
cd apps/frontend
npm install
npm run dev        # http://localhost:5173
```

Dev-прокси (цели в `.env`, см. `.env.example`): `/api/control/*` → Control Plane
(`:8090`), `/api/*` → Yii Core (`:8080`).

## Проверки

```bash
npm run check:tokens      # токены соответствуют design-tokens.json
npm run typecheck         # vue-tsc (strict)
npm run lint              # eslint
npm run test              # Vitest (unit + component, без БД)
npm run build             # typecheck + production-сборка
```

Браузерные проверки (Playwright + установленный Chrome/Edge; нужен `npm run dev`):

```bash
node scripts/verify-responsive.mjs  # read-only smoke: маршруты/guard, overflow, console, темы, keyboard, RU
node scripts/screenshot.mjs         # скриншоты неаутентифицированных состояний
node scripts/e2e-account-workspace.mjs --check-guard  # проверка отказа мутирующего E2E без isolated runtime
```

Мутирующий E2E (`node scripts/e2e-account-workspace.mjs`) запускается только в явно
подтверждённом изолированном окружении (`E2E_RUNTIME=isolated-ci`, `E2E_ISOLATED_DB=1`,
`E2E_ALLOW_MUTATIONS=1`, allowlisted hosts, `E2E_PASSWORD`). По умолчанию он отказывает
(`NOT_VERIFIED`, exit 2) и не выполняет HTTP-запросов; на локальной рабочей БД не
запускается.

Аутентифицированный кабинет и диалог создания аккаунта требуют работающего backend:
в браузерных скриптах они честно помечаются `NOT_VERIFIED`. Клавиатурная доступность
диалога покрыта unit-тестами (`AppDialog.spec.ts`).

## Сценарии (реальный backend)

Регистрация → подтверждение email → повторная отправка → вход → MFA → выход →
каталог → личный кабинет (создание и список Commercial Accounts) с пагинацией.
Создание аккаунта использует `Idempotency-Key` (генерируется один раз на форму).

## Будущие графики

Контракт визуализации (типы, адаптеры, форматирование, доступность) —
[docs/frontend/CHARTS_CONTRACT.md](../../docs/frontend/CHARTS_CONTRACT.md). Фиктивных
графиков и аналитики сейчас нет.

## Диагностика

- **TS2307 «Cannot find module '*.vue'» в IDE.** Обычный TypeScript-сервер без Vue
  language service не умеет резолвить `.vue`. В `env.d.ts` объявлен `declare module '*.vue'`,
  в `tsconfig.app.json` заданы `baseUrl` и `paths`. Перезапустите TS server в IDE
  (VSCode: «TypeScript: Restart TS Server») или установите расширение Vue (Volar).
  Источник истины — `npm run typecheck`, а не подсветка IDE.
- **Каталог показывает ошибку сети.** Backend не запущен либо неверна цель прокси в
  `.env` (`VITE_COMMERCIAL_PROXY_TARGET`, по умолчанию `http://localhost:8090`).
- **`npm run check:tokens` падает.** `tokens.css` правили вручную. Выполните
  `npm run generate:tokens` и закоммитьте результат.
- **`/cabinet` перенаправляет на `/login`.** Токены живут только в памяти; после
  перезагрузки страницы нужен повторный вход — это ожидаемо.
- **`/mfa` сразу уводит на `/login`.** Нет активного MFA challenge — это корректно.
- **`npm ci` в CI падает на lockfile.** Обновите `package-lock.json` локально
  (`npm install`) и закоммитьте его вместе с `package.json`.
