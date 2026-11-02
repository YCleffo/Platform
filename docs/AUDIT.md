# Задание агенту: закрыть пробелы БД → API → frontend Platform

Ты работаешь с `C:\Platform`. Твоя задача — довести существующие пользовательские сценарии,
устранить перечисленные дефекты и подтвердить результат проверками. Используй фактическую
матрицу ниже как стартовую точку. Каждое изменение должно сохранять разделение Core,
Commercial и будущего Education.

## 1. Основание и границы задания

Аудит выполнен **2 ноября 2026**, локально. HEAD: `fa1c64d719c7d630f2ce7d3daa15a43e3e99669b`.
Проверялся также **незакоммиченный и untracked код**: новая админка, billing, usage,
analytics, audit, privacy, новые backend readers и изменения общих компонентов.
Один SHA не описывает весь проверенный результат. Перед изменениями сохрани актуальный
`git status` и сверь состояние файлов: рабочая копия продолжает развиваться.

Правила работы:

- Не делай commit/push и не запускай удалённый GitHub CI. Проверяй его команды локально.
- Сохраняй чужие изменения, библиотеки иконок/шрифтов, макет, секреты и рабочие данные.
- Рабочие PostgreSQL не использовать для изменения бизнес-данных. Допустимы чтение
  метаданных и read-only UI smoke с обычными login/logout и их служебными событиями. Reset,
  fixtures, destructive/integration tests выполнять на отдельном одноразовом стенде.
  После проверки удалить созданные контейнеры, тома, сети, credentials и временные файлы.
- Core admin и Commercial customer имеют разные identities, сессии и полномочия.
  Не отправлять токен одной области в другую; не выдавать Core superadmin полномочия
  финансового или privacy-оператора Commercial автоматически.
- Не подменять отсутствующий backend фиктивными данными в приложении. HTTP fixtures
  допустимы в изолированных тестах и должны быть явно обозначены в результате.
- Весь итог вести в **этом одном MD**: обновлять статусы, матрицу и результаты. Не создавать
  новый набор датированных аудитов, агентских brief и сырых отчётов внутри `docs`.

## 2. Проверенная БД и граница API

| Проверка живой установки | Core / Runtime | Commercial |
| --- | --- | --- |
| Прикладные таблицы | 50 `core` + 3 `platform_runtime` | 61 `commercial` |
| Применённые миграции | 33 | 42 |
| Несовпадения live catalog и сохранённой схемы, `export_schema.py --check` | 0 | 0 |
| Неготовые/невалидные индексы | 0 | 0 |
| Невалидированные ограничения | 0 | 0 |
| Фактическая runtime-роль | `platform_app` | `platform_control` |
| SUPERUSER / CREATEDB / CREATEROLE / BYPASSRLS | все включены | все включены |

Последняя строка — открытый production blocker. Наличие отдельного provisioning script
и role gate не означает, что HTTP/worker runtime уже использует ограниченные credentials.
Текущая проверка структуры не является полной проверкой бизнес-данных, backup/restore,
платёжной сверки, межбазовых логических ссылок или поведения после аварии.

Дополнительно проверено:

- DBML: 50 Core tables / 86 FK, 3 Runtime / 1 FK, 61 Commercial / 97 FK — PASS.
- `build-reference.py --check` — PASS; `build-coverage.py --check` — **FAIL: Coverage inventory drift**.
  Схема совпадает, но справочник source/test references устарел после новых readers.
- В Symfony controllers найдено **78 явно объявленных HTTP method/path operations**;
  их набор совпал с `contracts/openapi/control-plane.yaml`. Из них 70 — `/api/control/*`,
  4 — health, 3 — M2M, 1 — webhook. Это инвентаризация контрактов, а не 78 пройденных E2E.
- В Core routes найдено 139 literal method/path operations: 51 `/api/auth/*`,
  82 `/api/core/*`, ещё 6 остальных. Это не процент покрытия интерфейсом.
- Хеши пяти выбранных backend файлов совпали с работающими образами: Commercial
  AnalyticsController, AuditController, GetAccountAnalytics; Core fast ListAuditEventsAction
  и routes.php. Полная воспроизводимость всех образов этим не доказана.

Источники: [schema catalogs](database/README.md),
[Core routes](../apps/backend/config/common/routes.php),
[Commercial controllers](../apps/control-plane/src/Infrastructure/Http),
[OpenAPI](../contracts/openapi/control-plane.yaml).

## 3. Что уже представлено во frontend

В router **34 именованных маршрута**: 22 customer/shared/settings/fallback и 12 Core admin
вместе с отдельным входом. Наличие маршрута не означает готовность всех его действий.

Обозначения: **E2E** — пройден сценарий с реальным одноразовым Commercial backend;
**READ** — проверено чтение через реальный API; **CODE** — действие найдено в коде,
полный lifecycle не подтверждён; **BLOCKED** — намеренно отсутствующая возможность.

### Commercial и общие настройки

| Экран / route | БД и API | Фактическое покрытие |
| --- | --- | --- |
| `/register`, `/verify-email`, `/resend-verification` | `control_users`, email verifications; `/api/control/auth/register`, `/verify`, `/verification/resend` | E2E resend после reload, ротация verification token; ошибки/публичные состояния проверены |
| `/login`, `/mfa` | Commercial sessions, auth challenges, TOTP/recovery; `/api/control/auth/*` | Login E2E; полный invitation/MFA flow в этом проходе **не прошёл** |
| `/forgot-password`, `/reset-password` | password resets; request/confirm APIs | E2E: реальное письмо Mailpit, новая вкладка, single-use, invalid/missing token, гигиена URL |
| `/` | `plans`, `plan_prices`, `features`, `plan_features`; catalog API | Каталог и переход к выбранному тарифу есть; клиентский выбор не подтверждает оплату |
| `/cabinet` | `commercial_accounts`, memberships; accounts GET/POST | E2E: создание двух аккаунтов, переключение, чужой/неизвестный аккаунт, повторный вход |
| `/accounts/:id` | account detail + server permissions | E2E overview, active navigation и переходы |
| `/accounts/:id/members` | memberships; list/change/remove APIs | Чтение E2E; действия и last-owner guard есть в коде; полный lifecycle сегодня не завершён |
| `/accounts/:id/invitations`, `/invitation` | account invitations, outbox; create/list/resend/revoke/preview/accept/decline | CODE; общий invitation E2E сегодня падает раньше завершения сценария |
| `/accounts/:id/billing` | billing profiles, subscriptions/items/changes, invoices/lines, payments, methods, receipts, refunds | READ открытия страницы; CODE сохранения профиля, checkout, отмены, смены тарифа, методов и refund. Покупка/возврат/возобновление после reload **не подтверждены** |
| `/accounts/:id/usage` | entitlements + usage counters/periods | READ через реальные `/entitlements`, `/usage`; изменение usage идёт через backend/M2M |
| `/accounts/:id/analytics` | агрегаты memberships, invoices/payments, audit, usage | READ реального `/analytics`, график появляется; формулы, периоды, полномочия и суммы требуют отдельных тестов |
| `/accounts/:id/audit` | `commercial.audit_events`; cursor/filter API | READ реальных account events; изоляция, фильтры, пагинация и late responses требуют тестов |
| `/settings/security` | sessions, password, TOTP, recovery codes | Экран расширен: все сессии, revoke/revoke others, change password, regenerate codes, disable MFA, enrollment/step-up. Чтение проверено; новые мутации — CODE |
| `/settings/privacy` | Commercial personal-data export | E2E реального скачивания JSON; другие privacy workflows здесь не представлены |
| `/settings` | browser preferences; backend не нужен | PASS: theme/locale/density/font/spacing/radius/shadows/motion, Undo, persistence, reduced motion, blocked storage |
| `/settings/components` | общие UI-компоненты | PASS основных controls, dropdown/search/cascade/mobile Back/context menu/dialog; новые tooltip/sortable/mobile-menu не получили полного отдельного покрытия |
| catch-all 404 | router | Публичные состояния и responsive smoke проходят |

Для account routes API prefix — `/api/control/accounts/:id`. Commercial Security и
Core Admin Security — разные пользовательские контуры.

### Core admin

11 страниц после входа открыты через **реальный Core API** на 1440/393/320 px: 33 состояния
плюс Create-dialog. Проверялось чтение и открытие диалога; бизнес-сущности
не создавались и не удалялись, пароли/роли/политики не менялись. Обычные login/logout
создали и отозвали сессию проверки, а также соответствующие служебные события.

| Route | API / таблицы | Что доступно сейчас и что не подтверждено |
| --- | --- | --- |
| `/admin/login` | отдельные `/api/auth/login`, `/mfa/verify`, `/me`, `/refresh`, `/logout` | Реальный login/logout проверен; MFA, refresh failure и lifecycle требуют отдельного E2E |
| `/admin` | Core list APIs | READ счётчиков; распределение account statuses считается по первой странице до 100 записей, не по полному набору |
| `/admin/organizations` | organizations, organization_memberships | READ; create/add/leave members представлены generic JSON actions |
| `/admin/persons` | persons, person_contacts | READ; create/update/archive/contacts — JSON actions, без полного mutation E2E |
| `/admin/accounts` | Core accounts, service_credentials, account_roles | READ; lifecycle/invite/credentials/roles — JSON actions. Имена подгружаются только из первых 100 persons |
| `/admin/rbac` | roles, permissions, role_permissions, account_roles | READ; create/update/delete/replace permissions/assign/revoke — generic actions |
| `/admin/modules` | modules, module_settings | READ; enable/disable, настройки — generic actions |
| `/admin/settings` | system_settings, feature_flags | READ; get/put/delete — generic actions |
| `/admin/privacy` | requests, legal holds, erasure, incidents, representations, distribution consent | READ основных вкладок; часть сложных операций — ввод JSON/UUID. Не весь Core privacy API представлен |
| `/admin/audit` | `core.audit_events`; `/api/core/audit-events` | READ, backend требует superadmin. Фильтры backend action/success/actor в generic UI не представлены |
| `/admin/security` | Core password-change API | Форма смены пароля есть; Core sessions/MFA/passkeys/external identities здесь не управляются |
| `/admin/finance` | Commercial finance operator APIs | **BLOCKED**, полноценного финансового кабинета нет; запрещённая граница Core → Commercial не обходится |

JSON/UUID-консоль — технический доступ к части API, а не законченный пользовательский UX.
Не считать наличие кнопки доказательством корректного payload, permissions или успешного действия.

## 4. Что БД/backend умеют, а готового интерфейса нет

| Область | Наличие данных/контрактов | Статус frontend |
| --- | --- | --- |
| Core account settings, notifications/privacy preferences | `account_settings`, `/api/auth/me/settings*` | Нет отдельного пользовательского Core settings UI; локальные appearance preferences этого не заменяют |
| Core passkeys, внешние identities/providers и расширенная безопасность | `auth_passkeys`, `auth_external_identities`, `auth_providers` и auth APIs | Admin login умеет challenge; управления lifecycle нет |
| Полный Core privacy workflow | requests/deadlines/blocks/retention/erasure/evidence/incidents/representation tables | Есть часть JSON operations; нет полноценного ведения сроков, correction evidence, corrections, blocks/release, processing termination |
| Commercial financial operator | financial_operators, external_compensation_attestations; 4 compensation operations | Отдельная операторская сессия/роль и рабочий UI отсутствуют |
| Commercial privacy operator | subject requests/holds/incidents/erasure/lifecycle; 5 operator operations | Customer export есть; операторских экранов нет. Core Privacy не заменяет Commercial Privacy |
| Deployments/domains/operations, licenses/installations, maintenance, releases | соответствующие Commercial таблицы, M2M/CLI процессы | Customer/operator lifecycle UI не найден. Сначала определить продуктовую границу и существующий public API |
| Outbox/inbox, job status, provider events/attempts, idempotency, signing keys | служебные таблицы обеих областей | Это инфраструктура. Не создавать прямой CRUD/редактор секретов ради «покрыть все таблицы» |
| Education «Сегодня» из SVG 393×852 | макет и дизайн-токены | Education API/экраны ещё не реализованы. Commercial Analytics не является этим dashboard |

Определи release scope для отсутствующих областей. Не добавляй их автоматически все в текущий
релиз и не объявляй покрытыми по совпадению названий таблиц или наличию JSON editor.

## 5. Подтверждённые дефекты и обязательные исправления

### P1 · Изоляция admin cache между сессиями

`modules/admin/adminCache.ts` хранит результаты и in-flight requests по одному path.
`clearAdminCache()` определён, но нигде не вызывается. Logout/unauthorized не очищают
данные; ключ не включает identity/session epoch.

**Браузерное воспроизведение с HTTP fixtures:** пользователь A открыл Persons; после
logout и входа B без прав, прямой переход B на `/admin/persons` показал запись A во время
ожидания 403. Одновременно была видна Create-кнопка. Это подтверждение дефекта клиентской
изоляции; обход серверной авторизации этим не доказан.

**Сделать:** очистку/разделение cache по auth boundary; отмену или epoch-check незавершённых
requests до записи в cache/state. После logout, expiry, смены пользователя старые данные
не должны появляться даже на один кадр. Проверить также ответы A, пришедшие уже после clear.

### P1 · Действующий пароль в локальном admin smoke script

В untracked `apps/frontend/scripts/_admin-ux.mjs` обнаружены literal identifier и password;
существующий вход с ними прошёл. Значения в этот документ не перенесены.

**Сделать:** убрать literal credentials из script, получать их из env/одноразового fixture;
обработать смену раскрытого credential без потери доступа владельца. Проверить staged diff,
чтобы секрет не попал в commit. Не удалять произвольные `.env` и рабочие credentials.

### P1 · Штатный invitation/MFA E2E не проходит

Первый запуск остановился на ожидании account link; снимок показал долгое loading state.
После чистого reset повтор остановился в Security: MFA status вернул **502**,
Vite записал **`read ECONNRESET`**, ожидание session list истекло.
`keepAlive: false` и JSON error mapping уже добавлены в Vite; считать проблему закрытой нельзя.

**Сделать:** собрать коррелированные browser/proxy/HTTP/DB timings на отдельном стенде,
разделить зависший запрос, transport reset и state/loading bug. Исправить причину;
не маскировать retry, отключением assertion, MFA или повышением timeout без объяснения.
Повторить полный flow и на штатном Linux runner/его локальном эквиваленте.

### P1 для CI · Дизайн и документация расходятся с источниками

- `check:tokens` падает: в generated `tokens.css` вручную добавлен `--color-danger-800`,
  которого нет в каноническом JSON/generated output.
- `check:styles` даёт 11 сообщений: жёсткие timings в AdminLayout/Tooltip, отсутствующий
  общий theme transition в нескольких компонентах, неопределённый `--radius-card` в AppMobileMenu.
- `build-coverage.py --check` падает: сохранённый справочник references устарел.

**Сделать:** перенести нужный token в canonical JSON/генератор, затем регенерировать CSS;
перенести motion на существующие переменные, исправить radius и theme transitions;
пересоздать coverage inventory. Не выбрасывать нужный danger color ради зелёного gate.

### P2 · Admin mobile overflow и доступность

В реальных данных на **393 и 320 px** document overflow воспроизвёлся на Persons,
Accounts, RBAC, Settings, Audit. Это 10 проверенных состояний. Наличие горизонтального
scroll у таблицы не предотвращает расширение всей страницы.

Axe нашёл `color-contrast` у опасных row actions на Persons/Accounts/RBAC/Settings
в проверенной светлой теме. В Create-dialog дополнительно найден `label` у `textarea`.

**Сделать:** ограничить min-width/grid tracks/card/table wrapper, оставить прокрутку внутри
таблицы или мобильные строки; сохранить sticky headers. Исправить contrast штатными
токенами, дать editor label/description. Проверить RU/EN, обе темы, 320/393/1440, zoom,
font scale, keyboard, dialog и длинные значения.

### P2 · Запоздалый admin login перехватывает навигацию

В `AdminLoginView.vue` / `core/admin/adminAuthStore.ts` нет защиты lifecycle, аналогичной
Commercial login. В браузерном тесте login response задержан; пользователь перешёл на
`/settings`, после ответа оказался на `/admin`.

**Сделать:** AbortSignal + operation/session guard для login/MFA/establish/person-name
lookup. Не применять результат после ухода со страницы, logout или нового входа.

### P2 · RBAC и generic admin contract недостаточны

Sidebar фильтрует permissions; resource routes проверяют преимущественно факт входа.
Generic create/action controls не имеют сопоставления с write permissions.
`shared/api/core/adminHttp.ts` проверяет только envelope status и приводит data к `T`;
невалидная структура списка может выглядеть как пустой список.

**Сделать:** показывать только разрешённые действия, обрабатывать direct URL/403, добавить
runtime guards для Core responses и pagination. Сервер остаётся источником полномочий.
Заменить JSON/UUID forms основных сценариев типизированными формами, поиском сущностей,
валидацией и подтверждением опасных действий; advanced JSON оставить явно обозначенным.

### До приёмки Billing · Незавершённый lifecycle и восстановление

В коде есть цепочка subscription → invoice → payment → checkout и callbacks для refunds,
plan change и payment methods. В `AccountBillingView.vue` checkoutFlow, invoiceFlow,
lastInvoice/lastPayment, planChange и documents хранятся в component refs. `load()`
восстанавливает подписку через entitlements, выбирающие только `status='active'`.
Полного списка истории invoices/payments и восстановления незавершённой операции нет.
Renewal invoice/payment доступны отдельно; это не общая история покупок.

**Проверить и закрыть:** reload/возврат от provider на каждом шаге, повтор после timeout,
pending/failed/cancelled payment, inactive/past_due subscription, plan change,
переключение account с незавершённым flow. Обнулять account-specific state и отбрасывать
поздние ответы. Idempotency key привязывать к намерению и payload; не создавать новую
операцию из-за reload. При необходимости добавить серверный read/recovery API.
Это открытый пробел приёмки по анализу кода; реальная покупка или потеря денег не моделировалась.

### Архитектурные и эксплуатационные долги

- `shared/api/core/adminHttp.ts` импортирует `core/admin/session`: нарушается описанное
  направление shared → composition root. Собрать Core transport в `core`, передавать
  session callbacks через параметры; использовать существующий общий HTTP transport.
- Analytics/Audit имеют guard по accountId, но при нескольких запросах одного account
  с разными filters/days нет полноценного request generation guard. Проверить out-of-order
  responses; это гипотеза для regression test, не выданный за воспроизведение дефект.
- Accounts admin join только первых 100 persons и dashboard distribution первых 100 accounts
  не масштабируются до полного набора. Нужен server aggregate/lookup либо явная пометка
  выборки; нельзя показывать её как общую статистику.
- Runtime PostgreSQL роли всё ещё суперпользователи. Переключить их по отдельному плану
  provisioning и проверить HTTP/workers на ограниченных ролях.

## 6. Какие проверки уже выполнены в этом аудите

| Проверка | Фактический результат 2 ноября |
| --- | --- |
| Frontend Vitest | **157 PASS / 34 spec files** |
| Typecheck, ESLint, production build, local icons | **PASS** |
| Generated tokens / shared styles | **FAIL / FAIL**, причины выше |
| UX, controls, menus, appearance, public responsive | **PASS**; controls 30 assertions; UX 30 menu + 10 dialog cycles без роста listeners |
| Public axe | **30 состояний, 0 автоматических A/AA violations** |
| Commercial workspace, одноразовый backend | **PASS**, включая новые read pages и privacy download |
| Commercial recovery, одноразовый backend | **PASS** |
| Invitation/MFA | **FAIL**, два запуска; полный lifecycle не подтверждён |
| Core admin real read smoke | 11 страниц × 3 ширины + dialog; API/page errors не зарегистрированы, но есть overflow/axe failures |
| Admin synthetic regression probes | Cache A → B и late login воспроизведены; это HTTP fixtures, не проверка серверного обхода |
| Live schema, reference, DBML, Pyright | **PASS**, Pyright 0 errors/warnings |
| Generated coverage inventory | **FAIL** |

Не переносить вчерашние 514 Core / 449 Commercial и нагрузочные числа в сегодняшние PASS:
полные backend suites, новый stress, rebuild/Trivy всех образов и remote Actions здесь
не запускались. Production readiness остаётся **NOT_READY**.

Одноразовый стенд после аудита удалён: 3 контейнера, 2 тома, 2 сети.
Исходные 13 контейнеров сохранили ID, image, StartedAt и mounts. Рабочие БД и MyBots
не сбрасывались; созданная для smoke admin session завершена logout.

Нет отдельных `.spec.ts` в `modules/admin`, `core/admin`, `shared/api/core`, новых
`commercial/billing`, `usage`, `analytics`, `audit` и settings pages. Это файловая
инвентаризация, не инструментированный line coverage. Часть read-сценариев уже есть
в workspace E2E; новые billing/security/admin mutations этим не покрываются.
У SecuritySettingsView три component tests проверяют в основном status/session rendering.
Admin `_admin-ux.mjs` не является полноценным CI E2E suite.

Старые проблемы закрывать только по текущим данным: reset перед recovery добавлен;
native reset теперь синхронизируется после нативного события, browser controls проходят;
role gate проверяет SQLSTATE 42501. Рабочие DB roles и proxy/MFA failure остаются открытыми.

## 7. Порядок выполнения и критерии готовности

1. Защитить admin cache/session boundary, убрать literal credential, закрыть late auth.
   Regression tests обязаны падать на старой реализации.
2. Вернуть зелёные tokens/styles/docs gates и исправить admin mobile/contrast/editor label.
3. Устранить причину invitation/MFA failure; выполнить исходный сценарий полностью.
4. Покрыть новые области: Core admin CRUD/RBAC/privacy на disposable Core; Commercial
   security lifecycle; Billing purchase/renewal/cancel/refund/plan-change/recovery на
   изолированном provider sandbox или управляемом test gateway с явной маркировкой.
5. Довести основной admin UX: обычные формы, выбор сущностей вместо ручных UUID,
   поиск/фильтры, field errors, permissions, empty/error/loading, retry и back navigation.
6. Зафиксировать продуктовую границу отсутствующих operator/deployment/Education функций.
   Обновить матрицу в этом документе: что стало READ/E2E, что осталось CODE/BLOCKED.
7. Выполнить локальные CI-команды на финальной рабочей копии, удалить только свои
   одноразовые ресурсы, сверить рабочие containers/volumes/DB до и после. Без коммитов.

Минимальная матрица regression:

- Два разных пользователя; logout/expiry; late response после смены сессии; отсутствие
  stale data в cache, forms, dialogs, dashboard и personal-data export.
- Commercial owner/admin/billing/technical и чужой account; Core read-only/write/superadmin;
  direct URL, серверный 403/404, скрытые write actions и отсутствие межконтурных токенов.
- 401+refresh, 403/404, 409, 422 с field errors, 429, 502/network failure; request cancellation,
  два быстрых filter/page/account changes; отсутствие бесконечного skeleton и false success.
- Существующие native/reset/Space/Tab/labels/disabled/mixed/range/Undo/menu/cascade/context
  contracts; desktop/mobile, RU/EN, обе темы, reduced motion и 140% text.
- Billing: повторное нажатие, повтор после неопределённого результата, reload, provider return,
  polling конечного статуса, документы/история, partial refund и запрещённые действия.
- DB: миграции fresh/upgrade, SQLSTATE denial tests с намеренной выдачей запрещённого права,
  поведение HTTP/workers под runtime-role. Не очищать рабочие данные.

Команды frontend из `apps/frontend`:

```powershell
npm run test
npm run typecheck
npm run lint
npm run check:icons
npm run check:tokens
npm run check:styles
npm run build
```

Проверки документации из корня:

```powershell
python scripts/database/export_schema.py --plane core --check
python scripts/database/export_schema.py --plane commercial --check
python scripts/documentation/build-reference.py --check
python scripts/documentation/build-coverage.py --check
python scripts/documentation/check-links.py
npm run check:dbml --prefix scripts/documentation
npm run check:python --prefix scripts/documentation
```

Browser scripts запускать против указанного собственного dev/production preview; мутации —
только при реальном isolated-runtime guard/nonce. После destructive suites выполнять reset
fixtures и перезапускать outbox worker между независимыми потоками, сохраняя rate limits.

Итоговая приёмка: один обновлённый этот MD с подтверждёнными изменениями, точными PASS/FAIL,
непройденными сценариями и cleanup. Отдельно подтвердить, что JSON токены, локальные
шрифты/иконки и исходный SVG сохранены, GitHub Actions не выдан за локальный прогон,
а непроверенные платёжные/операторские сценарии не объявлены готовыми.
