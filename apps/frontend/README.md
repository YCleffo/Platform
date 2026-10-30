# Frontend (Platform)

Пользовательский интерфейс Platform на **Vue 3 + TypeScript (strict)**, собранный на
**Vite**. Подключается к **Commercial Control Plane** (Symfony) по реальному
HTTP-контракту `contracts/openapi/control-plane.yaml`. Backend не мокается.

## Дизайн-система

Единый источник токенов — `стили.json` (Figma Tokens). Генератор
`scripts/generate-tokens.mjs` превращает его в `src/shared/styles/tokens.css`
(сырые токены: палитра, статусы, тени, отступы, радиусы, размеры). Семантические
алиасы (`--bg`, `--surface`, `--card`, `--border`, `--text-1`, `--accent`, …)
живут в `theme.css` и переключаются атрибутом `data-theme`.

```bash
npm run generate:tokens   # пересобрать tokens.css из стили.json
```

- Цвета: `#2260FF` (primary), `#F6F8FC` (фон), `#FFFFFF` (карточка), border-first.
- Типографика: Inter, отдельные mobile/desktop значения (`typography.css`).
- Радиусы: control 8/10/12, card 14/16/20/24, modal 20, pill 999.
- Тени: Soft/Card/Popover/Modal/Focus Ring (light + dark).

## Темы (Light / Dark / System)

Тема задаётся атрибутом `data-theme` на `<html>` (без вспышки при загрузке —
inline-скрипт в `index.html`). Логика — `src/app/theme/index.ts`. Приоритет:
`system` (автовыбор по `prefers-color-scheme`) по умолчанию, явные `light`/`dark`
переключаются в шапке. Выбор хранится в `localStorage` (`platform.theme`) — это
UI-предпочтение, а не authentication token.

## Технологии

- Vue 3 (Composition API, `<script setup>`)
- TypeScript `strict` (`vue-tsc`)
- Vite 7, Vue Router 4, Pinia 3
- vue-i18n 11 (`en` / `ru`)
- @lucide/vue (единый набор SVG-иконок)
- ESLint 9 (flat config)

## Структура

```text
src/
  app/
    router/           # маршруты и guard авторизации
    layouts/          # AppLayout (header + bottom nav), AuthLayout
    theme/            # light/dark/system переключение
  core/
    auth/             # in-memory сессия + Pinia-стор
    account/          # Pinia-стор коммерческих аккаунтов
  modules/commercial/
    onboarding/       # регистрация, подтверждение email
    auth/             # вход, MFA
    catalog/          # тарифы
    account/          # личный кабинет
  shared/
    api/              # HTTP transport + commercial-клиент (typed)
    ui/
      actions/        # AppButton, AppIconButton
      fields/         # AppInput, AppPasswordInput
      feedback/       # AppBadge, AppEmptyState, AppErrorState, AppSkeleton, AppToast
      navigation/     # AppHeader, AppBottomNav
      overlays/       # AppDialog (focus trap, bottom sheet на мобильном)
      surfaces/       # AppCard, AppDivider
    styles/           # tokens.css (генер.), theme.css, typography.css, base.css
    composables/      # useToast, useAsyncAction, useFocusTrap
    errors/           # безопасные сообщения об ошибках
    i18n/             # словари en/ru
```

## Запуск

Node.js 20.19+ (проверено на 24).

```bash
cd apps/frontend
npm install
npm run dev        # http://localhost:5173
```

Dev-прокси (цели настраиваются в `.env`, см. `.env.example`):

- `/api/control/*` → Commercial Control Plane (`http://localhost:8090`)
- `/api/*` → Yii Core (`http://localhost:8080`)

## Проверки

```bash
npm run typecheck        # vue-tsc (strict)
npm run lint             # eslint
npm run build            # typecheck + production-сборка
npm run generate:tokens  # пересборка токенов из стили.json
```

Визуальная проверка (Playwright + установленный Chrome/Edge; требует запущенный
`npm run dev`):

```bash
node scripts/screenshot.mjs         # скриншоты экранов в screenshots/
node scripts/verify-responsive.mjs  # 320–1440px: переполнение и ошибки
```

## Сценарии (реальный backend)

Регистрация → подтверждение email → повторная отправка → вход → MFA → выход →
каталог → личный кабинет (создание и список Commercial Accounts).
Создание аккаунта использует `Idempotency-Key` (генерируется один раз на форму).

## Безопасность сессий

Access и refresh токены — **только в памяти** (не в `localStorage`/`sessionStorage`/
cookies); после перезагрузки требуется повторный вход. При 401 — одна попытка
обновления через `refresh_token` (одновременные обновления сериализуются); при
неудаче сессия очищается. Персистентная сессия (HttpOnly-cookie/BFF) на этом этапе
не реализована. Токены и учётные данные в ошибках не отображаются.
