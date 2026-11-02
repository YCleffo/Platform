# Промпт для агента: доведение Platform после полного аудита

Дата проверки: **2 ноября 2026**. Исходная ревизия: `6fcb7ad`; результаты ниже относятся
к ней с исправлениями этого прохода. Это задание для следующего агента с проверенным
состоянием проекта, границами доказательств и порядком работ.

**Ты работаешь в `C:\Platform`. Доведи оставшиеся сценарии до проверяемого результата.
Сначала прочитай этот файл и соответствующие контракты. Не объявляй проект готовым
только по зелёному CI. Общий production verdict: NOT_READY.**

## 1. Правила выполнения

- Сохраняй рабочие данные Core, Commercial и MyBots. Тестовые destructive suites запускай
  только в отдельном Compose project с отдельными БД/Redis/томами. После проверки удаляй стенд.
- Core destructive tests требуют `APP_ENV=test`, `TEST_ISOLATED_RUNTIME=1`,
  `platform_test`, `redis-test`; Commercial — `CONTROL_TEST_ISOLATED=1` и
  `platform_commercial_test`. Не ослабляй эти guards.
- Не удаляй исходные Solar SVG, шрифты, дизайн JSON/SVG, миграции и действующие контракты.
- Не смешивай Core Account, Commercial Control User и Commercial Account; не переиспользуй
  клиентскую сессию вместо отдельной операторской авторизации.
- Не отключай проверки, не добавляй CVE исключения ради зелёного статуса.
  HTTP fixtures допустимы для UI regression; явно отличай их от реального backend E2E.
- Пользователь разрешил коммит с русским сообщением и проверку GitHub CI. Не делай force push.
- Обновляй этот MD. Не создавай новые датированные аудиты, агентские brief и дублирующие отчёты.

## 2. Что исправлено в этом проходе

### PHP2Shell и работающие образы

Подтверждён исходный уязвимый runtime: **PHP 8.3.33 ZTS** во всех семи PHP-сервисах Platform.
PHP2Shell — уязвимости PHP, а не Composer-пакет. Официальный PHP включает FrankenPHP
в затронутые web runtimes; ветка 8.3 исправлена начиная с **8.3.36**.
Источники: [PHP advisory](https://www.php.net/archive/2026.php#2026-10-21-5),
[OOM corruption](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w),
[stale global state](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp).

- Оба PHP Dockerfile переведены на `dunglas/frankenphp:php8.3.36`,
  digest `sha256:29d69af811fa99d158d0ad7f2b4ef3b8d8f591955656975f16ae56cb4f125119`.
  Фактический runtime: **FrankenPHP 1.13.4 / PHP 8.3.36 / Caddy 2.11.7**.
- Общий свежий тег `1.12-php8.3` оказался PHP 8.3.35: обновления одного общего тега было бы недостаточно.
- Удалена старая пересборка FrankenPHP 1.12.7. Новый официальный бинарник сохраняет Mercure/Vulcain,
  а его Go dependencies не ниже прежних overrides: kin-openapi 0.152.0, jose/v4 4.1.6,
  grpc 1.84.0, x/crypto 0.58.0, x/net 0.61.0; Go 1.27.3.
- Build guard проверяет PHP 8.3.36+ и ZTS отдельно через native `php` и
  `frankenphp php-cli`. Старый образ отвергнут обоими guards, новый принят.
- Пересобраны Core dev/prod, Commercial app/test, cross-plane и provisioner worker.
  **Все 11 рабочих контейнеров Platform пересозданы и healthy**; семь PHP-сервисов — 8.3.36 ZTS.
  Встроенный PHP в трёх HTTP-сервисах также проверен отдельно.
- Рабочие `8080/8082/8090`: `/health/live` и `/health/ready` — HTTP 200.
  PostgreSQL/Redis volumes сохранены, MyBots сохранил ID, образ, время запуска и mounts.
- Frontend оставлен на `http://127.0.0.1:5173` с рабочими Core/Commercial endpoints.
  Браузерный smoke реального каталога `/api/control/catalog/plans` — HTTP 200;
  настройки отрисованы, Inter и локальный SVG sprite загружены, page errors нет.

Результат исправления PHP2Shell основан на официальной исправленной версии, проверке обоих
интерпретаторов, build guards, production HTTP smoke и regression suites. Эксплуатационный RCE/OOM
PoC на рабочих серверах не запускался. Это не аудит всей памяти PHP и не доказательство отсутствия
других уязвимостей.

### PostgreSQL security gate

26 исправляемых HIGH/CRITICAL в исходном PostgreSQL-образе относились к Go 1.24.6 внутри
`/usr/local/bin/gosu`. Добавлен [infra/postgres/Dockerfile](../infra/postgres/Dockerfile):
тот же PostgreSQL **18.6-1.pgdg13+2**, тот же gosu **1.19**, пересобранный Go **1.27.3**.
Исходный commit и SHA256 архива закреплены. PostgreSQL binary, PGDATA, entrypoint, UID/GID,
том и формат данных сохранены. В финальном образе нет Go toolchain.

Все шесть закреплённых PostgreSQL consumers в Compose используют `platform-postgres:local`
с build policy. CI строит и сканирует именно этот образ. Проверены named/numeric UID/GID,
отказ неизвестному пользователю, передача exit code дочернего процесса, инициализация БД
и сохранение строки после restart. Независимые read-only reviews обоих исправлений не нашли
конкретного обхода или регрессии.

Служебные backup/provisioner sibling images с `FROM postgres:18` и provisioner provider,
ожидающий такое имя, не переписаны автоматически. Их обновление требует отдельной проверки
совместимости существующих dedicated deployments.

### Причины падения CI #17

[Исходный неуспешный запуск](https://github.com/YCleffo/Platform/actions/runs/41372709776)
проверен по job logs, а не по цвету схемы.

| Job / причина | Исправление |
| --- | --- |
| Core Psalm: mixed metadata в ListAuditEventsAction | Значение выделено в переменную до проверки типа, без изменения JSON semantics |
| Commercial PHPStan: 3 ошибки audit reader/controller | Удалено невозможное условие cursor; строковые query filters читаются через getString |
| Database/docs: Coverage inventory drift | Перегенерирован существующий инвентарь references |
| Frontend: generated tokens отличаются от JSON | danger-800 добавлен в canonical JSON, CSS сгенерирован штатно |
| Скрытые следующие style failures | Убраны hardcoded timings, восстановлен radius token, используются общие motion/theme variables |
| Browser: mobile drill panel ниже viewport | После смены уровней измеряется новый DOM, ResizeObserver подключается к новому panel; добавлен resize regression |
| PostgreSQL: 26 fixable Go findings | Пересобран gosu, исправляемых HIGH/CRITICAL стало 0 |

Job names сокращены; matrix показывает PostgreSQL/Redis/Mailpit, без digest в заголовке.
Digest остаются в источниках образов. Remote CI нового коммита должен проверяться
[в Actions](https://github.com/YCleffo/Platform/actions/workflows/ci.yml) по точному SHA;
старый запуск #17 не является результатом нового коммита.

### Интерфейс и чистка

- В RBAC EN labels корректны; появление loader не меняет координаты/размеры пяти action buttons.
- JSON ответ открывается общим fullscreen-компонентом: проверен viewport 1440×1000.
- Исправлен overflow заполненных Persons/RBAC на мобильном: absolute visually-hidden label
  dropdown теперь ограничена собственным positioned container. После исправления 33 fixture
  состояния админки на 1440/393/320 px — без overflow и page errors.
- JSON textarea получила accessible name; axe для Create dialog — без A/AA violations.
- Добавлен `verify-admin.mjs` в browser CI: fixtures, переводы, геометрия кнопок,
  fullscreen JSON и доступность. Это не реальная серверная авторизация.
- Удалены одноразовые `_admin-ux.mjs`, `_sidebar-final.mjs`; в первом находились
  literal credentials. Они не выводились в отчёт. **Удаление файла не отзывает пароль
  и не очищает Git history: владельцу нужно ротировать прежний credential.**
- Старый аудит заменён этим файлом; исходные иконки/шрифты сохранены.
- Одноразовый стенд удалён: 6 контейнеров, 7 томов с cache, 2 сети; тестовые образы/теги,
  временные ключи, env-файлы и DB dumps удалены. Сгенерированные локальные снимки удалены
  пофайлово; четыре выбранных evidence сохранены в документации. Рабочие 11 Platform и 2 MyBots сохранены.

## 3. БД: что действительно проверено

| Проверка | Core / Runtime | Commercial |
| --- | --- | --- |
| Прикладные таблицы | 50 core + 3 platform_runtime | 61 commercial |
| Применённые миграции | 33 | 42 |
| Live catalog против сохранённой схемы | PASS | PASS |
| Invalid/unready indexes | 0 | 0 |
| Unvalidated constraints | 0 | 0 |
| pg_dump и восстановление в отдельную БД | PASS, 53 таблицы | PASS, 61 таблица |
| Row counts после восстановления | Совпали по всем таблицам | Совпали по всем таблицам |
| Row counts до/после замены рабочих контейнеров | Без изменений | Без изменений |
| Фактическая runtime роль | platform_app | platform_control |
| SUPERUSER / CREATEDB / CREATEROLE / BYPASSRLS | Все true | Все true |

**Последняя строка — открытый production blocker.** Изолированный role gate прошёл,
но рабочие HTTP/workers пока используют привилегированные роли. Не выдавай отдельный
provisioning script за выполненное переключение runtime.

DBML: Core 50 таблиц / 86 физических FK, Runtime 3 / 1, Commercial 61 / 97 — PASS.
Schema reference, coverage inventory, Python typecheck и ссылки документации проверяются штатными
генераторами. Каталог не доказывает полную корректность финансовых данных, межбазовых логических
ссылок, external provider reconciliation или production disaster recovery.

В реальном Symfony router **78 HTTP method/path operations**; множество совпало с OpenAPI.
Core routes содержат **139 literal operations**: 51 auth, 82 core, 6 остальных.
Это инвентарь API, не процент frontend coverage.

## 4. Матрица frontend coverage

Router содержит **34 именованных маршрута**: 22 Commercial/shared/settings/fallback и
12 Core admin с отдельным входом. Обозначения:

- **E2E** — реальный одноразовый Commercial backend + браузер.
- **READ** — проверено чтение страницы в E2E; mutations этим не подтверждены.
- **UI fixture** — браузер с синтетическими HTTP ответами.
- **CODE** — действие есть в исходниках, полный пользовательский lifecycle не доказан.
- **Нет UI** — backend/таблицы есть, соответствующего рабочего экрана нет.

### Commercial и общие страницы

Для account routes API prefix: `/api/control/accounts/:accountId`.

| Route / область | БД / API | Текущее покрытие и граница |
| --- | --- | --- |
| /register, /verify-email, /resend-verification | control_users, verification, outbox; auth APIs | E2E register/verify/resend после reload, ротация token |
| /login, /mfa | sessions, challenges, TOTP/recovery | E2E вход и invitation/MFA lifecycle прошёл |
| /forgot-password, /reset-password | password resets | E2E письмо/new tab/смена/single-use/invalid/missing token; один первый сбой, повтор прошёл — см. ниже |
| / | plans, prices, features, plan_features; catalog | E2E чтение/выбор; оплата не подтверждается кликом по тарифу |
| /cabinet | commercial_accounts, memberships; accounts GET/POST | E2E создание, переключение, чужой/неизвестный account, повторный вход |
| /accounts/:id | detail, permissions | E2E overview и навигация |
| /accounts/:id/members | memberships | E2E связанных действий в invitation lifecycle; дополнительные роли/negative cases — по отдельной матрице |
| /accounts/:id/invitations, /invitation | invitations, outbox | Invitation/MFA E2E PASS; каждый CODE branch не считается автоматически проверенным |
| /accounts/:id/billing | billing_profiles, subscriptions/items/changes, invoices/lines, payments/methods, receipts, refunds | READ; формы и методы mutations — CODE, покупка/возврат/восстановление после reload не пройдены целиком |
| /accounts/:id/usage | entitlements, counters/periods | READ реальных entitlements/usage; изменения usage принадлежат backend/M2M |
| /accounts/:id/analytics | агрегаты membership/billing/audit/usage | READ, график отрисован; периоды/формулы/суммы/гонки требуют тестов |
| /accounts/:id/audit | commercial.audit_events, cursor/filter API | READ; полная матрица фильтров/пагинации/out-of-order не доказана |
| /settings/security | sessions, password, MFA/recovery APIs | E2E чтение; отдельные enrollment/step-up сценарии есть в invitation suite; полный набор новых mutations — CODE |
| /settings/privacy | personal-data export | E2E скачивание JSON; не операторский privacy workflow |
| /settings | browser preferences | Browser PASS: theme, locale, density, font, padding, radius, shadows, motion, Undo, persistence, multi-tab, reduced motion, blocked storage |
| /settings/components | shared controls/menu/dialog | Browser PASS keyboard/touch/mouse, checkbox/switch/range, search/cascade/Back/context menu/dialog |
| fallback 404, публичные auth states | router | Public responsive и guards проверены на 320–1440 px |

### Core admin

В этом проходе 11 страниц после входа проверены через **UI fixtures**, не через действующий
пароль оператора. Core backend API отдельно покрыт его integration suite.

| Route | Таблицы / API | Текущее состояние |
| --- | --- | --- |
| /admin/login | /api/auth/login, me, MFA, refresh, logout | CODE auth adapter, UI fixture login; реальный Core browser auth lifecycle не пройден |
| /admin | Core list APIs | Счётчики; distribution accounts считает первую страницу до 100 |
| /admin/organizations | organizations, memberships | JSON create/member actions; полного UI mutation E2E нет |
| /admin/persons | persons, person_contacts | JSON create/update/archive/contacts; UI fixture с данными |
| /admin/accounts | accounts, roles, service_credentials | Lifecycle/invite/credentials/roles — CODE; lookup persons ограничен первой сотней |
| /admin/rbac | roles, permissions, role_permissions, account_roles | Generic actions; EN labels/loading geometry проверены |
| /admin/modules | modules, module_settings | Enable/disable/settings — CODE |
| /admin/settings | system_settings, feature_flags | GET/PUT/DELETE generic actions — CODE |
| /admin/privacy | requests, holds, erasure, incidents, representations, consents | Частичный workflow с JSON/UUID; не весь Core privacy API |
| /admin/audit | core.audit_events; superadmin API | Список; фильтры backend action/success/actor не выведены полноценно |
| /admin/security | Core password change | Форма есть; Core sessions/MFA/passkeys/providers management отсутствует |
| /admin/finance | Commercial compensation API имеет отдельную авторизацию | Намеренно blocked: не готовый финансовый кабинет |

Таблицы с JSON editor не считать полноценным UX. API wrappers и открывающиеся диалоги не
заменяют проверку разрешённых/запрещённых действий, readonly/system/protected records и 409/422.

### Что frontend пока не покрывает

| Область backend | Пробел |
| --- | --- |
| Core account settings / notifications / privacy preferences | Нет отдельного Core self-service UI; appearance сохраняет только браузерные настройки |
| Core passkeys, external identities/providers | Нет управления lifecycle |
| Core полный privacy workflow | Сроки, correction/evidence, blocks/release, termination и часть representations не оформлены полностью |
| Commercial financial operator, 4 compensation operations | Нет отдельной операторской сессии и рабочего UI |
| Commercial privacy operator, 5 operations | Customer export не заменяет intake/resolve/holds/incidents/erasure |
| Deployments/domains/releases/maintenance/licenses | Пользовательские/операторские lifecycle screens не найдены; сначала зафиксировать release scope |
| Outbox/inbox/idempotency/provider attempts/signing keys | Служебная инфраструктура; не строить прямой CRUD секретов ради покрытия таблиц |
| Education «Сегодня» из design/мобайл.svg | API и страницы не реализованы; Commercial Analytics не является этим экраном |

## 5. Дизайн, локальные assets и доступность

Canonical source — `apps/frontend/design/design-tokens.json`; `tokens.css` генерируется.
Проверка generated tokens и style gate после исправлений — PASS. SVG 393×852 относится к
будущему Education экрану, поэтому pixel-perfect соответствие всего Commercial/Admin этому
макету не заявляется.

Шрифты локальны: 116 TTF исходников и 3 используемых WOFF2 (Inter variable, Space Mono regular/bold).
Runtime CSS и preload ссылаются на локальные файлы. Иконки локальны: исходная Solar коллекция
сохранена, build sprite содержит 28 выбранных symbols, `AppIcon` использует локальный
`<use href="...sprite.svg#name">`. HTTP-загрузка Google Fonts/CDN icons для этого не нужна.

**Продолжай текущую схему:** source SVG отдельно + генерируемый sprite только используемых
иконок. Для переключения набора/стиля добавь typed mapping semantic name → symbol/source;
не удаляй коллекцию, не собирай все тысячи иконок в runtime bundle и не поддерживай
sprite вручную параллельно исходникам.

Public axe: 30 состояний, 0 автоматических A/AA violations. Admin Create dialog после
accessible label — 0. Это не ручная WCAG сертификация. Осмотрены desktop settings и
320 px / 140% текста; скриншоты — в текущих evidence. Native browser autofill preview
и системные password manager menus отдельно не верифицированы.

Снимки: [настройки desktop](evidence/current/settings-desktop.png),
[настройки mobile](evidence/current/settings-mobile.png),
[RBAC с HTTP fixtures](evidence/current/admin-rbac.png),
[fullscreen JSON с HTTP fixtures](evidence/current/admin-response.png).

## 6. Реальные результаты локальной проверки

| Проверка | Результат |
| --- | --- |
| Core full deterministic seed 2120741368 | **516 tests / 14094 assertions PASS** |
| Core Psalm | PASS, [] |
| Commercial full suite | **449 tests / 4918 assertions PASS** |
| Commercial PHPStan | PASS в cross-plane image с требуемым backend autoload |
| Cross-plane required group | **8 tests / 331 assertions PASS** |
| Composer validate / platform / audit | PASS, известные Composer advisories не найдены |
| Миграции пустых isolated DB | PASS |
| Последняя Commercial migration: upgrade → rollback → upgrade; production reset guard | PASS; после цикла полный Commercial suite также PASS |
| Runtime role gates, обе БД | PASS в изолированном стенде |
| Production images без checkout mounts | Non-root HTTP + DB readiness PASS |
| Реальный scheduler/outbox readiness | PASS: отсутствие workers отвергается, heartbeat реальных workers принимается |
| Stateless Core restart + durable row | PASS |
| Vitest | **163 tests / 36 files PASS** |
| Typecheck, ESLint, production build | PASS |
| Tokens, styles, local SVG | PASS |
| Appearance/menu/UX/controls/public axe/responsive | PASS; production preview |
| Новый admin UI regression | PASS, 33 fixture состояния |
| Commercial workspace | PASS |
| Invitation/MFA | PASS |
| Recovery | Первый запуск FAIL на сообщении reused token; независимый reset и rerun PASS, причина первого сбоя не установлена |
| Load smoke, concurrency 1 / 5 | 166 / 699 requests, 0 errors; p95 36 / 51 ms; worst-route p95 36 / 54 ms |
| Load seed guard в чужой БД | Отказ до разрешённого seed в test DB — PASS |
| DBML / schema / generated reference / coverage / Pyright | PASS |
| actionlint / shell parsing / PowerShell parsing / ZIP regression | PASS |

Первый Core full запуск имел 2 ошибки из-за отсутствующего contracts mount в проверочном
стенде. Mount восстановлен; полный набор повторён успешно. Это не замаскировано как баг приложения.
PHPStan test image без backend autoload также не является полноценным CI окружением; окончательная
проверка выполнена там, где присутствует тот же cross-plane autoload, что в CI checkout.

Первый recovery FAIL не считать устранённым одной повторной попыткой. В E2E добавлена
диагностика видимых ошибок и screenshots при падении. Если повторится, сохрани статус/code
ответа reset-confirm и trace, установи причину до изменения ожиданий. Не добавляй автоматический
retry mutations и не отключай rate limits.

Load — короткий локальный closed workload, не production capacity/stress/soak verdict.
Full stress 100 concurrent users и длительный soak не выполнялись. npm audit выявляет
19 MODERATE в дереве инструментов разработки; high threshold проходит. Не применять
`npm audit fix --force` без проверки совместимости.

### Остаточный security inventory образов

Trivy 0.69.3, актуально загруженная vulnerability DB этого прохода, severity HIGH/CRITICAL.
Цифры — package/CVE findings, не число уникальных CVE и не доказанные RCE paths.

| Образ | HIGH+CRITICAL | Включая CRITICAL | Fixable |
| --- | --- | --- | --- |
| Новый Core prod | 60 | 1 | 0 |
| Новый Commercial app | 60 | 1 | 0 |
| Новый platform-postgres:local | 63 | 1 | 0 |
| Закреплённый Redis | 0 | 0 | 0 |
| Закреплённый Mailpit | 0 | 0 | 0 |

В приложениях остаётся, в частности, libxml2 CRITICAL из distro inventory. Отсутствие
FixedVersion не означает отсутствие риска. Production release требует исправления либо
явного решения ответственного о принятии конкретного остаточного риска; такого решения нет.
Чистый Composer audit и fixable gate не заменяют общий inventory.

## 7. Что делать дальше — по приоритету

### P1. Изоляция Core admin state между пользователями

В `modules/admin/adminCache.ts` cache и in-flight keyed только path.
`clearAdminCache` не вызывается на logout/unauthorized; ключ не включает session epoch.
`AdminResourceView.load` сначала показывает cache. В коде нет полноценного session guard
до последующей записи в view/cache. Это открытый дефект клиентской изоляции; серверный
authorization bypass этим не доказан.

Сделай session-scoped cache и epoch/cancellation guard. Проверь A → logout → B без прав,
403 в ожидании, ответ A после clear, refresh/logout/expiry, decoration lookups и dashboard.
Старые строки не должны отображаться или возвращаться в cache ни на один кадр.
Добавь regression с задержанными ответами и реальный negative authorization E2E.

### P1. Production DB roles и секреты

Переключи HTTP/workers на ограниченные runtime roles отдельно от migrator/admin credentials.
Проверь CRUD, последовательности, append-only audit/financial history, jobs/outbox,
запрет DDL/role management и SQLSTATE 42501. Сделай rollback plan до переключения.
Не сбрасывай существующие volumes.

Ротируй credential из удалённого ручного smoke script; проверь другие tracked test/demo
credentials без вывода значений в логи. Удаление из текущего tree не отзывает уже известный пароль.

### P1 до приёмки Billing. Доказать полный пользовательский lifecycle

Сейчас `checkoutFlow`, `invoiceFlow`, последние invoice/payment, planChange и document refs
хранятся в памяти страницы; load в основном восстанавливает entitlement/current subscription.
Нужны восстановление незавершённой операции после reload, история/поиск документов и
понятное возвращение из provider checkout.

Проверь с backend: профиль → subscription → invoice → payment → checkout → webhook →
entitlement; pending/failed/uncertain, двойной click, идемпотентность, reload/вторая вкладка,
отмена auto-renew, immediate/period-end cancellation, downgrade/upgrade, refund/receipt.
Не симулируй успешную оплату в приложении; provider sandbox/fixture должен быть обозначен.

### P2. Auth races, role UX, pagination и данные

- `AdminLoginView.finish` вызывается после await без проверки, остался ли пользователь на
  текущем login view. Проверь late login/MFA response после ухода на другую страницу.
- В `adminAuthStore.establish` дополнительный person lookup не проверяет session epoch до
  обновления displayName. Проверь ответ предыдущей сессии.
- Analytics/Audit guard по accountId не защищает два запроса одного account с разными days/filters.
  Введи request generation guard и тесты обратного порядка ответов.
- Убери произвольный JSON/UUID из основных пользовательских операций, добавь typed forms,
  ссылки/поиск связанных объектов, field errors и ясные destructive confirmations.
- Прячь/disable actions по permissions и protected/system state; серверные проверки обязательны.
- Dashboard distribution и persons lookup по первой сотне не выдавай за полный набор.
- Для новых Billing/Usage/Analytics/Audit и Core admin API/auth нужны отдельные meaningful
  tests; текущие 163 tests не являются line/branch coverage этих модулей.
- Зафиксируй release scope для отсутствующих operator/Education/deployment screens.

## 8. Документация и критерии закрытия

Сохраняй README запуска, архитектурные границы, API/event contracts, миграции, schema catalogs,
privacy/security/backup/restore/provisioning runbooks. Они нужны разработчику и эксплуатации;
generated coverage участвует в CI, его нельзя просто удалить как «лишний документ».

Устаревшие датированные аудиты и разовые smoke-скрипты не возвращать. Инвентарь/статусы
этого прохода хранить здесь. `docs/frontend/README.md` и `apps/frontend/README.md` следует
свести к одному владельцу подробного frontend описания и короткой ссылке из второго:
сейчас часть module/test descriptions отстаёт от billing/admin и новых checks.

Для каждой следующей задачи обнови здесь: поведение до/после, путь к реализации,
точную команду, результат и оставшуюся границу. Завершение означает:

1. Все изменённые contracts/types/source согласованы; generators и CI gates проходят без suppressions.
2. Изменённые пользовательские сценарии проходят с реальным isolated backend и negative cases.
3. Нет layout shifts/overflow в изменённых состояниях на 320/393/768/1440 px, EN/RU,
   light/dark, reduced motion и увеличенном шрифте; есть screenshots и ручной осмотр.
4. Поведение доказано на реально пересобранных HTTP/worker images, не только host unit tests.
5. Тестовые контейнеры/тома/сети/секреты удалены, рабочие данные и MyBots сохранены.
6. Проверен именно новый GitHub run для отправленного SHA; не писать «CI зелёный» по старому запуску.
