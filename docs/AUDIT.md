# Аудит Platform — 1 ноября 2026

**Вердикт: локальная разработка работоспособна; полный CI и production пока не готовы.**
Проверена ревизия `22362bb71f34a3313ace79b52b9e8d2b43c7cfc5`. Проверки выполнялись
локально на одноразовых PostgreSQL/Redis/Mailpit. Рабочие БД не очищались.
Ниже разделены подтверждённые дефекты, пройденные проверки и непроверенные условия выпуска.
В этом проходе изменена документация; перечисленные дефекты приложения и CI оставлены
конкретными задачами для следующего исправления. Коммитов, push и запуска GitHub Actions нет.

## Что исправить

### A01 · P1 · Browser CI упирается в лимит регистраций

В [workflow](../.github/workflows/ci.yml#L657) recovery запускается сразу после invitation,
без сброса fixtures. Invitation создаёт четыре пользователя, recovery ещё двух;
[AuthService](../apps/control-plane/src/Identity/Application/AuthService.php#L33)
разрешает пять регистраций с одного IP за час. Второй пользователь recovery получает
**HTTP 429**. Ошибка повторилась дважды. Recovery на чистой БД проходит.

**Действие:** перед recovery остановить HTTP runtime, сбросить только одноразовые fixtures,
поднять runtime и заново запустить outbox worker — по уже существующему шаблону workflow.
Не повышать лимит и не отключать rate limiting ради теста.
**Приёмка:** invitation → reset → recovery → reset → load проходят одной последовательностью.

### A02 · P2 · Отменённый reset всё равно очищает форму

Обработчики `onReset` в [AppInput](../apps/frontend/src/shared/ui/fields/AppInput.vue#L51),
[AppSwitch](../apps/frontend/src/shared/ui/fields/AppSwitch.vue#L12) и
[AppCheckbox](../apps/frontend/src/shared/ui/fields/AppCheckbox.vue#L20) меняют модель,
не учитывая отмену события. В реальном Chromium форма с `@reset.prevent`, текстом
`Initial` и включённым switch после Reset получила пустой текст и `false`.
Браузерный сброс был отменён, Vue state — уже изменён.

**Действие:** синхронизировать модель только после подтверждения неотменённого reset;
учесть порядок слушателей на форме и её предках. Обычный reset должен остаться рабочим.
**Приёмка:** browser/component tests для обычного, отменённого и программного reset;
значения модели, DOM и FormData совпадают. Текущий тест обычного reset этого дефекта не ловит.

### A03 · P1 для release gate · Ошибка SQL выдаётся за запрет доступа

[db-role-gate.sh](../scripts/ci/db-role-gate.sh#L80) считает любой ненулевой exit code
отказом по правам. На одноразовой Core БД я временно выдал runtime-роли `TRUNCATE`
на `core.accounts`: `has_table_privilege` вернул `true`, но команда завершилась
SQLSTATE **0A000** из-за внешних ключей. Gate засчитал бы такой результат как запрет.
Выданное диагностическое право сразу отозвано.

**Действие:** проверять отсутствие привилегий явно и требовать SQLSTATE `42501` для
negative checks. Выполнять пробные операции в откатываемой транзакции/специальных fixtures.
**Приёмка:** намеренная выдача запрещённого права делает gate красным; FK/syntax/connection
errors также завершают проверку ошибкой, а не сообщением «Denied as expected».

### A04 · P1 до production · Приложения используют bootstrap superuser

Чтение фактической конфигурации контейнеров и `pg_roles` подтвердило: Core использует
`platform_app`, Commercial — `platform_control`; у обеих ролей включены SUPERUSER,
CREATEDB, CREATEROLE, BYPASSRLS. Добавление CI role gate не переключает HTTP и workers
на новые credentials. Для локальной dev-установки это известная модель, для production
условие выпуска не выполнено.

**Действие:** выполнить [provisioning ролей](operations/DATABASE_ROLES.md), отдельно
настроить migration/runtime credentials и проверить все HTTP/worker flows от runtime.
Рабочие пароли и роли в ходе аудита не менялись.

### A05 · P2 · Штатный локальный Vite-прокси нестабилен

В четырёх invitation/MFA-прогонах запросы sessions или MFA status через штатный Vite
получали 500; лог Vite содержал **`read ECONNRESET`**. Повтор с Linux snapshot исходников
также падал. Прямой запрос к Commercial возвращал 200; диагностический listener сервера
не зафиксировал прикладного исключения на этих сбоях.

Одноразовый прокси с `http.Agent({ keepAlive: false })` позволил полностью пройти тот же
invitation/MFA flow. Это рабочая диагностическая мера, **не доказательство первопричины**.
В [штатный vite.config.ts](../apps/frontend/vite.config.ts) это изменение не внесено.

**Действие:** проверить жизненный цикл proxy connections на этом Windows/Docker окружении
и штатный сценарий на Linux runner; выбрать явную настройку agent или исправление транспорта.
Не скрывать 500 автоматическими retries. **Приёмка:** полный flow проходит с обычной
конфигурацией проекта, без временного прокси. Переносимость сбоя на GitHub не подтверждена.

### A06 · P2 · Scheduler «healthy», но entitlement sync постоянно падает

Рабочий `backend-scheduler` имеет `CORE_ENTITLEMENT_SYNC_ENABLED=true`, при этом
installation ID, Control Plane URL и сам deployment credential не заданы. Последние
запуски `runtime_entitlement_sync` заканчиваются `failed / job_failed`. Heartbeat
контейнера после нагрузки вернулся в `healthy`; это не означает успеха каждого задания.
[SchedulerLoop](../apps/backend/src/PlatformRuntime/Scheduler/SchedulerLoop.php) заменяет
исходное исключение общим кодом, поэтому лога недостаточно для точной диагностики.

**Действие:** для standalone dev явно выключать sync либо выдавать понятный `skipped`
при отсутствии provisioning; для связанной установки завершить настройку credentials.
Добавить безопасный диагностический код/корреляцию без вывода секретов и отдельный
контроль ошибок задания. **Приёмка:** нет постоянных `job_failed` на пустой локальной
установке; настоящий отказ настроенного sync остаётся видимым и не скрывается heartbeat.

## Что реально проверено

| Проверка текущей ревизии | Результат |
| --- | --- |
| Frontend unit/component | **151 тест, 34 файла — PASS** |
| Typecheck, ESLint, build, styles, tokens, icons | **PASS** |
| Core full suite, seed `2120741368` | **514 тестов, 14 081 assertion — PASS** |
| Core Psalm `--no-cache` | **PASS**, ошибок нет |
| Commercial full PHPUnit | **449 тестов, 4916 assertions — PASS** |
| Cross-plane, отдельная обязательная конфигурация | **8 тестов, 331 assertion — PASS** |
| Миграции обеих БД с пустого состояния | **PASS** |
| Role gates Core/Commercial | Формально PASS; надёжность negative check ограничена A03 |
| Public browser: appearance, controls, menus, UX, responsive | **PASS**; 27 controls assertions, 30 циклов меню/10 диалогов без роста listeners |
| Public accessibility, axe A/AA | **28 состояний**, автоматических нарушений нет |
| Invitation/MFA + authenticated accessibility | **PASS с временным proxy agent**, 4 состояния axe без нарушений; штатная конфигурация — A05 |
| Resend/reset password browser, реальные письма Mailpit | **PASS на чистых fixtures**; порядок workflow — A01 |
| Mutating E2E/load guards | **PASS**, 4 entrypoints отказываются без isolation flags |
| Нагрузочный CI smoke, 1/5 одновременных запросов | **874 запроса, 0 ошибок**, худший p95 endpoint 51 ms |
| Нагрузочный stress, ступени 1/5/20/50/100 по 15 секунд | **12 547 запросов, 0 ошибок**, худший p95 endpoint 896 ms; все заданные пороги пройдены |
| Schema snapshots, reference, coverage, DBML, Pyright | **PASS**, 0 ошибок Python types |
| Actionlint, 14 Bash scripts, PowerShell syntax/ZIP regression | **PASS**; ZIP проверен в локальном PowerShell 5.1, не Windows GitHub runner |
| Composer Core validate/audit locked, npm audit frontend/docs | **PASS на настроенных порогах**; npm frontend имеет MODERATE findings |

Core и Commercial проверялись в имеющихся PHP images с текущими source/vendor;
для итогового Core использован снимок на отдельном Linux volume. Новая сборка всех
production images с нуля в этом проходе не выполнялась. Первый Core-запуск без HTTP
сервера дал 70 connection errors; стенд исправлен, полный итоговый прогон выше прошёл.
Промежуточный нагрузочный запуск параллельно другим проверкам не прошёл; указанный
smoke выполнен отдельно после них. Эти диагностические ошибки не выданы за дефекты кода.

Команды, результаты и воспроизведения: [checks.json](evidence/current/checks.json).
Автоматический axe не заменяет ручную проверку screen reader; нагрузка одного пользователя
на небольших fixtures не подтверждает production capacity и SLO.

## Интерфейс и соответствие заданию

- Реализованы recovery/resend/404, настройки темы/языка, плотности, шрифтов, отступов,
  радиусов, теней и скорости анимаций. Проверены сохранение, Undo, blocked storage,
  multi-tab, reduced motion, быстрые смены темы, RU/EN и ширины 320–1440.
- Чекбоксы, switch и range используют нативные inputs; проверены мышь, touch, клавиатура,
  mixed/disabled, рост бегунка и завершение drag снаружи. Полная семантика reset — A02.
- Dropdown и context menu имеют поиск, пустое состояние, три уровня каскада, мобильный
  Back, управление фокусом и modal scope. Нативное меню сохранено для редактируемых полей,
  ссылок и выделения текста. Повторные открытия не увеличивают глобальные listeners.
- JSON → CSS tokens и выбранный SVG sprite воспроизводятся без diff. Исходный SVG
  `design/мобайл.svg` (393×852) относится к будущему Education, а текущий интерфейс —
  Commercial. Совпадение цветов/токенов не означает реализации учебного экрана «Сегодня».
  Его реальные API и экраны остаются в [roadmap](roadmap/PLATFORM_ROADMAP.md).
- Шрифты загружаются локальными WOFF2; Google Fonts/CDN не нужны. Сохранены все
  **116 TTF, 3 WOFF2 и 8280 Solar SVG**. Приложение использует локальный спрайт из **28**
  выбранных символов через `<use>`. Рекомендация: оставить эту схему и именованный registry;
  переключать набор/стиль через registry, не включать все 8280 иконок в один bundle.
- Security UI пока показывает текущую сессию, enrollment и step-up. Управление всеми
  сессиями, отключение MFA/обновление recovery codes и checkout/subscription UI нельзя
  считать завершёнными по одному наличию backend endpoints. Это отдельная продуктовая приёмка.

Скриншоты просмотрены: [desktop, тёмная тема](evidence/current/settings-desktop.png),
[320 px, RU, увеличенный шрифт](evidence/current/settings-mobile.png).
На просмотренных состояниях не обнаружено горизонтального overflow или наложения контролов;
это не утверждение об отсутствии любых интерфейсных ошибок.

## Что удалено и что оставлено

Удалены **101 файл / 6 393 973 байта**:

- старый `DEEP_AUDIT_2026-11-01.md` и агентский `AGENT_DEEP_AUDIT_BRIEF.md`;
- исторический `PRODUCTION_READINESS_PROGRESS.md` с повторяющимися статусами;
- датированный `IMAGE_RISK_DECISION_2026-11-01.md`: существенное решение перенесено
  в короткий [IMAGE_SECURITY](operations/IMAGE_SECURITY.md), риск остался **NOT_ACCEPTED**;
- неиспользуемый `CHARTS_CONTRACT.md`: основные правила будущих графиков сведены в roadmap;
- три старых каталога evidence: controls/ux/deep-audit от 31 октября, включая дубли
  скриншотов, сырые scanner inventories и одноразовые воспроизводители.

Вместо архива отчётов оставлены этот аудит, [READINESS](operations/READINESS.md),
короткое решение по образам и одна текущая сводка с двумя скриншотами.
Навигация документации упрощена; технический каталог свёрнут. Архитектура, DB schema,
backup/restore, security/privacy contracts и генераторы сохранены: они нужны для
разработки и эксплуатации. Рабочие `.env`, данные Docker, MyBots, тесты проекта,
макет, лицензии, исходники иконок/шрифтов не удалялись.

Одноразовый Docker-стенд удалён: **6 контейнеров, 5 томов, 2 сети**, служебный actionlint
image. Исходные 12 контейнеров сохранили ID, image, StartedAt и mounts; 10 Platform
сервисов в конце `healthy`. MyBots не изменялся. Core/Commercial health возвращают 200;
прикладных таблиц соответственно 53/61. Во время нагрузки два Core heartbeat check
превышали 5-секундный timeout и восстановились после неё; проблему задания A06 это не закрывает.

## Следующий проход

1. Исправить A01 и повторить полный browser job в исходном порядке.
2. Исправить A02/A03 с проверками, которые воспроизводят именно обнаруженные ошибки.
3. Закрыть A05 на штатном прокси, затем выполнить весь workflow на зафиксированном SHA.
4. Исправить конфигурацию/диагностику scheduler по A06.
5. До production закрыть A04, получить свежие scans точных digest и решение по остаточным
   CVE. В этом проходе свежего Trivy scan нет; старые количества не применяются к новым
   инфраструктурным образам. Полный GitHub CI, внешние SMTP/платежи, TLS/DNS, restore/PITR,
   HA, privacy deployment и production SLO остаются **NOT_VERIFIED**.
6. После приёмки Commercial переходить к Education по roadmap, без фиктивных API.
