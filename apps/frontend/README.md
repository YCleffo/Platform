# Platform frontend

Vue 3 + TypeScript SPA для Core admin и Commercial Control Plane.

```bash
npm ci
npm run dev
```

Dev server: `http://localhost:5173`. `/api/control` идёт в Commercial на 8090, `/api` — в быстрый Core `backend-fast` на 8082. Настройки proxy: `.env.local` (ignored) или `.env.example`.

```bash
npm run check:icons
npm run check:tokens
npm run check:styles
npm run typecheck
npm run lint
npm run format:check
npm test
npm run build
```

Vue форматируется закреплённым Prettier из `package-lock.json`: `npm run format`.
В многострочном теге каждый атрибут расположен на отдельной строке, закрывающая скобка
тоже переносится. Настройки CLI и редактора общие: `.prettierrc.json`. VS Code использует
расширение Prettier для Vue при сохранении; рекомендуется в `.vscode/extensions.json`.

Настройки интерфейса: `/settings`. Примеры dropdown и контекстного меню:
`/settings/components`. Для проверки собранного приложения запустить `npm run preview`,
задать `BASE_URL` на его адрес и выполнить:

```bash
node scripts/verify-appearance.mjs
node scripts/verify-menus.mjs
node scripts/verify-ux.mjs
node scripts/verify-accessibility.mjs
node scripts/verify-responsive.mjs
node scripts/verify-billing.mjs
node scripts/verify-core-security.mjs
node scripts/verify-core-profile.mjs
npm run verify:typography
npm run verify:workflows
npm run test:workbench-contracts
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

Core auth: `/admin/login`, `/activate`, `/password-reset`, `/mfa-recovery`,
`/admin/forgot-password`, `/admin/mfa-recovery`. Core и Commercial используют отдельные API
и сессии. `node scripts/verify-core-auth.mjs` проверяет UI через HTTP fixtures.
`npm run test:e2e:core-auth` проверяет реальные Core API/SMTP на одноразовом стенде;
требует explicit `APP_URL`, `CORE_API_URL`, `MAILPIT_URL`, `CORE_TEST_CONTAINER` и E2E isolation
markers/nonce/password. Guard проверяет nonce и через Core, и через frontend proxy.
Готовая конфигурация стенда и запуска — job `core-auth-browser` в `ci.yml`.

`npm run test:e2e:billing` проверяет реальный zero-price lifecycle через Commercial HTTP,
PostgreSQL, SMTP и MFA: сохранение profile, создание invoice/payment, activation, reload,
отмена auto-renew и немедленная отмена с проверкой entitlements. Нужны Commercial E2E
markers/nonce, `APP_URL`, `CONTROL_API_URL`, `MAILPIT_URL`, `E2E_PASSWORD` и Compose test project.
Скрипт проверяет имя/labels тестовой БД перед seed. Он включён в browser CI после сброса
одноразовых fixtures. Это не проверка платного YooKassa checkout или provider sandbox.

Core Security: `/admin/security/mfa` и `/admin/security/sessions`. `npm run verify:core-security`
проверяет новые страницы и dialogs через HTTP fixtures на dev/preview с `BASE_URL`.
Сценарии включают узкий экран, RU/EN, light/dark, 140% text, QR/manual key, ошибку кода,
одноразовый показ recovery codes, отзыв сессий и явный повторный вход при recent-auth expiry.
Это UI regression, а не доказательство реального MFA mutation lifecycle.

`npm run test:e2e:core-security` запускается на том же доказанном nonce Core test runtime,
что и `test:e2e:core-auth`. Проверяет настоящий UI revoke/revoke-others, enrollment с TOTP,
replay отказ, regeneration, новый MFA login, disable и истечение recent authentication.
Пароли/секреты берутся из guarded fixture только в памяти; рабочие БД запрещены.
CI использует HTTPS с одноразовым сертификатом; исключение trust ограничено test request context.

Моя учётная запись Core: `/admin/profile` и `/admin/profile/consents`, доступны вошедшему
пользователю без административных прав. Язык/часовой пояс, шесть настроек schema v2,
сохранение изменённых полей, отмена и подтверждённый сброс работают через Core API.
Настройки интерфейса аккаунта применяются внутри Core; браузерные настройки `/settings`
сохраняются отдельно. Согласия поддерживают просмотр текста, подтверждение, отзыв и
повторное ознакомление при изменении версии. Редактируемые RU/EN примеры текстов:
`src/modules/admin/profile/consentTemplates.ts`.

`npm run verify:core-profile` проверяет интерфейс с HTTP fixtures, включая пустой реестр
с шаблонами. `npm run test:e2e:core-profile` использует тот же guarded disposable Core
runtime, что и auth/security E2E: настоящий HTTP/PG/Redis, запись и сброс настроек,
согласие/отзыв, параллельные частичные PATCH и повторный вход. Скрипт запускается в CI;
рабочие БД использовать нельзя.

Ключи доступа и внешние аккаунты Core: `/admin/security/passkeys` и
`/admin/security/identities`; вход по passkey доступен на `/admin/login`. Нужны WebAuthn
и secure context; локальный адрес по умолчанию — **http://localhost:5173**. `127.0.0.1`
является другим RP/origin и не заменяет `localhost`. Compose передаёт
`AUTH_WEBAUTHN_RP_ID`, `AUTH_WEBAUTHN_ORIGINS`, `AUTH_WEBAUTHN_RP_NAME` и TTL в Core.
Произвольные origins не разрешаются. Контракт: `contracts/openapi/auth-passkeys.yaml`.

Google/Telegram подключаются в отдельном окне. Исходная Core session остаётся в памяти;
popup не получает токены и opener. Callback передаёт одноразовый код через same-origin
BroadcastChannel, сервер проверяет account/session/state/PKCE. Popup blocker и отмена
показывают локализованную ошибку. Email/имя выводятся текстом, внешние avatar URL не загружаются.
Provider redirect URI остаётся `/auth/callback/google` или `/auth/callback/telegram`.

`npm run verify:core-credentials` проверяет HTTP fixtures и native WebAuthn через виртуальный
аутентификатор Chromium. `npm run test:e2e:core-credentials` выполняет настоящий Core/PG/Redis
цикл с криптографической проверкой, последним способом входа, recent authentication и новым
входом после смены методов. OIDC transport в этом тесте явно синтетический, как в Core auth E2E;
это не проверка живых Google/Telegram или физического ключа. Guard и cleanup обязательны.

Core запросы персональных данных: `/admin/profile/privacy` и `/admin/profile/privacy/:id`.
Обычный вошедший Core account создаёт запрос о себе либо выбирает действующее полномочие
из списка; scopes проверяются сервером при каждом действии. Статусы/сроки берутся из API.
Ответ открывается в общей fullscreen modal; скачивается точный JSON сервера, без повторной
сериализации. Выдача одноразовая: после сетевой ошибки GET автоматически не повторяется.
Документ хранится только в памяти открытой страницы и очищается при уходе/logout.

`npm run verify:core-privacy` проверяет HTTP fixtures, responsive/axe и отказы/таймауты.
`npm run test:e2e:core-privacy` проверяет настоящий Core/PG/Redis: self/representative
submission/delivery, isolation/revocation, PG SHA256/size и 100 authenticated reads @20.
Требуются те же Core mutation guards/nonce и `CORE_TEST_CONTAINER`. Processing-information
E2E требует `CORE_PRIVACY_BROWSER_FIXTURE=1` только на одноразовом стенде: operator name,
address и два purpose disclosure definitions там явно синтетические. Production legal
configuration этим не создаётся. Контракт — [core-privacy.yaml](../../contracts/openapi/core-privacy.yaml).

Операторские запросы: `/admin/privacy` и `/admin/privacy/requests/:id`.
Фильтры и пагинация выполняются сервером. Регистрация офлайн-запроса, этапы рассмотрения,
подтверждение доказательств, исправление полей, продление срока и ограничения имеют отдельные
формы. Контакты выбираются только у человека из запроса; цели — из серверного реестра.
Выдача оператором разрешена только для verified offline intake. Ответ на онлайн-запрос
получает сам субъект либо его представитель. Ручного перехода в completed нет.

`npm run verify:core-privacy-operator` — HTTP fixtures, RU/EN, responsive/axe, права и ошибки.
`npm run test:e2e:core-privacy-operator` — реальный guarded Core/PG/Redis: intake/corrections,
deadlines/blocks/termination, raw responses и Redis presence. Требования к nonce/стенду те же.
Online heartbeat отправляется только после сохранения opt-in, из видимой вкладки с сетью;
отключение настройки/logout прекращает отправку. Client ID живёт в памяти страницы;
только одноразовый OAuth marker временно переносит его через redirect в sessionStorage.
После проверки state marker удаляется. Токены и PKCE verifier в storage не записываются.
TLS dev/preview согласует HTTP/1.1 для совместимости с локальным API proxy; сертификаты
остаются обязательными. Рабочий HTTP dev server по-прежнему использует порт 5173.

`npm run verify:core-configuration` — формы modules/settings/flags, public preview,
HTTP fixtures, responsive/axe, права и ошибки.
`npm run test:e2e:core-configuration` — guarded настоящий Core/PG/Redis, MFA operator,
publication, module lifecycle, typed setting/flag CRUD и read burst. Рабочие БД запрещены.

[Архитектура, API, ассеты и browser E2E](../../docs/frontend/README.md).

Мобильные карточки используют отдельные размеры из design tokens; размер полей ввода
сохраняет защиту от автоматического zoom на iOS. `AppDisclosure` заменяет нативные
раскрывающиеся блоки, `AppBreadcrumbs` сокращает путь и открывает скрытые уровни через меню.
Все модалки используют `AppDialog`: общие header, toolbar, прокручиваемый body и footer.
Для полноэкранного JSON результата используется `AppJsonResponseDialog`.

`AppInput` с типами `date`, `time`, `datetime-local` подключает локальный календарь/время
`AppTemporalPicker`. Он поддерживает RU/EN, ручной ввод, клавиатуру, min/max/step,
disabled/readonly и вложение в модалку. Значение модели остаётся ISO wall time;
компонент не переводит его в UTC. У существующих форм сохраняется их преобразование
часового пояса на границе API. Примеры доступны в `/settings/components`.

`npm run verify:mobile-components` проверяет эти компоненты на 320/393/1440 px,
доступность, вложенные Escape/focus и невозможное время при переходе DST.
`npm run test:e2e:paid-billing` и `npm run test:e2e:operators` требуют одноразового
стенда и существующих mutation guards/nonce. Только browser CI подключает
явный `COMPOSE_PROJECT_NAME`, отличный от рабочего `platform`, и
`compose.browser.yaml`: настоящие HTTP/PG/SMTP/MFA и gateways, явно синтетический
внешний платёжный transport. Это не проверка живого OAuth или YooKassa sandbox.
Production image не содержит классов этого transport и CLI fixtures.

`npm run test:e2e:periodic-billing` проверяет границу платного downgrade, автоматическое
продление после отказа, backoff, grace и поздний settlement после истечения доступа.
Нужен отдельный стенд с `compose.billing-clock.yaml` и `BILLING_TEST_CLOCK_DIRECTORY`:
тестовые PHP/PostgreSQL используют один readonly clock-файл через libfaketime.
Время машины и рабочие сервисы не меняются; даты финансовой истории не переписываются.
CLI worker/fixtures запускаются через `/opt/platform-test/entrypoint`, как HTTP runtime.
В CI стенд поднимает `scripts/ci/billing-clock-start.sh`, задача `Billing · renewal and recovery`.
Provider wire синтетический; внешние OAuth/YooKassa credentials для этой проверки не нужны.

`npm run test:security-dependencies` воспроизводит границы glob/minimatch в ограниченном
дочернем процессе, проверяет прекращение traversal после abort и обычные пути потребителей.
Проверяется отдельно от Vitest; CI запускает её после dependency audit.
