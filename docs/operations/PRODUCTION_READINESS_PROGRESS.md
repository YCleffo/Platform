# Проверка проекта и CI — 2026-11-01

**Production: `NOT_READY`.** Живой статус обновлён после
[независимого аудита](DEEP_AUDIT_2026-11-01.md) ревизии
`fa313953ca07cf1df9f904f5a61e4e5a10f068e7`. Разделы ниже, датированные 2026-10-31,
сохранены как история проверок; они не заменяют текущий статус исправлений.
Локальный прогон не является результатом GitHub runner. Новых коммитов и push нет.

## Статус исправлений по аудиту 2026-11-01

| Finding | Статус | Что изменено / что осталось |
| --- | --- | --- |
| F01 password recovery | Готово в коде | `/forgot-password`, `/reset-password?token=…`, generic request, invalid/used/missing token, очистка URL |
| F02 resend verification | Готово в коде | `/resend-verification`, ссылки из login и verification error, generic acknowledgement, ротация токена |
| F03 native form contract | Готово в коде | `name`/form attrs на реальном control; native reset синхронно возвращает Vue state; browser regression |
| F04 204 contract | Готово в коде | 204 с обязательной схемой отклоняется; void endpoints сохранены |
| F05 404 | Готово в коде | Catch-all route и локализованная страница возврата |
| F06 reflow labels | Готово в коде | Колонки масштабируются с fontScale; слова не рвутся; проверка 320/393 RU |
| F07 working runtime | Готово локально | Control Plane image `sha256:2ca05ee…` пересобран из source, control-контейнеры перезапущены; health live/ready = ok, optimization присутствует в `/app/src` |
| F08 DB roles | Частично | CI denial gate `db-role-gate.sh` для обеих БД добавлен; рабочий/production runtime ещё не переключён на отдельные credentials |
| F09 late login | Готово в коде | AbortSignal + lifecycle guard для login и MFA; late result не меняет route |
| F10 MFA QR a11y | Готово в коде | Accessible name на SVG; authenticated axe matrix в browser E2E |
| F11 infra images | Готово в конфигурации | Postgres `18.6-trixie` и Mailpit `v1.33.0` закреплены digest; отдельный CI scan job; рабочие контейнеры перезапущены, схемы/данные сохранены |
| F12 app image residual risk | Задокументировано, blocker | Оценка достижимости и условия решения: [IMAGE_RISK_DECISION_2026-11-01](IMAGE_RISK_DECISION_2026-11-01.md); риск не принят |
| F13 off-step preferences | Готово в коде | Numeric settings нормализуются к step сетке; state/CSS/ARIA совпадают |

Локально после изменений: frontend typecheck/lint/build/tokens/icons/styles — PASS;
151 unit/component tests в 34 files — PASS; public accessibility — 28 состояний,
0 автоматических A/AA violations; appearance/controls/UX/menus/responsive — PASS
(27 browser assertions для controls). Account recovery E2E добавлен в browser CI,
но локально против рабочей БД не запускался.

## Исправленные дефекты

1. В основной навигации «Тарифы» оставались активными на других страницах из-за
   совпадения родительского route. Теперь подсветка использует точное совпадение.
   Аналогично исправлены вкладки Overview/Members/Invitations внутри аккаунта.
2. Sessions API допускает `null` в `last_used_at` и `user_agent`; frontend отвергал
   весь список с такой записью. Исправлены TypeScript types, runtime guard, OpenAPI
   и отображение отсутствующей даты. Сервер сериализует даты сессий в RFC 3339.
   Проверяются свежая сессия, неизвестные значения, реальная страница Security и навигация.
3. У Core production image отсутствовал writable `/app/runtime`. Созданы cache/log
   directories с владельцем www-data; в CI добавлен настоящий запуск обоих production
   HTTP images и проверка схемы без bind mount исходников приложения.
4. Обновлены Debian packages, Go до 1.26.10 и уязвимые Go modules FrankenPHP;
   Core и Commercial используют закреплённую базу PHP 8.3. Compiler headers удаляются
   из production; dev Core сохраняет возможность собрать Xdebug.
5. Cross-plane image получает имя базового test image из Compose project.
   Отдельный `.dockerignore` исключает host vendor/runtime/.env/c3.php; CI проверяет
   отсутствие этих файлов. Backend dependencies устанавливаются внутри builder.
6. Тестовые тома, сети и provisioner network привязаны к `COMPOSE_PROJECT_NAME`.
   Boundary fixture исправлен для 31-го числа: вместо переполнения `-1 month`
   выбирается корректный календарный период, ожидание ограничено 15 секундами.
   Расчёт дополнительно проверен на 1 827 датах, включая високосные годы.
7. Browser CI запускает Vite с `--strictPort`, ожидает heartbeat настоящего outbox
   worker, останавливает старый runtime перед reset и удаляет процесс/TLS files после job.

## Выполненные проверки

| Проверка | Результат |
| --- | --- |
| Core full на обновлённом runtime, seed 2120741368 | PASS: 514 tests / 14 081 assertions; 15:42 на локальном Docker Desktop |
| Commercial final full после оптимизации активности сессии | PASS: 449 tests / 4 916 assertions; отдельный ControlAuthTest — 6 / 27 |
| Cross-plane bootstrap, HTTP runtime, downgrade/renewal/restart | PASS: 8 tests / 331 assertions |
| Browser E2E после финальной правки API | PASS: account workspace и invitation/membership/MFA; checks подсветки навигации и реальной активной сессии |
| Frontend unit/component | PASS: 151 tests / 34 files после recovery/form/step исправлений; typecheck, lint, build, local tokens/icons/styles |
| Доступность | PASS: 28 public/auth/settings/menu/modal/mobile состояний в Light/Dark, 0 автоматических A/AA violations; без исключения правил; authenticated MFA matrix в browser E2E |
| Повторные действия UI | PASS: 30 циклов меню, 10 диалогов; глобальные listeners до/после — 1/1; orphan overlay — 0 |
| Настройки и меню на production preview | PASS: theme timing, rapid changes, reduced motion, storage/reload/multi-tab, custom select, cascade/Back, keyboard, dialog scope, context menus |
| Responsive browser smoke | PASS: 18 маршрутов × 9 ширин 320–1440 px, RU, темы, guards и отсутствие горизонтального overflow |
| Production HTTP smoke | PASS для Core и Commercial: non-root, без checkout mounts, live + schema readiness HTTP 200 |
| Composer / PHP static analysis | PASS: strict validate, platform requirements, audit; Core Psalm и Commercial PHPStan — 0 errors |
| CI configuration | PASS: Actionlint + embedded ShellCheck, bash syntax; PowerShell syntax и ZIP regression |
| Документы / БД | PASS: links, snapshot/reference/coverage, DBML, Pyright |
| Commercial migrations | PASS: empty → 42 migrations, previous/latest/down/up, reset guard и полные повторные suites |
| Работники и restart | PASS: Core restart с сохранённой БД; Commercial readiness с настоящими scheduler/outbox heartbeats |
| npm production dependencies | PASS: 0 vulnerabilities |
| npm с dev dependencies | 19 MODERATE в minimatch/glob dependency chains; HIGH/CRITICAL — 0 |
| Trivy production image gates | PASS в обоих образах: исправляемых HIGH/CRITICAL — 0; полный inventory см. ниже |

Full suites включают отдельные unit/API/etc suites; повторные прогоны нельзя
суммировать как уникальные тесты. Все мутации тестов выполнялись только в отдельном
Compose project, `platform_test` / `platform_commercial_test`, с isolation markers.
Рабочие БД тестами не очищались.

В предыдущем прогоне один invitation E2E остановился после MFA enrollment:
Vite proxy зарегистрировал `ECONNRESET` на `GET /api/control/accounts`.
Перезапуска/OOM контейнера и ошибок приложения не найдено; причина сетевого сброса
не установлена. После чистого reset полный invitation/MFA сценарий прошёл.
В текущем прогоне account и invitation/MFA сценарии прошли до и после изменения AuthService.
Это не доказательство устранения старого transient-сбоя: его причина остаётся неустановленной.
Автоматический retry тестов не добавлен; при сбое сохраняются screenshot и HTTP 5xx diagnostics.
Очистка cache выполняется только после browser checks.
Transient TLS bad-record-MAC при загрузке зависимостей устранён успешным повтором
с cache; TLS verification не отключалась.

Production smoke использует существующую **синтетическую CI privacy policy**,
а не реальные юридические сведения. Worker checks выполняются отдельно; для HTTP
smoke workers отключены. Эти проверки подтверждают запуск и схему, а не готовность production.

## Что реально проверяет CI

[Workflow](../../.github/workflows/ci.yml) содержит 9 jobs: configuration,
Windows tools, Core, Commercial, infrastructure images, cross-plane, documentation,
frontend и browser E2E.
Тестовые suites не отключены, ошибки tests не игнорируются. npm audit блокирует
HIGH/CRITICAL. Production image gate блокирует HIGH/CRITICAL с выпущенным исправлением.

Полный Trivy inventory включает находки без исправления: таблица All/Fix available/
No fix available публикуется в GitHub job summary, CRITICAL без fix выдаёт warning,
JSON inventory сохраняется как artifact на 7 дней. Проходящий gate не означает «всех CVE нет».

Исторический [GitHub run 41041137409](https://github.com/YCleffo/Platform/actions/runs/41041137409)
успешен для старого `07cb9bd4…`, но содержит только 5 jobs. Он не подтверждает
текущий frontend/browser, Core production smoke или новые security gates.
Текущий пользовательский commit уже существует; по последнему запросу выполнена локальная
проверка. Новые изменения остаются в working tree и не отправлялись на GitHub.

## Безопасность образов и оставшиеся ограничения

До исправлений Trivy нашёл в Commercial 128 исправляемых HIGH, в Core —
14 исправляемых CRITICAL и 93 HIGH. После обновлений в обоих production images
исправляемых HIGH/CRITICAL **0**. Полный scan каждого образа всё ещё содержит
**1 CRITICAL и 60 HIGH без доступного исправления** по аудиту 1 ноября; число HIGH менялось
при обновлении scanner DB без изменения source. Исключающие CVE ignore-файлы не добавлены.

CRITICAL — `libxml2`, CVE-2026-6653. Debian trixie отмечен vulnerable/no-dsa;
исправление есть в unstable, но отсутствует в текущем stable package.
[Debian tracker](https://security-tracker.debian.org/tracker/CVE-2026-6653).
Перенос на иной runtime или проверенный backport требует отдельной проверки совместимости.
Версия Go выбрана по [официальному security release](https://go.dev/doc/devel/release#go1.26.10).

19 MODERATE относятся к dev tooling. Advisory требует minimatch 11.0.1 / glob 14.0.2;
родительские зависимости пока используют несовместимые ranges. Нужна отдельная
проверенная миграция tooling, а не подавление audit.
[minimatch advisory](https://github.com/advisories/GHSA-g3hv-75wg-m249),
[glob advisory](https://github.com/advisories/GHSA-3g3m-2fjr-6pw4).

PostgreSQL/Mailpit в Compose обновлены до `18.6-trixie` и `v1.33.0` с digest и
перезапущены на рабочих томах: Core — 53 прикладные таблицы/2 схемы, Commercial —
61 прикладная таблица/42 migration versions, все контейнеры healthy. Infrastructure
scan job теперь явно сканирует PostgreSQL, Redis и Mailpit отдельно от app images.
Mailpit — dev/test инфраструктура. Остаточный риск app images зафиксирован как
release blocker: [IMAGE_RISK_DECISION_2026-11-01](IMAGE_RISK_DECISION_2026-11-01.md).

До production также остаются: least-privilege DB roles вместо локальных bootstrap
credentials ([инструкция](DATABASE_ROLES.md); CI denial gate добавлен, рабочий runtime
ещё не переключён), реальный provider/YooKassa smoke, утверждённая privacy policy, HA,
restore/PITR drill, TLS/DNS и нагрузочный SLO.
Прежний локальный 10 000 VU прогон с 82,75% HTTP errors / p95 10,52 s не считается пройденным.

## БД, frontend и документы

Рабочие БД повторно проверены чтением: Core — 33 migrations / 53 прикладные таблицы,
Commercial — 42 / 61; вместе с таблицами истории миграций всего 54 / 62.
Invalid indexes и unvalidated constraints — 0 в обеих БД. Экспорты схем совпадают.
Core Account, Control User и Commercial Account остаются отдельными identities.
Frontend реализует Commercial; Education dashboard из SVG остаётся будущим модулем.
[Архитектура frontend](../frontend/README.md), [инварианты](../architecture/CORE_INVARIANTS.md),
[схемы](../database/README.md), [roadmap](../roadmap/PLATFORM_ROADMAP.md).

Полные **8 280 Solar SVG и 116 TTF сохранены**. В bundle входят только выбранные
28 SVG и локальные Inter/Space Mono WOFF2. Google Fonts/CDN не используются.
`AppIcon` отделяет семантические имена от рендера: будущая карта Linear/Bold не требует
переписывать страницы. Лицензии и attribution включены в public assets.

Удалены дубли PROJECT_TREE, INTERNATIONAL_SECURITY_CORE, database/core/README,
старый PROJECT_AUDIT_2026-10-31 и пустой evidence README. Архитектура и текущие результаты
собраны в профильных docs; apps/frontend/README служит точкой входа с командами.

## Настройки и переиспользуемые компоненты

Добавлен `/settings`: Light/Dark/System, язык, compact/default/spacious, масштаб шрифтов,
отступов и скруглений, padding кнопок, четыре уровня теней, включение и скорость анимаций,
длительность смены темы. Настройки сохраняются локально, синхронизируются между вкладками
и имеют сброс. OS reduced motion имеет приоритет. Все CSS timing/easing централизованы;
View Transitions синхронизирует смену темы, fallback применяет её атомарно.

Добавлены `AppDropdown`, `AppMenu`, `AppContextMenu` и `/settings/components` с примерами.
Есть поиск по всему дереву, single/multiple, clear, disabled, группы/разделители, иконки,
описания, каскадные панели, мобильный Back, keyboard/focus и безопасное открытие в диалоге.
Заменены все четыре native select. Контекстное меню страницы содержит переходы, тему и
плотность; поля, ссылки, выделенный текст и Shift+right click сохраняют меню браузера.

Браузерные проверки нашли и устранили потерю клика при hover-переходе из каскада в Back
на промежуточной ширине, потерю фокуса при поиске внутри вложенного уровня и overflow
шапки при 320 px / шрифте 140%. Ранее ошибочная подсветка «Тарифы» исправлена через
явную принадлежность route к разделу: Settings остаётся активным на дочерних страницах.
API компонентов и диапазоны переменных: [frontend](../frontend/README.md).

В первом UX-проходе добавлены 14 регрессионных тестов: 12 frontend и 2 Commercial.
Настройки получили готовые варианты, отмену до 20 изменений, точные шаги «−/+»,
сброс отдельного параметра и раскрываемый блок дополнительных отступов/скруглений.
Исправлены контраст вторичного текста Light, подчёркивание inline links, доступные имена
языка/Back/reset, region для контекстной области и ARIA references к настоящим menu.
Индикатор текущей вкладки аккаунта удаляется сразу при навигации; прежняя линия больше
не остаётся на время цветового transition. E2E проверяет единственный видимый индикатор.
Снимки тем ожидают завершения View Transition, снимок кабинета после MFA — загрузки списка.

Axe сохраняет incomplete-результаты в JSON: символы «−/+» требуют ручной оценки контраста
и названий, а popup IDREFs — проверки существования цели. Названия и кнопки проверены
клавиатурой/скриншотами; наличие всех aria-controls/labelledby/describedby/owns проверяет скрипт.
Автоматический PASS не равен полной WCAG-сертификации.

Следующее обновление checkbox/switch/range добавило ещё 5 component tests и 27 browser
assertions, включая native form attributes/reset. Итого frontend: 151 tests / 34 files.
Новые элементы используют общие theme/motion variables и reduced motion; повторные
appearance/menu/UX/responsive и accessibility checks прошли. [Снимки и проверки controls](evidence/controls-2026-10-31/README.md).

Проверка font/image/stylesheet requests подтвердила same-origin assets. В локальном
сетевом окружении наблюдается вставка `http://local.adguard.org` в HTTP HTML; в исходниках
и dist/index.html этой ссылки нет. Origin записан отдельно в UX JSON; настройки фильтра
не менялись. Это не зависимость frontend от стороннего font/icon CDN.

## Нагрузка и устранённая задержка

Порог задан до прогона и не ослаблялся: error rate ≤1%, p95 ≤1000 ms **для каждого
этапа и endpoint**. Чередуются catalog, me, accounts и sessions; три опубликованных
synthetic тарифа с prices/features, один пользователь/сессия и непустой account list.
Форма JSON и содержимое проверяются на каждом запросе, retries отсутствуют.

Первый смешанный прогон превысил порог на 50/100. Чистый повтор подтвердил проблему
на 100 (p95 1154 ms), а smoke — превышение на отдельных endpoint при 5.
AuthService записывал last_used_at при каждом запросе одной сессии. Теперь первая
активность записывается сразу, следующие access-запросы обновляют её не чаще раза в минуту,
как существующий M2M path. Проверки expiry/revocation/user status остаются на каждом запросе;
новые тесты проверяют отсутствие лишней версии строки PostgreSQL, обновление устаревшей
активности и немедленный отказ после logout. Refresh сохраняет немедленную запись времени.

| Одновременные запросы | p95 до, чистый прогон | p95 после | Худший endpoint после | RPS после |
| --- | --- | --- | --- | --- |
| 1 | 34 ms | 34 ms | 35 ms | 34 |
| 5 | 44 ms | 43 ms | 45 ms | 158 |
| 20 | 213 ms | 129 ms | 131 ms | 244 |
| 50 | 679 ms | 333 ms | 343 ms | 254 |
| 100 | 1154 ms — FAIL | 561 ms | 586 ms | 253 |

Финальный stress: **14 298 запросов, 0 ошибок**, все этапы/endpoint PASS; 15 s на этап.
CI smoke: **995 запросов, 0 ошибок**, худший p95 endpoint 43 ms; этапы 1/5 по 5 s.
Перед нагрузкой CI очищает только disposable fixtures, сохраняя реальные rate limits.
SQL seed проверен отказом на другой БД. Окружение — локальный Docker Desktop; это closed
workload одного пользователя на небольших данных, а не оценка production capacity/SLO.

[Первый замер](evidence/ux-2026-10-31/load-commercial-stress-initial.json),
[чистый замер до](evidence/ux-2026-10-31/load-commercial-stress-before-optimization.json),
[smoke до](evidence/ux-2026-10-31/load-commercial-smoke-before-optimization.json),
[stress после](evidence/ux-2026-10-31/load-commercial-stress.json),
[CI smoke после](evidence/ux-2026-10-31/load-commercial-smoke.json).

## Проверенные скриншоты

Сохранены выбранные снимки, без QR/секретов MFA и служебных логов:

- Настройки: [desktop Light](evidence/ux-2026-10-31/ux-settings-desktop-light.png), [desktop Dark](evidence/ux-2026-10-31/ux-settings-desktop-dark.png), [mobile RU Dark](evidence/ux-2026-10-31/ux-settings-mobile-ru-dark.png).
- Точные параметры: [шрифты и шаги](evidence/ux-2026-10-31/ux-settings-mobile-ranges.png), [дополнительные настройки](evidence/ux-2026-10-31/ux-settings-mobile-advanced.png), [анимации](evidence/ux-2026-10-31/ux-settings-mobile-motion.png), [320 px / 140%](evidence/ux-2026-10-31/ux-settings-320-large-font.png).
- Меню: [desktop cascade](evidence/ux-2026-10-31/ux-cascade-desktop.png), [mobile Back](evidence/ux-2026-10-31/ux-cascade-mobile-back.png), [landscape](evidence/ux-2026-10-31/ux-cascade-landscape.png).
- Аккаунт: [desktop](evidence/ux-2026-10-31/account-desktop-light.png), [mobile Dark](evidence/ux-2026-10-31/account-mobile-dark.png), [участники](evidence/ux-2026-10-31/members-mobile-light.png).
- [UX results](evidence/ux-2026-10-31/ux-results.json), [axe results и incomplete](evidence/ux-2026-10-31/accessibility.json).

## Рабочий runtime и очистка

В предыдущей части аудита обновлены Core backend/scheduler/email-worker и Commercial
HTTP/scheduler/outbox. На текущем повторном прогоне все 12 исходных контейнеров
(включая MyBots) сохранили IDs, image IDs, mounts и время запуска — сверено со снимком
до стенда. Все 4 рабочих тома сохранены. Оба рабочих `/health/ready` после очистки
отвечают HTTP 200; invalid indexes и unvalidated constraints — 0 в обеих БД.

Удалены ресурсы одноразового проекта `platform-ux-audit-20261031` и 7 временных
image references (test/verify/tool images). После финальной перепроверки снимков
временные PostgreSQL/HTTP/Mailpit также удалены. Остались 6 рабочих image tags.
Build cache освобождён на 7,577 ГБ;
Buildx показывает 0 Б и пустую history.
Временные frontend processes остановлены; screenshots, test outputs, host test caches,
служебные скрипты, secrets/certificates и logs удалены. Проверенные снимки и метрики
сохранены по ссылкам выше. Исходники тестов и полные
библиотеки иконок/шрифтов сохранены.

В текущем проходе Control Plane image пересобран из source и рабочие control-контейнеры
перезапущены без миграции и очистки данных. Новый image
`sha256:2ca05ee845b667fd68023de1a8de9cf7a07e8d482305fd906eb9ca0a041bafe6` совпадает
с image ID запущенного `platform-control-plane-1`; внутри контейнера присутствует
минутное ограничение `last_used_at`. `/health/live` и `/health/ready` — 200, Commercial DB
сохранила 6 control users и 42 migration versions. Полные login/account flows не
повторялись без тестовых credentials; это остаётся smoke-задачей после выкладки.
PostgreSQL/Mailpit/Redis рабочие контейнеры пересозданы на закреплённых digest;
PostgreSQL сохранил именованные тома, проверены схемы и health. Первый pull Redis
по неверному digest дал 8.8.4, который не прочитал существующий AOF; digest исправлен
на текущий `8-alpine` (8.10.x), после чего Redis и зависимые workers восстановлены.
