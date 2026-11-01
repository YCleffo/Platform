# Platform frontend

Vue 3 + TypeScript SPA для Commercial Control Plane.

```bash
npm ci
npm run dev
```

Dev server: `http://localhost:5173`. Proxy настраивается через `.env.example`.

```bash
npm run check:icons
npm run check:tokens
npm run check:styles
npm run typecheck
npm run lint
npm test
npm run build
```

Настройки интерфейса: `/settings`. Примеры dropdown и контекстного меню:
`/settings/components`. Для проверки собранного приложения запустить `npm run preview`,
задать `BASE_URL` на его адрес и выполнить:

```bash
node scripts/verify-appearance.mjs
node scripts/verify-menus.mjs
node scripts/verify-ux.mjs
node scripts/verify-accessibility.mjs
node scripts/verify-responsive.mjs
```

Эти проверки не меняют данные сервера. UX-прогон сохраняет screenshots и проверяет повторные
открытия без утечки глобальных обработчиков. Проверка axe-core запускается без исключений
правил для WCAG A/AA; authenticated matrix в browser E2E включает Security Center, MFA setup,
recovery codes и MFA challenge. Account/invitation/recovery E2E запускаются только
через одноразовый CI runtime с isolation nonce; рабочие БД использовать нельзя.
Сценарии восстановления: `/forgot-password`, `/reset-password?token=…` и
`/resend-verification`; неизвестные URL показывают локализованную 404-страницу.

Нагрузка: `npm run test:load` с `LOAD_PROFILE=smoke|standard|stress` и теми же обязательными
E2E isolation markers/nonce. Перед запуском на **одноразовой** Commercial БД применить
`scripts/ci/seed-load-catalog.sql` (через psql из корня репозитория). Скрипт отказывается
работать с другим именем БД. Smoke — 1/5 одновременных запросов по 5 s;
standard — 1/5/20/50 по 15 s; stress добавляет 100. Четыре GET endpoint проверяются
с валидным токеном и непустыми fixtures; error rate ≤1%, p95 ≤1000 ms для каждого этапа
и endpoint. JSON сохраняется в screenshots. Это локальный closed workload, не production SLO.

Шрифты и SVG-иконки локальные, без Google Fonts или icon CDN.
Иконки: `src/shared/ui/icons/svg/`, затем `npm run generate:icons`.

[Архитектура, API, ассеты и browser E2E](../../docs/frontend/README.md).
