# Проверка проекта и CI — 2026-10-31

**Production: `CORE_NOT_READY`.** Рабочее дерево на базе
`af8b66f28920efa20767b09533695c950cf940cf` проверено локально на Windows и в Linux Docker.
Коммитов и push нет. Ни один локальный результат не объявляется результатом GitHub runner.

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
| Core full на обновлённом runtime, seed 2120741368 | PASS: 514 tests / 14 081 assertions; повторный прогон 15:11 на локальном Docker Desktop |
| Commercial final full после исправления Session API | PASS: 447 tests / 4 909 assertions |
| Cross-plane bootstrap, HTTP runtime, downgrade/renewal/restart | PASS: 8 tests / 331 assertions |
| Browser E2E после финальной правки API | PASS: account workspace и invitation/membership/MFA; checks подсветки навигации и реальной активной сессии |
| Frontend unit/component | PASS: 129 tests / 31 files; typecheck, lint, build, local tokens/icons/styles |
| Настройки и меню на production preview | PASS: theme timing, rapid changes, reduced motion, storage/reload/multi-tab, custom select, cascade/Back, keyboard, dialog scope, context menus |
| Responsive browser smoke | PASS: 13 маршрутов × 9 ширин 320–1440 px, RU, темы, guards и отсутствие горизонтального overflow |
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

На текущем повторном прогоне один invitation E2E остановился после MFA enrollment:
Vite proxy зарегистрировал `ECONNRESET` на `GET /api/control/accounts`.
Перезапуска/OOM контейнера и ошибок приложения не найдено; причина сетевого сброса
не установлена. После чистого reset полный invitation/MFA сценарий прошёл.
Это не доказательство устранения transient-сбоя: он остаётся отмеченным ограничением.
Автоматический retry тестов не добавлен; при сбое сохраняются screenshot и HTTP 5xx diagnostics.
Очистка cache выполняется только после browser checks.
Transient TLS bad-record-MAC при загрузке зависимостей устранён успешным повтором
с cache; TLS verification не отключалась.

Production smoke использует существующую **синтетическую CI privacy policy**,
а не реальные юридические сведения. Worker checks выполняются отдельно; для HTTP
smoke workers отключены. Эти проверки подтверждают запуск и схему, а не готовность production.

## Что реально проверяет CI

[Workflow](../../.github/workflows/ci.yml) содержит 8 jobs: configuration,
Windows tools, Core, Commercial, cross-plane, documentation, frontend и browser E2E.
Тестовые suites не отключены, ошибки tests не игнорируются. npm audit блокирует
HIGH/CRITICAL. Production image gate блокирует HIGH/CRITICAL с выпущенным исправлением.

Полный Trivy inventory включает находки без исправления: таблица All/Fix available/
No fix available публикуется в GitHub job summary, CRITICAL без fix выдаёт warning,
JSON inventory сохраняется как artifact на 7 дней. Проходящий gate не означает «всех CVE нет».

Последний проверенный [GitHub run 41041137409](https://github.com/YCleffo/Platform/actions/runs/41041137409)
успешен для старого `07cb9bd4…`, но содержит только 5 jobs. Он не подтверждает
текущий frontend/browser, Core production smoke или новые security gates.
Новый hosted run возможен после будущего commit; в этой работе commit/push запрещены.

## Безопасность образов и оставшиеся ограничения

До исправлений Trivy нашёл в Commercial 128 исправляемых HIGH, в Core —
14 исправляемых CRITICAL и 93 HIGH. После обновлений в обоих production images
исправляемых HIGH/CRITICAL **0**. Полный scan каждого образа всё ещё содержит
**1 CRITICAL и 58 HIGH без доступного исправления**; исключающие CVE ignore-файлы не добавлены.

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

До production также остаются: least-privilege DB roles вместо локальных bootstrap
credentials ([инструкция](DATABASE_ROLES.md)), реальный provider/YooKassa smoke,
утверждённая privacy policy, HA, restore/PITR drill, TLS/DNS и нагрузочный SLO.
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

## Рабочий runtime и очистка

В предыдущей части аудита обновлены Core backend/scheduler/email-worker и Commercial
HTTP/scheduler/outbox. На текущем повторном прогоне все 12 исходных контейнеров
(включая MyBots) сохранили IDs, image IDs, mounts и время запуска — сверено со снимком
до стенда. Все 4 рабочих тома сохранены. Оба рабочих `/health/ready` после очистки
отвечают HTTP 200; invalid indexes и unvalidated constraints — 0 в обеих БД.

Удалены 6 контейнеров стенда `platform-ui-settings-20261031`, 3 test volumes,
2 сети и 7 временных image references (test/verify/tool images).
Остались 6 рабочих image tags. Build cache освобождён на 7,091 ГБ;
Buildx показывает 0 Б и пустую history.
Временные frontend processes остановлены; screenshots, test outputs, host test caches,
служебные скрипты, secrets/certificates и logs удалены. Исходники тестов и полные
библиотеки иконок/шрифтов сохранены.
