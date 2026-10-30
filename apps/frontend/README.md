# Frontend (Platform)

Первый пользовательский интерфейс Platform на **Vue 3 + TypeScript (strict)**, собранный
на **Vite**. Подключается к существующему **Commercial Control Plane** (Symfony) по
реальному HTTP-контракту из `contracts/openapi/control-plane.yaml`. Backend не
мокается: все данные приходят с живого API.

## Технологии

- Vue 3 (Composition API, `<script setup>`)
- TypeScript в `strict`-режиме (`vue-tsc`)
- Vite 7
- Vue Router 4
- Pinia 3
- vue-i18n 11 (локализация `en` / `ru`)
- ESLint 9 (flat config) + `typescript-eslint` + `eslint-plugin-vue`

## Структура

```text
src/
  app/
    router/           # маршруты и guard авторизации
    layouts/          # AppLayout (основной), AuthLayout (авторизация)
  core/
    auth/             # in-memory сессия и Pinia-стор авторизации
    account/          # Pinia-стор коммерческих аккаунтов
  modules/commercial/
    onboarding/       # регистрация, подтверждение email
    auth/             # вход, MFA challenge
    catalog/          # опубликованные тарифы
    account/          # личный кабинет (список и создание аккаунтов)
  shared/
    api/              # HTTP transport + commercial-клиент (typed)
    types/            # типы из OpenAPI-контрактов
    ui/               # базовые компоненты (Button, FormField, Dialog, ...)
    composables/      # useToast, useAsyncAction
    errors/           # безопасное приведение ошибок к сообщению
    i18n/             # словари en/ru и инициализация
    styles/           # design tokens и базовые стили
```

## Запуск

Требуется Node.js 20.19+ (проверено на 24).

```bash
cd apps/frontend
npm install
npm run dev        # http://localhost:5173
```

Dev-сервер проксирует API через Vite:

- `/api/control/*` → Commercial Control Plane (по умолчанию `http://localhost:8090`)
- `/api/*`         → Yii Core backend (по умолчанию `http://localhost:8080`)

Цели прокси настраиваются переменными окружения (`apps/frontend/.env`,
см. `.env.example`):

```bash
VITE_CORE_PROXY_TARGET=http://localhost:8080
VITE_COMMERCIAL_PROXY_TARGET=http://localhost:8090
```

Никакие `localhost`-адреса не зашиты в компонентах приложения.

## Сборка и проверки

```bash
npm run typecheck   # vue-tsc --noEmit (strict)
npm run lint        # eslint .
npm run build       # typecheck + production-сборка Vite
```

## Сценарии (реальный backend)

1. Регистрация Commercial-пользователя (`POST /api/control/auth/register`)
2. Подтверждение email (`POST /api/control/auth/verify`, токен из письма через `/verify-email?token=…`)
3. Повторная отправка подтверждения (`POST /api/control/auth/verification/resend`)
4. Вход (`POST /api/control/auth/login`)
5. MFA challenge при требовании backend (`POST /api/control/auth/mfa/login/verify`)
6. Выход (`POST /api/control/auth/logout`)
7. Просмотр опубликованных тарифов (`GET /api/control/catalog/plans`)
8. Личный кабинет (`GET /api/control/me`, `GET /api/control/accounts`, `POST /api/control/accounts`)

Создание аккаунта использует `Idempotency-Key` (генерируется один раз на форму).

## Безопасность сессий

Access и refresh токены хранятся **только в памяти** приложения (не в
`localStorage`/`sessionStorage`/cookies). После перезагрузки страницы сессия не
восстанавливается и требуется повторный вход — это осознанное ограничение текущего
этапа. При 401 выполняется одна попытка обновления access-токена через
`refresh_token` (одновременные обновления сериализуются, чтобы не срабатывал отзыв
семейства при повторном использовании refresh-токена); при неудаче сессия очищается
и происходит переход на экран входа. Персистентная сессия через HttpOnly-cookie/BFF
на этом этапе не реализована.

Ошибки выводятся из санитизированного ответа API или фиксированных сообщений;
токены и учётные данные в сообщениях об ошибках не отображаются.
