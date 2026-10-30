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

## Account context

`core/account/accountStore.ts` — единый контекст аккаунтов: список, выбранный аккаунт,
пагинация (`limit`/`offset`/`total` существующего API), загрузка, ошибки, инвалидация по
epoch и защита от устаревших ответов. Создание аккаунта не перезагружает кабинет — диалог
сообщает об успехе, страница обновляет первую страницу.

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
