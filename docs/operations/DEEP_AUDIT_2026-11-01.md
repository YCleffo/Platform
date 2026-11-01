# Platform: независимый аудит — 1 ноября 2026

## Вердикт

**Основные автоматические проверки проходят. Пользовательский продукт при этом не завершён.**
Подтверждены **10 дефектов/расхождений и 3 инфраструктурных риска**; подтверждённого P0 нет.
Восстановление доступа и повторное подтверждение email нельзя считать готовыми.
Расширенная проверка доступности выявила нарушение на реальном экране настройки MFA.
Production readiness остаётся **NOT READY**.

Проверена ревизия `fa313953ca07cf1df9f904f5a61e4e5a10f068e7` (`ок [skip ci]`).
Перед аудитом working tree был чистым. Код приложения в этом аудите не исправлялся,
рабочие контейнеры не обновлялись. Проверки выполнены локально; GitHub Actions не запускался.
Аудит начат 31 октября, завершён 1 ноября. Между частями был длительный перерыв:
сырые wall-clock durations вспомогательного runner нельзя использовать как latency приложения.
Нагрузка измерялась отдельным новым прогоном после завершения сборок и остальных тестов.

[Окружение](evidence/deep-audit-2026-10-31/environment.json) ·
[машинные результаты backend](evidence/deep-audit-2026-10-31/backend-results.json) ·
[доказательства изоляции](evidence/deep-audit-2026-10-31/isolation-manifest.json).

## Что исправлять первым

1. **F01/F02:** закончить восстановление пароля и повторную verification. Это потерянные
   пользовательские сценарии, их не заменяет полировка настроек оформления.
2. **F09/F04:** устранить запоздалую навигацию после login и ложный success при неверном API response.
3. **F03/F13/F06/F10:** привести контракты контролов, крайние настройки и доступность MFA к приёмке.
4. **F07:** применить проверенное изменение в рабочем Control Plane и связать runtime с ревизией.
5. До production отдельно закрыть **F08/F11/F12** и эксплуатационные NOT_VERIFIED ниже.

## Подтверждённые findings

Приоритеты: P1 — основной сценарий заблокирован либо условие безопасного выпуска не выполнено;
P2 — ограниченный дефект UX, контракта или эксплуатации. Оценка scanner CRITICAL не равна
доказанному удалённому exploit или P0. Production-ограничения не блокируют автоматически локальную разработку.

| ID | Приоритет / область | Проблема | Последствие |
| --- | --- | --- | --- |
| F01 | P1 / продукт | Нет UI восстановления пароля | Потерявший пароль пользователь не может восстановить доступ через сайт |
| F02 | P1 / продукт | Resend на ошибке verification возвращает в регистрацию | Уже зарегистрированный pending user попадает в тупик |
| F03 | P2 / компоненты | Native form reset и FormData не сохраняют заявляемый контракт | DOM и Vue state расходятся; name теряется на AppInput |
| F04 | P2 / API boundary | Любой 204 принимается до runtime validation | Регистрация может показать успех без требуемого ответа |
| F05 | P2 / навигация | Нет catch-all/404 page | Неизвестные URL дают пустой экран без пути восстановления |
| F06 | P2 / UX | Разрыв подписей внутри слов при разрешённых настройках | Отсутствие overflow не означает приемлемую читаемость |
| F07 | P2 / runtime | Рабочий AuthService отличается от проверенного source | Оптимизация активности сессий пользователю ещё не применена |
| F08 | P1 перед production / БД | Оба runtime используют PostgreSQL superuser | У web-процесса избыточные DDL/role/RLS privileges |
| F09 | P2 / async UX | Завершившийся login меняет route после ухода со страницы | Пользователя выбрасывает с регистрации в кабинет |
| F10 | P2 / доступность | QR MFA не имеет доступного имени | Axe `svg-img-alt`, serious; этот экран отсутствует в прежнем accessibility gate |
| F11 | P2 локально, gate выпуска / инфраструктура | PostgreSQL/Mailpit имеют исправляемые findings вне текущего CI scan scope | Зелёные app-image gates не описывают весь стек |
| F12 | Риск выпуска / зависимости | В новых app images остаются HIGH/CRITICAL без известного исправления | Нужен разбор достижимости и решение по остаточному риску |
| F13 | P2 / настройки | Значение из storage не согласуется с step range | Thumb/ARIA value, текст и реально применённый масштаб расходятся |

### F01 — отсутствует восстановление пароля

В [router](../../apps/frontend/src/app/router/index.ts) отсутствуют request/confirm routes;
в [LoginView](../../apps/frontend/src/modules/commercial/auth/LoginView.vue) нет действия
«Забыли пароль?». При этом [AuthController](../../apps/control-plane/src/Infrastructure/Http/AuthController.php)
реализует `/auth/password/reset/request` и `/confirm`, а конфигурация email ведёт на `/reset-password`.

**Воспроизведение:** открыть `/login`, затем `/reset-password?token=synthetic-audit-token`.
В первом случае нет восстановления, во втором — пустой body, `mainCount=0`.
[Факты](evidence/deep-audit-2026-10-31/public-audit.json),
[экран](evidence/deep-audit-2026-10-31/missing-reset-route.png).

**Дальше:** две полноценные формы, интеграция с существующим API, обработка used/expired token,
generic request result, ограничения повторов, очистка токена из URL и переход к login.
**Приёмка:** real Mailpit request → письмо → новая вкладка confirm → новый пароль работает,
старый отклоняется; invalid/expired/used token не даёт пустой экран. Токен не сохраняется в storage/логах.

### F02 — повторное подтверждение email не восстанавливается

[VerifyEmailView](../../apps/frontend/src/modules/commercial/onboarding/VerifyEmailView.vue)
ведёт ссылкой resend на `/register`. Кнопка настоящего resend в
[RegisterView](../../apps/frontend/src/modules/commercial/onboarding/RegisterView.vue) доступна только
при локальном `registered=true`; reload это состояние теряет.

**Воспроизведение с реальным backend:** создать pending user → открыть invalid verification link →
Resend → ввести тот же email в регистрации. Ответ `400 / email_unavailable`, форма регистрации
остаётся, resend buttons — 0. [Результат](evidence/deep-audit-2026-10-31/auth-audit.json),
[экран](evidence/deep-audit-2026-10-31/resend-existing-user-dead-end.png).
Pending user также не проходит обычный login до verification: это нормальное серверное ограничение,
которое делает отсутствие recovery path существенным.

**Дальше:** независимый resend flow с вводом email и generic acknowledgement, доступный из
verification error и login; не требовать повторной регистрации.
**Приёмка:** pending user после reload/истечения ссылки получает новое письмо; старый токен
недействителен; verified/unknown email не раскрываются различающимися публичными результатами.

### F03 — контракт native forms нарушен

В отдельной HTML-форме отрендерены реальные компоненты из source.
При исходном `AppSwitch model=true` нажатие native reset оставляет Vue model=true,
но input.checked=false и ручку в выключенном состоянии.
У `AppInput name="username"` атрибут попадает на wrapper, не на input: FormData не содержит username.
После reset текстового input DOM value становится пустым, а Vue model остаётся `Changed`.

Источники: [AppSwitch](../../apps/frontend/src/shared/ui/fields/AppSwitch.vue),
[AppInput](../../apps/frontend/src/shared/ui/fields/AppInput.vue).
[DOM/model/FormData](evidence/deep-audit-2026-10-31/public-audit.json),
[контрактный стенд](evidence/deep-audit-2026-10-31/switch-reset-desync.png).
Это дефект переиспользуемого контракта; текущие login/register используют JS submit,
поэтому отсутствие name само по себе не означает, что их запросы сейчас теряют email.

**Дальше:** явно определить и реализовать reset через controlled form state, передавать native
form attributes в настоящий control. Не добавлять глобальные listeners без lifecycle cleanup.
**Приёмка:** reset синхронно восстанавливает оговорённые defaults в DOM, model и FormData;
disabled поля не отправляются, required работает. Добавить browser regression, а не только props assertions.

### F04 — 204 обходит validator

[http.ts:89](../../apps/frontend/src/shared/api/http.ts) возвращает undefined для 204 раньше,
чем проверяет `options.validate`. [RegisterView](../../apps/frontend/src/modules/commercial/onboarding/RegisterView.vue)
после такого ответа устанавливает `registered=true`.

**Воспроизведение:** fault injection только ответа `/auth/register` в browser test: 204 с пустым
body. UI показывает «We sent a verification email». Это проверка устойчивости клиента;
настоящий backend в этом тесте не обвиняется в возврате 204.
[Факт и отметка injection](evidence/deep-audit-2026-10-31/public-audit.json),
[ложный success](evidence/deep-audit-2026-10-31/register-unexpected-204.png).

**Дальше:** различать разрешённый void response и endpoint с обязательной схемой.
**Приёмка:** неожиданный empty/204 для register/login/create не переводит UI в success;
валидные void logout/reauthenticate продолжают работать. Проверить и transport, и реальную форму.

### F05 — неизвестный URL даёт пустую страницу

У router нет catch-all. `/does-not-exist` даёт пустой body, без main, сообщения и навигации.
[Экран](evidence/deep-audit-2026-10-31/missing-404.png).
Это отдельный дефект от отсутствия reset feature: 404 нужна и для ошибочных/устаревших ссылок.

**Приёмка:** неизвестный deep link отображает локализованную страницу с понятным возвратом;
не вызывает redirect loop и не маскирует неизвестный route пустым успешным экраном.

### F06 — подписи ломаются при масштабировании

На 320 px, RU, fontScale=1.4 и spacious разрешённые theme/density tiles разрывают
«Системная», «Компактная», «Просторная» внутри слова.
Причина: минимальная ширина grid и `overflow-wrap:anywhere` у `.appearance-choice` в
[AppearanceSettingsView:159](../../apps/frontend/src/modules/settings/AppearanceSettingsView.vue).
[Тема](evidence/deep-audit-2026-10-31/theme-label-wrap.png),
[плотность](evidence/deep-audit-2026-10-31/density-label-wrap.png).
Горизонтального overflow в этом режиме нет — прежний smoke правильно это подтверждает,
но не оценивает качество переноса и визуальную иерархию.

**Приёмка:** на 320/393 px при всех разрешённых настройках подписи остаются читаемыми целыми
словами; при необходимости tiles переходят в одну колонку. Не уменьшать выбранный размер шрифта,
не прятать label и не возвращать горизонтальный скролл ради прохождения проверки.

### F07 — в рабочем runtime отсутствует проверенная оптимизация

Сравнение нормализованных строк, а не только hashes, подтвердило: рабочий Control Plane
по-прежнему UPDATE-ит last_used_at на каждом access request. В source/new production image
эта запись ограничена минутным интервалом. [Diff](evidence/deep-audit-2026-10-31/auth-runtime-diff.txt),
[fingerprints](evidence/deep-audit-2026-10-31/working-runtime.json).
Это известный deployment gap, не ошибка принципа неизменяемых Docker images.

**Дальше:** обычный контролируемый rebuild/restart Control Plane и проверка применения
ревизии, без миграции/очистки данных. Добавить release provenance к образам/диагностике.
**Приёмка:** running artifact однозначно связан с проверенным SHA; health, реальные login/account
flows и сохранность БД подтверждены после обновления. В этом аудите обновление не выполнялось.

### F08 — runtime подключается суперпользователем

Read-only проверка реальных рабочих credentials показала у обеих app roles:
`rolsuper=true`, `rolcreatedb=true`, `rolcreaterole=true`, `rolbypassrls=true`.
[Результат](evidence/deep-audit-2026-10-31/working-runtime.json).
Это задокументированная модель локального bootstrap, не неожиданное нарушение dev Compose;
для production это незакрытое условие безопасности.

На disposable Commercial DB проверен существующий provision script. SELECT и rollback INSERT
разрешены; CREATE/TRUNCATE/ALTER, удаление migration history и pg_read_file запрещены.
Отдельный HTTP-процесс под runtime-role успешно выполнил register/login/me/sessions/create/read account.
Fixture activation сделана SQL, доставка email ранее проверена отдельно.
[Все результаты](evidence/deep-audit-2026-10-31/database-role-checks.json).

**Приёмка:** отдельные migration/runtime credentials для обеих БД, denial gates в CI и ключевые
HTTP/worker business flows под runtime-role. Не считать один SELECT доказательством готовности всех операций.

### F09 — запоздалый login отменяет выбор пользователя

В [LoginView](../../apps/frontend/src/modules/commercial/auth/LoginView.vue) router.push выполняется
после await без проверки, что этот экземпляр view ещё активен.

**Воспроизведение:** отправить настоящий login, задержать только доставку его ответа,
нажать Sign up, затем отпустить ответ. Route меняется `/register` → `/cabinet`.
[Факт с realBackend=true](evidence/deep-audit-2026-10-31/auth-audit.json).

**Приёмка:** завершение покинутой операции не перенаправляет и не меняет новую форму.
Установка сессии и навигация имеют явный lifecycle; где возможно применяется AbortSignal.
Regression проверяет поздние success/error для login и MFA, а также более новую auth operation.

### F10 — QR MFA без доступного имени

В [MfaSetupDialog:97](../../apps/frontend/src/modules/commercial/security/MfaSetupDialog.vue)
`QrcodeSvg` без accessible name. На настоящей enrollment форме axe нашёл
`svg-img-alt`, impact serious, target `svg[width="200"]`.
[Authenticated accessibility](evidence/deep-audit-2026-10-31/authenticated-accessibility.json).
Рядом есть ручной secret: не утверждаю, что blind user полностью лишён альтернативы QR.
Сам QR/secret/recovery codes в evidence не сохранены.

**Приёмка:** корректный локализованный name/description либо aria-hidden при полноценной
эквивалентной альтернативе; не включать secret в alt. Этот authenticated state входит в CI accessibility matrix.

### F11 — исправляемые находки инфраструктуры не входят в текущие gates

Свежий scan используемых images от 1 ноября:

| Target | HIGH | CRITICAL | Package findings с известным fix |
| --- | ---: | ---: | ---: |
| Новый Core production | 60 | 1 | 0 |
| Новый Commercial production | 60 | 1 | 0 |
| Используемый postgres:18 | 92 | 2 | 30 |
| Используемый redis:8-alpine | 0 | 0 | 0 |
| Используемый Mailpit v1.31.1 | 5 | 0 | 5 |

Из 30 исправляемых PostgreSQL-image findings: 26 относятся к вспомогательному `gosu`,
4 — к OS packages. Исправляемый CRITICAL `CVE-2025-68121` найден в Go stdlib **gosu**;
это не доказательство эксплуатации PostgreSQL server. Mailpit — dev/test инфраструктура.
Текущий workflow сканирует app images, не эти инфраструктурные targets.
[Summary](evidence/deep-audit-2026-10-31/security-summary.json),
[PostgreSQL inventory](evidence/deep-audit-2026-10-31/trivy-postgres.json),
[Mailpit inventory](evidence/deep-audit-2026-10-31/trivy-mailpit.json).

**Приёмка:** проверенные обновлённые infrastructure images, фиксированная версия/digest и
явный scan scope для release. Отдельно оценить достижимость findings; не переходить на другой
PostgreSQL major и не удалять данные ради scanner PASS.

### F12 — остаточный риск app images остаётся

Оба заново собранных app images содержат CRITICAL `CVE-2026-6653` в libxml2 и 60 HIGH,
без известного свежей DB сканера fix. 31 октября HIGH было 58: обновление inventory 1 ноября
изменило результат без изменения source. [Полные Core](evidence/deep-audit-2026-10-31/trivy-core.json)
и [Commercial](evidence/deep-audit-2026-10-31/trivy-commercial.json) inventories сохранены.

**Дальше:** установить достижимость уязвимых функций из приложения, доступность совместимого
исправления/base image, compensating controls и владельца решения по остаточному риску.
**Приёмка:** исправление с regression evidence либо явная оценка/согласованное ограничение риска.
Не превращать `ignore-unfixed` gate в утверждение «уязвимостей нет». Exploitability здесь не доказана.

### F13 — off-step preferences рассинхронизируют range

При сохранённом `fontScale:1.03` normalizer оставляет 1.03, а native range со step .05
округляет value до 1.05. В результате thumb/aria-valuenow соответствуют 105%,
output и aria-valuetext — 103%, реально применённый CSS scale — 1.03.
[Измерение](evidence/deep-audit-2026-10-31/offstep-preferences.json).
Источник: [normalizeAppearance](../../apps/frontend/src/app/appearance/index.ts) и
[AppRange](../../apps/frontend/src/shared/ui/fields/AppRange.vue).

**Приёмка:** восстановление off-step data даёт согласованные state, CSS, native value, output
и ARIA. Например, при выбранной политике .05 все становятся 1.05/105%. Проверить остальные numeric settings,
границы и старые storage versions; обычные UI steps должны остаться точными.

## Что действительно работает

- PostgreSQL schemas: 54/62 таблицы с migration history; invalid indexes и unvalidated constraints — 0.
  Рабочие БД проверялись чтением. Миграции и rollback/upgrade — только на disposable DB.
- 76 controller routes/methods сопоставлены с 70 OpenAPI paths без пропусков.
  Это проверка paths/methods, не доказательство эквивалентности всех response schemas.
  [Inventory](evidence/deep-audit-2026-10-31/route-inventory.json).
- Account isolation, permissions, session/MFA и invitation lifecycle проходят реальные integration/browser tests.
  Scoped foreign-account GET по контракту скрывается 404, а не выдаёт чужие данные.
- Core и Commercial production images заново собраны, non-root/dev-files checks и HTTP/schema smoke
  без checkout mounts проходят. Scheduler/outbox readiness проверена отдельным сценарием с настоящими workers.
- HTTP unauthorized response — 401 и no-store/private, CORS для постороннего Origin не открыт.
  Infrastructure ports опубликованы на 127.0.0.1. Redis без пароля не объявляется публично доступным:
  проверенный binding — loopback. [Headers](evidence/deep-audit-2026-10-31/http-headers.json),
  [bindings](evidence/deep-audit-2026-10-31/infrastructure-exposure.json).
- Inter/Space Mono и SVG sprite локальные. В dist только 28 выбранных symbols; полные 8280 SVG/116 TTF
  сохранены. [Состав dist](evidence/deep-audit-2026-10-31/frontend-assets.json).
- Настройки themes/storage/undo/reduced motion, native mouse/touch drag, release outside и custom menu
  keyboard/cascade/Back проверены. Repeated overlays: 30 menu/10 dialog cycles, listeners 1 → 1.
  Это не доказательство отсутствия любых heap leaks.
- Canonical JSON tokens и generated CSS совпадают. SVG Education — будущий отдельный модуль;
  отсутствие его экранов в Commercial не записано как баг.

## Проверки и соответствие локальному CI

| Область / команда | Результат |
| --- | --- |
| Frontend npm ci | PASS в отдельной временной директории; working install дополнительно npm ls — PASS |
| npm test | PASS: 146 tests / 34 files |
| typecheck, lint, build, check:tokens/icons/styles | PASS |
| npm audit --audit-level=high / --omit=dev | PASS gate; dev — 19 MODERATE, production — 0 |
| verify-controls | PASS: 24 assertions, mouse/touch/keyboard/persistence/reduced motion |
| verify-appearance, verify-menus, verify-ux, verify-responsive | PASS; 13 routes × 9 widths, 320–1440 |
| Public accessibility | PASS: 18 states / 0 автоматических A/AA violations |
| Расширенная authenticated accessibility | FAIL: 12 states, 1 svg-img-alt в MFA setup; JSON passed=false |
| Core Composer strict/platform/audit, Psalm --no-cache | PASS |
| Core Unit / Console / Functional / API | PASS: 350/2/92/70 tests отдельно; не суммировать assertions с full повторно |
| Core full --seed 2120741368 | PASS: 514 tests / 14 081 assertions; внутренний timer 14:17 |
| Commercial Composer/YAML/container/PHPStan | PASS в корректном dependency layout |
| Commercial full | PASS: 449 tests / 4916 assertions, повторено после migration cycle и clean reset |
| Commercial previous/latest/down/up, reset guard dev refusal | PASS |
| Cross-plane required group | PASS: 8 tests / 331 assertions |
| Core stateless restart / Commercial worker readiness | PASS |
| Production non-root/no-dev/no-secrets/HTTP schema smoke | PASS для обоих images |
| Trivy app fixable HIGH/CRITICAL gate | PASS: ??? exit 0; ?????? unfixed inventory ?? ????? |
| Actionlint + embedded shell / bash syntax | PASS |
| PowerShell syntax + ZIP fixture regression | PASS; commits создавались только внутри одноразового ZIP fixture, не в Platform |
| Schema snapshot/reference/coverage/links, DBML, Pyright | PASS |
| Account workspace / invitation-membership-MFA browser | PASS на реальном disposable backend |
| Least-privilege Commercial role SQL + sampled HTTP | PASS; это не полный lifecycle всех финансовых/worker операций |
| CI smoke / extended multi-user load | PASS: 1163 CI smoke; 53 071 reads + 80 writes multi-user; ????????? recovery repeat |

Воспроизводимые команды находятся в workflow и frontend README. Exact commands, exit codes и
окружение сохранены в [backend](evidence/deep-audit-2026-10-31/backend-results.json),
[Commercial](evidence/deep-audit-2026-10-31/parallel-commercial-results.json),
[migration/HTTP](evidence/deep-audit-2026-10-31/extra-results.json),
[production](evidence/deep-audit-2026-10-31/production-results.json),
[docs](evidence/deep-audit-2026-10-31/docs-results.json),
[load](evidence/deep-audit-2026-10-31/load-results.json) metadata.

Локальный runner не является GitHub runner: Windows/Git Bash, другой separator COMPOSE_FILE,
явный `CI=true` для разрешённого disposable stand и временный TLS. Старый SHA не выдаётся за текущий.
Один вспомогательный PHPStan invocation без `/backend/vendor` завершился ошибкой layout;
корректный эквивалент CI с read-only backend mount проходит. Это не обнаруженная ошибка PHPStan проекта.
Guard сначала отказал ошибочно настроенному MAILPIT URL; исправлен URL стенда, guard не ослаблялся.
В helper нагрузки первоначально ожидался 403; по коду и OpenAPI восстановлено точное ожидание 404
до измерения workload. При обновлении Trivy DB был transport EOF; после успешной загрузки DB
тот же Core image проверен повторно с проверкой TLS, без CVE ignores.

## Нагрузка

10 ??????????? ?????????????/??????, ?? 100 ???????? ????????? (1000 ?????), catalog/me/
paginated accounts/sessions. ?? ???? ?????? ? 80 ???????? create account ? ????????????
rate limits. ?????: 30 s baseline, 120 s read/write, 15 s burst, 30 s recovery.

| ???? | Concurrency | Read requests | Errors | p95 | ?????? read endpoint p95 | RPS |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| baseline | 10 | 8905 | 0 | 46 ms | 48 ms | 297 |
| extended-read-write | 50 | 34584 | 0 | 310 ms | 320 ms | 288 |
| burst | 100 | 3746 | 0 | 600 ms | 629 ms | 245 |
| recovery | 10 | 5836 | 0 | 73 ms | 76 ms | 194 |

?????: **53,071 read requests, 0 ??????**, 80 writes / 0 ??????,
write p95 **472 ms**. ? ???? 10 ????????????? replay ?????? ???????? ID,
?????????? ????????? create ??????, ?????????? ????? ???? ????????? 429, ???????? ??????????
????????? ? ????? 109 ?? ????????????. Foreign account GET ? 404 ?? ?????????.
[?????? ???????](evidence/deep-audit-2026-10-31/load-multi.json).
???????????????? ?????????? [replay harness](evidence/deep-audit-2026-10-31/load-multi-replay.mjs) ???????? ??? evidence: ?????? ?? ????? ??????? ????? fresh disposable migrations/catalog seed, ? ???? ?? mutation markers ? nonce. ???????????? JSON ?? ??????????????; ????? ????????? ????? ? runtime/deep-audit-replay. ????? ????????? import/output ? ?????????? ???????? ?????????; ?????? workload ????????? ? ????????? ???????????? helper, ?????????? ? command metadata.


????????? CI smoke: **1163 ???????? / 0 ??????**,
????? 1/5 concurrent workers ?? 5 s.
[CI smoke](evidence/deep-audit-2026-10-31/load-ci-smoke.json).

? ?????? ??????? recovery throughput ???? baseline. ??????? ?????? ?? ??? ?? ??????
? ?????? ???????? ??? baseline 211 ? recovery 192 RPS,
p95 68 ? 73 ms, ??? ??????. ??? ??????????,
?? ?????????? ?????? ? ?? ????????????? ?????????? production capacity.
[??????](evidence/deep-audit-2026-10-31/load-recovery.json).

? 19 DB samples ???????? ???????: ???????? 21 client connection (??????? sampler),
8 active; lock waiters / blocked / deadlocks / pending email ? 0. Sampling ??? ? 3 s
????? ?????????? ???????? ????????. Domain outbox events ?? ??????????? ? email queue.
[DB samples](evidence/deep-audit-2026-10-31/load-database-samples.json),
[CPU/RAM samples ????????? ???????](evidence/deep-audit-2026-10-31/load-resource-samples.json).


Пороги заданы до измерения: p95 ≤1000 ms и error rate ≤1% для этапа и каждого read endpoint;
для 80 запланированных записей — тот же latency/error budget и minSamples=80.
Нет request retries и ослабления rate limits. Отдельные ожидаемые 429 quota checks не включены
в successful-read latency. Проверяется не только status, но и данные/изоляция/количество аккаунтов.

Окружение: Windows Docker Desktop, 16 CPU, Docker memory ~31.3 GiB; локальный closed workload.
Это расширенный короткий тест с небольшими по production-меркам данными, не длительный soak,
не open-arrival SLO и не доказательство промышленной ёмкости.

## Что ещё НЕ доказано

- Реальный iOS/Safari, Firefox, VoiceOver/NVDA и настоящий browser zoom 200%.
  Chromium touch emulation и app fontScale=140% не заменяют эти проверки.
- HA/failover, длительный soak, большая финансовая история, restore/PITR drill и допустимые RPO/RTO.
- Реальный provider/YooKassa smoke: тесты проверяют адаптеры/контракты, не merchant production setup.
- Production TLS/DNS/ingress и утверждённая оператором privacy configuration.
- Достижимость перечисленных CVE в реальных entrypoints; scanner inventory не является exploit PoC.
- Полная работа Core и всех Commercial workers/financial flows с production least-privilege credentials.
- Сквозная функциональность checkout/subscription UI и полный Security management lifecycle
  не следует из наличия backend endpoints. Их границу релиза нужно явно определить по roadmap.
- Систематический анализ всех heap retainers и полный response-schema equivalence всех endpoints.

Локальное окружение вставляет `local.adguard.org` в HTTP responses, включая изменение CSP.
Source/dist не используют этот font/icon CDN. Настройки фильтра не менялись; browser/network
измерения в этом окружении нельзя приписывать только коду приложения.

## План следующей работы

S/M/L — относительная оценка при наличии работающего окружения; это не обещание календарных сроков.

| Порядок | Задача | Модули / зависимости | Объём | Готовность и обязательная проверка |
| --- | --- | --- | --- | --- |
| 1 | F01: password recovery request/confirm | router, commercial/auth API, две формы; существующий backend | M | Реальное письмо, новая вкладка, single-use/expired, смена пароля, понятный путь к login |
| 2 | F02: standalone resend | onboarding, login, existing resend API | S–M | Pending user после reload получает новое письмо; нет email enumeration и dead end |
| 3 | F09/F04: async lifecycle и строгий response contract | Login/MFA, useAsyncAction, http client | M | Late responses не уводят с новой страницы; unexpected 204 не даёт success; void endpoints сохранены |
| 4 | F03/F13: form/reset/native attributes и numeric normalization | shared fields, useForm, appearance normalizer | M | DOM/model/FormData/ARIA согласованы; off-step/corrupt storage и границы покрыты browser tests |
| 5 | F10/F06: a11y MFA и reflow контролов | MFA setup, settings tile grid | S–M | 0 нарушений в authenticated matrix; целые подписи на 320/393 при extreme preferences |
| 6 | F07: применить проверенный runtime | Control Plane build/release; после regression gates | S | Artifact связан с SHA; diff применён; ready/login/account flows и данные сохранены |
| 7 | F08: раздельные DB roles в выпуске | deployment secrets/migrator/runtime, обе БД | M | Runtime не superuser; denial gates + HTTP/worker scenarios под runtime-role |
| 8 | F11/F12: image inventory и release risk | app/infra Docker images, CI security matrix | M–L | Совместимые обновления, fresh inventories, оценка достижимости и принятое решение по остаткам |
| 9 | Уточнить границу Commercial релиза | roadmap, checkout/subscription и Security lifecycle | M для спецификации | Явный перечень доступных пользователю действий и незавершённых функций; без фиктивных API |
| 10 | После закрытия критических journeys — общий design-system polish | control tokens/variants, style consistency | M | Единые размеры/стадии/тексты, обоснованные a11y exceptions; существующее поведение сохранено |

Можно начать 1–5 параллельно по независимым модулям при согласованном API; F01/F02 имеют
готовую серверную основу. F06 не требует переделывать весь frontend. Не начинать Education
для маскировки незавершённых Commercial recovery flows.

В UI стоит сократить роль технической демонстрации: `/settings/components` оставить явно
обозначенным showcase, а главные сценарии продукта делать понятными без его посещения.
Повторяющуюся ARIA-description логику и control geometry tokens унифицировать после фиксации
правильных контрактов. Фиксированный размер iOS-style switch не является багом сам по себе.

## Документация и кандидаты на удаление

Проверены 48 исходных MD: локальный граф не показал забытых документов, кроме отдельного audit brief;
README как корневой вход ожидаемо не требует входящих ссылок.
[Inventory](evidence/deep-audit-2026-10-31/documentation-inventory.json),
[граф ссылок](evidence/deep-audit-2026-10-31/document-links.json).

| Документ / группа | Решение | Причина / что сохранить |
| --- | --- | --- |
| AGENT_DEEP_AUDIT_BRIEF.md | Кандидат на архив после приёмки аудита | Задание выполнено; требования можно сохранить как checklist. Не нужен как второй текущий статус проекта |
| PRODUCTION_READINESS_PROGRESS.md | Переписать living summary, не удалять | Старый SHA и смешение истории/текущих статусов. Вынести историю в dated audits, оставить актуальные blockers и evidence links |
| PLATFORM_ROADMAP.md | Уточнить stage/готовность по journeys | Явно отметить отсутствие password recovery UI, resend recovery и границу billing/Security UI |
| docs/frontend/README.md | Уточнить native form contract после F03 | Не обещать native semantics шире реально проверенных возможностей |
| apps/frontend/README.md | Сохранить как точку запуска | Команды; не дублировать сюда всю архитектуру и каждый старый прогон |
| DBML, schema snapshots, generated reference/coverage, process diagrams | Сохранить | Они проверяются tools и имеют конкретных consumers; generated не означает ненужный |
| Evidence и licenses | Сохранить по политике хранения | Нужны для воспроизведения/атрибуции. Исключать секреты и одноразовые сырые outputs |
| Полные Solar/TTF libraries | Сохранить | Прямое требование пользователя; shipping subset уже ограничен |

**Безусловных документов к удалению в текущем дереве не выявлено.** Предлагать удаление
актуального контракта только ради сокращения числа файлов было бы необоснованно.

## Очистка и сохранность

??????? ?????????? 6 ??????????? disposable project, 3 ??? ????, 2 ????, 7 ?????????
image references, 101 ????????? ?????? build cache ? 8 ??????? ??????? ??????. Build cache ? 0 B,
history ?????. ??? 12 ???????? ??????????? ????????? IDs, image IDs, mounts ? ?????? StartedAt.
???????? 6 ???????? image tags ? 4 ??????? ????, ??????? MyBots. ??? ??????? /health/ready ? 200.
[???????? ????????? ? ???????](evidence/deep-audit-2026-10-31/cleanup.json).

??????????? orchestration helpers, ????????? npm install, secrets/TLS/logs, screenshots ??? curated evidence,
Vitest/Python test caches ???????. ??????????? frontend processes ???????????; ????????????????
Vite ????????. ??????? ?? ?? ????????? ? ?? ?????????????? ??? mutating fixtures.


Временный component harness удалён из frontend. В Platform не создавались commits/push;
новые постоянные файлы этого этапа — данный отчёт и обезличенные evidence.
Для build cache применялась адресная фильтрация записей аудита, не глобальная очистка
Docker resources ([Docker buildx filters](https://docs.docker.com/reference/cli/docker/buildx/prune/)).
