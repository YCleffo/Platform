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
node scripts/verify-responsive.mjs
```

Эти три проверки не меняют данные сервера. Account/invitation E2E запускаются только
через одноразовый CI runtime с isolation nonce; рабочие БД использовать нельзя.

Шрифты и SVG-иконки локальные, без Google Fonts или icon CDN.
Иконки: `src/shared/ui/icons/svg/`, затем `npm run generate:icons`.

[Архитектура, API, ассеты и browser E2E](../../docs/frontend/README.md).
