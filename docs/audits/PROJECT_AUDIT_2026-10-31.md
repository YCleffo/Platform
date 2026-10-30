# Аудит Platform — 31.10.2026

**Коммит:** `841b3bd017d93eff5ab2f83c3965a98c2ae6cfc9` (`main`). **Часовой пояс:** Asia/Yekaterinburg.

**Вердикт: CI_FAILED · CORE_NOT_READY · BROWSER_E2E_NOT_VERIFIED.**

Падает половина CI: **3 FAIL / 3 PASS**. Core, frontend и PowerShell прошли. Commercial и cross-plane блокирует синтаксис YAML; документацию — несоответствие migration manifest. За этими ошибками обнаружены дополнительные дефекты приглашений и непройденные интеграционные проверки. Принимать FRONTEND-06 и переходить к новым функциям до исправлений нельзя.

## 1. Что действительно проверено

- Вручную запущен **весь существующий workflow**, без изменений и push: [GitHub Actions №41122551042](https://github.com/YCleffo/Platform/actions/runs/41122551042). Выполнен 31.10.2026, 01:21–01:28; результат `failure`, проверен SHA. [Снимок запуска](evidence/2026-10-31/github-dispatch-run.json), [все jobs и steps](evidence/2026-10-31/github-dispatch-jobs.json).
- Локально заново установлены locked npm dependencies, выполнены проверки frontend, PHPStan, Psalm, PHP syntax, unit-тесты Commercial, проверки документации и Compose.
- В обеих рабочих PostgreSQL выполнены только чтение каталога, истории миграций, привилегий и проверки схемы. Сравнены таблицы, поля, ограничения, индексы, триггеры, функции и enum с JSON-каталогами.
- В настоящем Chrome выполнен read-only responsive smoke; SVG отрисован, страницы просмотрены. Отдельно воспроизведены два дефекта на контролируемых фикстурах; это **не** интеграционный E2E.
- Проверены инварианты, HTTP-контракты и их CI-покрытие, миграции, настройки workers, приглашения, дизайн-токены, шрифты и документация. Присланный отчёт не использован как доказательство прохождения проверок.

Исходники приложений, рабочие данные, миграции и конфигурация не изменены. Рабочие сервисы не перезапускались. Записаны этот отчёт и материалы аудита. Появившаяся во время проверки незакоммиченная папка `apps/frontend/src/shared/Solar icons/` сохранена; она не относится к проверенному SHA.

## 2. Матрица CI

| Job | Результат текущего SHA | Что подтверждено / где остановился |
| --- | --- | --- |
| PowerShell operator tools | **PASS** | Синтаксис PowerShell; регрессия ZIP, Unicode, несколько Git paths, исключение ignored-файлов |
| Yii Backend QA | **PASS** | Composer/platform/audit, Psalm, чистые миграции, Unit/Console/Functional/API, полный seed, restart, production image |
| Symfony Commercial QA | **FAIL** | Composer и PHPStan прошли; загрузка `security.yaml` падает до применения миграций |
| Strict cross-plane E2E | **FAIL** | Та же ошибка YAML при запуске Commercial; текущая cross-plane матрица не выполнена |
| Database diagrams/docs | **FAIL** | Core snapshot прошёл; Commercial: `Migration source drift` |
| Frontend quality | **PASS** | npm ci, tokens, typecheck, lint, 108 тестов, E2E refusal guard, production build |

Core: Unit **350 / 12 526 assertions**, Console **2 / 9**, Functional **92 / 857**, API **70 / 719**. Полный независимый прогон с seed `2120741368`: **514 тестов / 14 081 assertions**. Restart-проверка подтвердила сохранение durable runtime row и работу cleanup после перезапуска. [Извлечение лога Core](evidence/2026-10-31/github-job-124292303810.json).

Commercial после первого сбоя **пропускает** unit/integration, повторные миграции, upgrade/down/up, reset/rerun, readiness workers, production build и Trivy. Это не успешные проверки. Причины: [Commercial](evidence/2026-10-31/github-job-124292303854.json), [cross-plane](evidence/2026-10-31/github-job-124292303860.json), [документация](evidence/2026-10-31/github-job-124292303891.json).

## 3. Блокеры и ошибки

### A01 · P0 · Commercial не загружается с текущими исходниками

**Место:** [security.yaml](../../apps/control-plane/config/packages/security.yaml), строки 18–20.

Три элемента `access_control` потеряли отступы: catalog, invitation preview, YooKassa webhook. Symfony сообщает `You cannot define a sequence item when in a mapping`. Ошибка подтверждена локальным `lint:yaml` и свежим CI. Миграционный job падает на конфигурации приложения, а не на SQL новой миграции.

**Исправить:** вернуть элементы внутрь `security.access_control`; добавить ранние `lint:yaml` и boot/container checks до дорогих Docker-сборок. **Приёмка:** YAML lint, загрузка test/prod Kernel, затем полный Commercial и cross-plane CI. [Локальный результат](evidence/2026-10-31/commercial-yaml.txt).

### A02 · P1 · Commercial snapshot не связан с новой миграцией

**Место:** [schema.catalog.json](../database/commercial/schema.catalog.json), [Version20261031000100.php](../../apps/control-plane/migrations/Version20261031000100.php).

В JSON уже есть 61-я таблица `commercial_account_invitations`, но `migration_sources` не содержит новую миграцию: manifest описывает **40 файлов**, в исходниках **41**. Поэтому `export_schema.py --plane commercial --from-snapshot --check` воспроизводимо возвращает exit 1. Парсинг DBML и совпадение генераторов со snapshot этого не обнаруживают.

**Исправить:** применить все миграции на пустой изолированной БД и экспортировать реальный каталог штатным exporter, затем DBML/reference/coverage. Не исправлять один SHA вручную. **Приёмка:** все семь проверок документации и CI проходят. [Результаты команд](evidence/2026-10-31/docs-checks.json).

### A03 · P1 · Audit-контракт БД несовместим с membership и приглашениями

**Места:** [MembershipService](../../apps/control-plane/src/Account/Application/MembershipService.php), [InvitationService](../../apps/control-plane/src/Account/Invitation/Application/InvitationService.php), [audit trigger migration](../../apps/control-plane/migrations/Version20261001000100.php).

Два независимых дефекта:

1. Пустой PHP-массив сериализуется в `[]`, а триггер вызывает `jsonb_object_keys`/`jsonb_each`, ожидающие объект. `MembershipService::remove()` и invitation revoke/resend/decline передают `[]`. Ошибка removal уже была в реальном CI; текущий код сохранил проблему. Read-only SQL-проба также возвращает `cannot call jsonb_object_keys on an array`.
2. Поле metadata `role` разрешено только для `commercial_membership.saved` и `commercial_membership.role_changed`. Новые `commercial_invitation.created`, `commercial_invitation.accepted`, `commercial_membership.reactivated` отсутствуют в allowlist. Новая миграция создаёт таблицу, но не обновляет эту функцию. Следовательно, после починки YAML создание приглашения всё равно будет откатываться на audit INSERT.

**Исправить:** сериализовать пустую metadata как `{}`; отдельной совместимой миграцией расширить точный allowlist новых действий, сохранив запреты на PII и произвольные ключи. Не отключать триггер и не ослаблять его целиком. **Приёмка:** реальные create/accept/resend/revoke/decline, removal/reactivation, негативные privacy metadata cases. [SQL-проба](evidence/2026-10-31/jsonb-array-probe.json), [фактическая функция](evidence/2026-10-31/audit-metadata-contract.json).

### A04 · P1 · Idempotent replay приглашения обходит актуальную проверку прав

**Место:** [InvitationService::create](../../apps/control-plane/src/Account/Invitation/Application/InvitationService.php), строки 55–71.

Ветка `completed` возвращает `findById()` **раньше** account lock и `requirePermission`. Controller проверяет sensitive MFA, но не membership. Бывший администратор, сохранивший прежний key и payload, после отзыва членства может повторить запрос и получить запись приглашения, включая email. Это чтение результата без текущих прав; новой роли пользователю оно не назначает.

На DBAL-фикстуре с отсутствующим membership сервис вернул приглашение, выполнив **0 authorization queries**. Реальной БД фикстура не касалась. **Исправить:** проверять текущие права под account lock на всех ветках, включая replay; сохранить согласованный порядок блокировок. **Приёмка:** replay после revoke/downgrade, другой actor, другой account, прежний успешный retry и конфликт payload. [Воспроизведение](evidence/2026-10-31/invitation-idempotency-authorization.json).

### A05 · P1 · В CI не переданы новые invitation env

**Места:** [compose.ci.yaml](../../compose.ci.yaml), [services.yaml](../../apps/control-plane/config/services.yaml), строки 202–209.

`control-plane-test` и наследующий `cross-plane-test` не получают `CONTROL_INVITATION_URL` и `CONTROL_INVITATION_TTL_SECONDS`. У Symfony нет default parameters для них. В обычных API/worker services обе переменные уже есть. Ошибка сейчас скрыта YAML-блокером; проявится при создании соответствующих сервисов/worker.

**Исправить:** добавить согласованную конфигурацию в test runtime и пример env; проверить запуск реального outbox worker. `control-migrate` также не получает их — проверить его фактический DI graph, не считать это доказанным падением мигратора. [Безопасная выборка Compose](evidence/2026-10-31/compose-sanitized.json).

### A06 · P1 · Старые интеграционные ошибки остаются за текущим блокером

В [прогоне 13b1fe2](https://github.com/YCleffo/Platform/actions/runs/41115685025) Commercial выполнил **423 теста / 4756 assertions**, получил **4 errors / 1 failure**. Это предыдущий SHA, а не результат текущего. Относящиеся к следующим трём причинам файлы не изменились до текущего HEAD (`git diff` проверен):

| Ошибка | Причина | Исправление |
| --- | --- | --- |
| `CommercialMeHttpTest` | `getContainer()->get(AuthService::class)` обращается к приватному/inlined service | Корректный test container или HTTP fixtures; не делать все production services публичными |
| Два `RecentAuthenticationTest` | Fixture записывает `mfa_authenticated_at` в сессию с `authentication_method=password`, нарушая CHECK | Сначала создать корректную MFA-сессию/step-up, затем состарить evidence; CHECK сохранить |
| `HttpErrorResponsesTest`, DELETE catalog | Тест требует `no-store` для любого `/api/control/*`; subscriber применяет его к ограниченному набору путей | Зафиксировать единую cache policy и согласовать код с тестом |

Четвёртый error — audit `[]` из A03. **Приёмка:** полный Commercial integration suite после A01–A05 без skip/ослабления финансовых и auth-инвариантов. [Очищенная выдержка предыдущего лога](evidence/2026-10-31/previous-commercial-failures.json).

### A07 · P1 · Браузерная приёмка FRONTEND-05/06 отсутствует даже в runner

[e2e-account-workspace.mjs](../../apps/frontend/scripts/e2e-account-workspace.mjs) проверяет login, создание аккаунтов, overview/members read, переключение, redirect, темы, overflow и cross-user denial. **В нём нет сценариев MFA enrollment/step-up, изменения/удаления membership, приглашений и почтового lifecycle.**

CI запускает только `--check-guard`. Он подтверждает отказ без настроенного runtime и не открывает эти пользовательские сценарии. Ни один job не поднимает полный изолированный frontend+Commercial+Mailpit browser runtime. Публичный responsive smoke также не включён в CI.

**Исправить:** отдельный disposable CI browser job с реальными workers, почтой и БД; расширить сценарии до приглашения → письмо → preview → login/MFA → accept/decline, resend/старый token, revoke, роли и last owner. Изоляцию обеспечить инфраструктурой, а не только строкой `E2E_ISOLATION_PROOF` длиной 32 символа. **Приёмка:** успешный полный browser job на SHA исправлений, screenshots/traces как artifacts.

### A08 · P1 · Рабочая установка не соответствует исходникам

Commercial работает из ранее собранного образа, без bind mount исходников. SHA-256 `security.yaml` и `MembershipService.php` внутри контейнера отличаются от рабочего дерева. `/health/live` и `/health/ready` сейчас дают 200, но это проверка **другого кода**. Успешный локальный catalog GET не доказывает работоспособность HEAD.

Кроме того, рабочая БД имеет 40/41 Commercial migrations, invitation-таблицы нет. **Исправить после зелёного CI:** отдельный контролируемый build/migration/deployment с проверкой revision и сохранности данных. Не применять новую миграцию автоматически в рамках аудита. См. матрицу БД ниже.

### A09 · P1 для production · Runtime-роли PostgreSQL имеют административные права

У `platform_app` и `platform_control` подтверждены **SUPERUSER, CREATEDB, CREATEROLE, BYPASSRLS**. Это соответствует bootstrap-подходу локального Compose, но не production runtime. Нулевое число SQL ошибок и успешные миграции не доказывают least privilege.

**Исправить перед production:** отдельные owner/migrator/runtime roles; повторить приложение, migrations и privilege-denial tests на изолированной копии. **Приёмка:** runtime не может DDL, менять историю миграций, повышать привилегии или читать server files; бизнес-операции работают. [Core](evidence/2026-10-31/core-db-readonly.txt), [Commercial](evidence/2026-10-31/commercial-db-readonly.txt).

### A10 · P1 по объёму frontend · Экран SVG не реализован

Канонический [мобайл.svg](../../apps/frontend/design/мобайл.svg) — **393×852**, учебный экран «Сегодня». В router есть Commercial/auth/security/invitations; Education routes и соответствующих экранов нет. Это разница между текущим объёмом реализации и макетом, а не повод переносить учебные данные в Commercial или добавлять фиктивные API.

| Элемент SVG | Фактическое состояние |
| --- | --- |
| Приветствие, дата, уведомления, avatar | Нет целевого dashboard; общий header другого назначения |
| Неделя и выбор даты | Не реализованы |
| Следующее занятие, действия «Открыть / Список / Материалы» | Не реализованы |
| Timeline «Сегодня», занятия, день рождения, созвон | Не реализован |
| Шесть быстрых действий | Не реализованы |
| «Требует внимания» | Не реализовано |
| «Сегодня / Календарь / Люди / Сообщения / Ещё» | В приложении три других раздела: тарифы/кабинет/безопасность |
| Светлая палитра, базовый фон, радиусы, Inter | Частично перенесены через tokens/UI kit |
| Нижняя панель SVG | Высота 72px учтена; форма, число пунктов, иконки и active state отличаются |

**Доказательства:** [отрисованный SVG](evidence/2026-10-31/design-mobile.png), [реальный каталог](evidence/2026-10-31/catalog-393.png), [login](evidence/2026-10-31/login-light-393.png), [router](../../apps/frontend/src/app/router/index.ts).

**Приёмка:** отдельно зафиксировать план Commercial и будущего Education; для каждого блока SVG указать route, реальный API, владельца данных и критерий визуальной проверки. Не объявлять весь макет учтённым по одному `check:tokens`.

### A11 · P1 по требованию автономности · Локальные шрифты не подключены

[index.html](../../apps/frontend/index.html), строки 19–24, содержит Google preconnect и внешний CSS Inter. `@font-face` для скачанных файлов нет. Production build вообще не выпускает font assets.

В `src/shared/fonts` уже есть Inter, Roboto и Space Mono: **116 TTF**, OFL/README, суммарно около **30,7 MB**. В реальном браузере зафиксированы запросы к `fonts.googleapis.com` и `fonts.gstatic.com`; при их блокировке `document.fonts` пуст, используется системная подстановка. Скачивание файлов само по себе зависимость не устранило.

**Исправить:** подключить нужные local Inter weights/variable через `@font-face`, `font-display: swap`; удалить Google links; проверить RU/EN glyphs и все используемые веса. При необходимости подготовить WOFF2. Roboto не равен упомянутому в monospace fallback `Roboto Mono`; для mono выбрать имеющийся Space Mono либо другой явно согласованный local font. Сохранить OFL для используемых семейств. Не включать все статические дубли в bundle.

**Приёмка:** собранное приложение при заблокированных Google-доменах загружает свои font assets, использует Inter для RU/EN и не делает внешних font requests. [Сетевая проверка](evidence/2026-10-31/browser-fonts.json).

### A12 · P2 · Генератор токенов переносит не весь JSON

[generate-tokens.mjs](../../apps/frontend/scripts/generate-tokens.mjs) читает Spacing и Radius. `Number Variables.Gap` и `Padding` не экспортируются; `Size` и page padding повторены литералами в самом генераторе. Typography переносит size/line/weight/tracking, но игнорирует `fontFamily`. Сейчас некоторые значения совпадают, однако изменение канонического JSON для этих групп не обновит CSS.

**Исправить:** генерировать соответствующие значения из JSON, связать компоненты с ними; отдельно отметить сознательные layout exceptions. **Приёмка:** изменение каждого поддерживаемого семейства токенов меняет ожидаемый CSS, `check:tokens` обнаруживает drift. Текущий PASS означает лишь совпадение CSS с неполным генератором.

### A13 · P2 · После успешного decline показывается отсутствие ссылки

[useInvitationAcceptance](../../apps/frontend/src/modules/commercial/invitations/useInvitationAcceptance.ts) очищает token и ставит `declined=true`. В [InvitationAcceptView](../../apps/frontend/src/modules/commercial/invitations/InvitationAcceptView.vue), строки 125–137, `!hasToken` проверяется раньше `declined`. Поэтому success-state недостижим: экран просит снова открыть письмо.

Воспроизведено в Chrome на контролируемых API-фикстурах, без backend mutations. **Исправить:** terminal success-state должен иметь приоритет над missing-token. **Приёмка:** после успешного decline виден подтверждённый отказ, после обычного открытия без token — missing-link. [Результат](evidence/2026-10-31/invitation-decline-fixture.json), [скриншот](evidence/2026-10-31/invitation-decline-fixture.png).

### A14 · P2 · Invitation token endpoints выпали из sensitive cache policy

[SecurityBaselineSubscriber](../../apps/control-plane/src/Infrastructure/Http/SecurityBaselineSubscriber.php), строка 18: regex включает auth/accounts/privacy/m2m, но не `/api/control/invitations/*`. Прямая проверка subscriber подтверждает отсутствие `no-store` для preview/accept/decline; управление приглашениями внутри accounts его получает. Это не доказательство утечки через cache, но явный разрыв политики чувствительных ответов.

**Исправить:** включить token endpoints в согласованную sensitive policy. **Приёмка:** HTTP tests проверяют no-store на каждом invitation response, включая ошибки. [Проверка](evidence/2026-10-31/invitation-cache-policy.txt).

### A15 · P2 · Invitation cleanup учитывает hold только отправителя

[ControlPrivacyCleanup](../../apps/control-plane/src/Privacy/ControlPrivacyCleanup.php), строка 33: invitation eligibility исключает `privacy_holds` только по `invited_by`. Получатель, `accepted_by` и зарегистрированный пользователь с совпадающим email не проверяются. Экспорт при этом связывает приглашение со всеми этими субъектами.

**Исправить:** согласовать subject mapping cleanup с export/erasure и запретить очистку при hold любого относящегося субъекта. **Приёмка:** isolated DB cases для hold inviter/recipient/accepted_by и отсутствия hold. Это вывод из SQL; фактическое удаление на рабочей БД не запускалось.

## 4. Базы данных: фактический результат

| Показатель | Core/runtime | Commercial |
| --- | --- | --- |
| PostgreSQL | 18.6 | 18.6 |
| Рабочая БД | `platform` | `platform_commercial` |
| Физические domain tables | 50 Core + 3 runtime | 60 |
| Таблиц в сохранённом snapshot | 53 | 61 |
| Применено / файлов миграций | 33 / 33 | 40 / 41 |
| Последняя применённая | `033_reconcile_legacy_data_hygiene.sql` | `Version20261023000100` |
| Pending | Нет | `Version20261031000100` |
| Неизвестные миграции в истории | Нет | Нет |
| Invalid/not-ready indexes | 0 | 0 |
| NOT VALID constraints | 0 | 0 |
| Различия существующих таблиц со snapshot | Нет | Нет; дополнительно в snapshot есть invitations |
| Функции и enum совпадают со snapshot | Да | Да |
| Штатный Core `yii db:check` | PASS | Не применяется |

Итого на рабочих БД **113 domain tables**, в документации после добавления invitations — **114**. Catalog comparison не является проверкой всех бизнес-операций или содержимого строк. История миграций и результаты: [Core](evidence/2026-10-31/core-migrations.json), [Commercial](evidence/2026-10-31/commercial-migrations.json), [сравнение каталога](evidence/2026-10-31/db-catalog-comparison.json), [db:check](evidence/2026-10-31/core-db-check.txt).

Новая Commercial migration не прошла чистое применение/up-down-up в текущем CI из-за A01. На рабочую БД в ходе аудита она не применялась.

## 5. Локальные проверки сверх CI

| Проверка | Результат |
| --- | --- |
| Frontend `npm ci`, tokens, typecheck, lint | PASS |
| Frontend Vitest | **108 / 108**, 26 файлов |
| Frontend production build | PASS; JS 318,47 kB / gzip 106,47 kB, CSS 61,17 kB; fonts отсутствуют |
| Frontend `npm audit` | 0 advisories |
| Commercial unit | **131 тест / 543 assertions**, PASS |
| Commercial PHPStan level 7 | PASS, 0 errors |
| Core Psalm | PASS, 0 errors |
| PHP syntax src/config/tests/migrations выбранных приложений | **1102 файла**, 0 ошибок |
| Composer validate обоих приложений | PASS |
| Composer audit обоих приложений | 0 advisories / abandoned packages |
| Core snapshot, reference, coverage, links | PASS; до добавления отчёта 390 links / 48 Markdown files |
| Commercial snapshot source check | **FAIL**, A02 |
| DBML | PASS: Core 50/86 FK, runtime 3/1 FK, Commercial 61/97 FK |
| Pyright | 0 errors / warnings |
| PowerShell syntax | PASS |
| Combined Compose config с временными CI-required secrets | PASS; без запуска сервисов |
| Chrome responsive smoke | PASS: 11 routes × 9 widths 320–1440, guards, overflow, themes, keyboard, RU |
| Public health/readiness существующих API | HTTP 200; Commercial относится к старому image, A08 |

Материалы: [frontend tests](evidence/2026-10-31/frontend-test.txt), [build](evidence/2026-10-31/frontend-build.txt), [responsive](evidence/2026-10-31/browser-responsive.txt), [Commercial unit](evidence/2026-10-31/commercial-unit.txt), [PHPStan](evidence/2026-10-31/commercial-phpstan.txt), [Psalm](evidence/2026-10-31/core-psalm.txt), [PHP syntax](evidence/2026-10-31/php-syntax.json), [DBML](evidence/2026-10-31/dbml.txt), [Pyright](evidence/2026-10-31/pyright.txt), [public HTTP](evidence/2026-10-31/runtime-public-http.json).

Отдельно об окружении: native Windows PHP из PATH не загружает `pdo_pgsql`, поэтому обычный `composer check-platform-reqs` локально возвращает exit 2. В Linux Core container и CI расширение есть. Для PHPStan использован явный `php -d extension=pdo_pgsql vendor/bin/phpstan analyse --no-progress`; обычный Composer script на этом установленном vendor не нашёл Windows wrapper. Это не причины падения GitHub CI. В Chrome/HTTP окружение AdGuard добавляет свои запросы/заголовки; они не приписаны приложению.

## 6. Документация: что удалить и что исправить

Проверены 47 Markdown-файлов `docs` и корневой README; дополнительно оба frontend README. Массовое удаление не требуется: большая часть файлов фиксирует действующие контракты и операторские процедуры.

| Документ | Решение | Причина и условие |
| --- | --- | --- |
| [architecture/PROJECT_TREE.md](../architecture/PROJECT_TREE.md) | **Удалить после переноса** | 19 строк дублируют entrypoint; frontend вообще отсутствует. Поместить актуальную карту в root README, обновить ссылки |
| [database/core/README.md](../database/core/README.md) | **Удалить после объединения** | 5 строк навигации; ссылки и сведения перенести в `docs/database/README.md` |
| [architecture/INTERNATIONAL_SECURITY_CORE.md](../architecture/INTERNATIONAL_SECURITY_CORE.md) | **Удалить как отдельный исторический документ после переноса уникального содержания** | Смешивает этап migration 010 и текущие правила; устаревшие формулировки о login/Redis. Сохранить уникальные правила ClientContext/proxy/device/time/GeoIP в действующих INTERNATIONALIZATION/SECURITY_HARDENING/AUTHENTICATION_AUTHORIZATION |
| [apps/frontend/README.md](../../apps/frontend/README.md) | **Сократить, оставить entrypoint** | Повторяет большую часть `docs/frontend/README.md`; оставить команды и ссылку на единое руководство |
| [docs/frontend/README.md](../frontend/README.md) | **Исправить** | «members только просмотр» и «приглашений по email нет» противоречат текущему коду; module tree не включает security/invitations |
| [PRODUCTION_READINESS_PROGRESS.md](../operations/PRODUCTION_READINESS_PROGRESS.md) | **Исправить, не удалять** | В PASS-матрице нет SHA/даты/run links; наличие gate местами смешано с его прохождением. Добавить реальные результаты и blocked проверки текущего коммита |
| Commercial catalog/DBML/reference | **Перегенерировать, не удалять** | A02; источник — реально мигрированная изолированная БД |

**Удалять без переноса уникального содержания: ничего.** Удаление трёх кандидатов требует обновления входящих ссылок и финального `check-links.py`.

**Оставить:** CORE_INVARIANTS и core-data-model; актуальные архитектурные границы; OpenAPI/events; database catalogs/DBML/reference/coverage; процессы `.mmd` и их `.svg`; операции backup/restore/provisioning/compensation/upgrade; privacy policy/inventory/readiness документы; AUTHENTICATION_AND_MFA; CHARTS_CONTRACT как явно будущий контракт. Generated source и generated view выполняют разные задачи и не считаются ненужными дублями.

Не обнаружен сохранённый пошаговый FRONTEND-01…07 roadmap с критериями приёмки; исходная матрица E01–E20 также прямо указана как отсутствующая в репозитории. **Перед продолжением нужен один план**, где Commercial и Education разделены, каждый этап ссылается на API/макет/тесты, а результат содержит SHA и run URL. Не создавать новый отчёт на каждый мелкий шаг вместо обновления этого плана.

Ассеты отдельно: в незакоммиченной `Solar icons/` сейчас **8280 SVG / около 12,1 MB**, тогда как приложение импортирует `@lucide/vue`. XML-разбор всех файлов прошёл; script/foreignObject/event attributes и внешних ссылок не обнаружено ([результат](evidence/2026-10-31/local-icon-pack.json)). Наличие каталога ещё не означает подключение иконок макета. Для следующего UI-этапа выбрать единый согласованный набор и provenance/license, подключать только используемые файлы. Не удалять пользовательский pack автоматически. OFL шрифтов не удалять вместе с «лишними документами».

## 7. Чёткий порядок дальнейшей работы

| Шаг | Работа | Условие завершения |
| --- | --- | --- |
| 1 | A01: восстановить YAML, добавить ранние config gates | test/prod Kernel загружается; lint проходит |
| 2 | A03–A05: audit contract, replay authorization, invitation env | Негативные/позитивные invitation+membership tests на реальной изолированной PostgreSQL |
| 3 | A06 и privacy/cache/UI defects A13–A15 | Полный Commercial suite без failures/errors; component/HTTP/hold cases |
| 4 | Миграции и A02 | Empty migrate, repeat, previous→latest→previous→latest; экспорт реального каталога; docs CI PASS |
| 5 | Полный CI на исправленном SHA | **6/6 jobs PASS**; Commercial image и Trivy реально исполнены |
| 6 | A07: полный browser runtime и недостающие сценарии | Реальные invitation/MFA/membership flows PASS, artifacts привязаны к SHA |
| 7 | A11–A12: local fonts и полный token mapping | Нет Google requests; RU/EN/weights; tokens и build PASS |
| 8 | Обновить roadmap и документацию, выполнить согласованную чистку | Нет устаревших утверждений/битых ссылок, единый план |
| 9 | Отдельно обновить рабочую установку | Образ и БД соответствуют release SHA, данные сохранены, smoke по новой версии |
| 10 | Начинать Education по SVG | У каждого блока есть API/данные/состояния и визуальная приёмка, без mock runtime |

Шаги 5–6 нельзя заменить локальными unit-тестами или guard PASS. Удаление документов не лечит CI. Рабочую БД нельзя использовать как тестовую и сбрасывать ради зелёной проверки.

## 8. Что остаётся неподтверждённым

- Текущие Commercial integration/concurrency/P0 regression tests, invitation миграция и rollback, strict cross-plane: блокируются A01; написанный тест не равен выполненному.
- Полный браузерный invitation/MFA/membership E2E и внешний SMTP delivery; наличие outbox event не равно доставке письма.
- Новая SMTP egress purpose `control_account_invitation` и retention category `invitation` требуют заполненной операторской конфигурации; их нельзя автоматически «одобрить» для прохождения readiness.
- Production least privilege, DR/restore/PITR/HA, TLS/DNS, provider sandbox, целевая нагрузка и SLO. Историческая неудача на 10 000 VU остаётся в readiness-документе; в этом аудите нагрузка заново не запускалась.
- Backup/deployment test scripts существуют, но не подключены к текущему workflow и требуют подготовленных fixtures. Они не считаются пройденными по факту наличия файлов. Core image не имеет отдельного Trivy gate; Commercial Trivy в этом run был skipped.
- Полная приёмка учебного dashboard по SVG. Проверенные публичные/auth поверхности — другая часть приложения.

**Результат аудита — воспроизводимый список причин и порядок исправлений. Подтверждения готовности всего продукта к production нет.**

Финальная проверка самого отчёта: `check-links.py`, `build-reference.py --check`, `build-coverage.py --check` и `git diff --check` прошли. JSON evidence проверены на корректность; текстовые материалы сохранены в UTF-8. Application source diff отсутствует.
