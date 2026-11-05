# Промпт для агента: доведение Platform после полного аудита

Дата проверки: **5 ноября 2026**. Базовая ревизия: `12634f4`; текущие изменения находятся
в рабочем дереве. Исходный аудит PHP/CI начинался с `6fcb7ad`. Ниже зафиксированы
сделанные исправления, проверенные сценарии и оставшийся порядок работ.

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
- Новое указание пользователя: **не делать коммиты и push без его явного разрешения**.
  Прежнее разрешение больше не действует. Текущие изменения проверяются локально.
- Обновляй этот MD. Не создавай новые датированные аудиты, агентские brief и дублирующие отчёты.

## 2. Что исправлено в этом проходе

### Этап 15: периодический Billing и оставшиеся security findings

Продолжение 5 ноября 2026; commit/push и GitHub Actions не выполнялись. Пользователь явно
отложил live OAuth/YooKassa до появления credentials. Общий verdict: **NOT_READY**.
Матрица HTTP coverage не менялась: **229 = Core 146 + Commercial 83; CODE 215 / SERVICE 14 /
GENERIC 0 / missing 0**. Это source coverage, а не production-приёмка всех сценариев.

**Исправлены два реальных deadlock пути**, обнаруженные при одновременных scheduler/webhook:

1. `RecordSavedPaymentMethod` блокировал attempt и payment через JOIN в порядке, обратном
   binder/settlement. Теперь `FOR UPDATE OF a` блокирует только attempt; immutable ownership,
   provider/account advisory locks и ограничения сохранены. Двухсоединительный тест до
   исправления завершался `55P03` на payment; после проходит, карта сохраняется один раз.
2. `ApplyAuthoritativeReceipt` при двух UPDATE одного чека повторно проверяет FK; PostgreSQL
   захватывал payment перед invoice, а settlement — invoice перед payment. Теперь invoice
   берётся `FOR KEY SHARE` до чека. Отдельный процесс реально ждёт invoice; владелец invoice
   способен захватить payment, завершиться и пропустить fiscalization. До исправления тест
   воспроизводил `55P03`, после проходит. Суммы, provider reference, terminal status, immutable
   snapshot, audit/outbox и проверки provider response не ослаблены.

Тесты находятся в `PaymentConcurrencyTest.php` и `race-worker.php`. Их committed providers
теперь обязательно выключаются в teardown: первый полный прогон выявил загрязнение fixture
merchant и каскадные ошибки ambiguous provider. Это дефект нового теста, а не повод ослаблять
production locator. Для окончательного прогона нужна свежая пустая test БД.

**Периодический browser Billing:** `npm run test:e2e:periodic-billing`, **3 группы PASS** на
настоящих HTTP/PG/SMTP/MFA, scheduler, gateways и финансовых constraints. Внешний provider
wire явно синтетический. Пройдены: платный downgrade на границе периода; автоматическое
продление → decline → grace access → backoff → второй attempt на одном payment → authoritative
reconciliation; длительный provider outage → исчерпание grace → expiry → поздний settlement
с дубликатом webhook без восстановления доступа. Хэши исходных invoice не менялись.
После исчезновения активной подписки UI объясняет состояние и сохранённую историю на RU/EN.

`compose.billing-clock.yaml` opt-in; `infra/testing/clock.*` наследует обычные test images.
Общий readonly offset-файл изменяет только wall clock тестовых PHP/PG; host clock, рабочие
сервисы, финансовая история и production Dockerfiles не меняются. Проверены отказ entrypoint
по prod env, isolation=0, короткому nonce и рабочему DB URL — **4 отказа exit 126**. Пробы PHP/PG
согласованы; monotonic time не скачет. CLI запускается через тот же entrypoint. Перед сменой
файла оба читателя приостанавливаются. Это не доказательство поведения всех real-time таймеров
PG/Go: при скачке wall time автоматический PG deadlock timeout не сработал; lock-order regression
проверяется отдельно при offset=0 с ограниченным lock_timeout.

Добавлена CI job **Billing · renewal and recovery**, отдельный project `platform-billing-ci`,
случайные test secrets, пустая БД, HTTPS production preview, artifact upload и cleanup.
`scripts/ci/billing-clock-start.sh` собирает обычные образы перед clock extensions и проверяет
четыре отказа entrypoint. Workflow/actionlint и shell syntax проверены локально; нового GitHub
run нет. Изменения остаются в рабочем дереве.

**Security outcome `fixed`: frontend glob/minimatch.** Было 14 MODERATE в dev chains.
Scoped overrides обновляют затронутые minimatch до **11.0.1**, glob до **14.0.2**, сохраняя
callable CommonJS minimatch 3.1.5 для ESLint. Blanket override или `npm audit fix --force` не
применялись. До patch: amplification исчерпывал 128 MB, traversal выполнял ещё 30 reads после
abort. После: два focused tests PASS, amplification и альтернативное brace expansion bounded,
0 reads после abort; обычный matching/traversal, Vue tooling и lint работают; **npm audit 0**.
Изменены package.json/lock, добавлен `security-dependencies.test.mjs` и CI gate.
Источники: [minimatch advisory](https://github.com/advisories/GHSA-g3hv-75wg-m249),
[glob advisory](https://github.com/advisories/GHSA-3g3m-2fjr-6pw4).

**Security outcome `blocked`: libxml2 / CVE-2026-6653.** Свежий scan сохраняет CRITICAL;
Debian trixie `2.12.7+dfsg+really2.9.14-2.1+deb13u3` по-прежнему vulnerable. Debian отмечает
no-dsa/minor, но это не основание скрыть finding. Исправление в upstream меняет entity/parser
структуры; готового совместимого обновления trixie не установлено. Смешивать sid/Ubuntu .deb
или другую SONAME без проверки ABI нельзя. Отсутствие явного XML parser call в src не доказывает
недостижимость через зависимости/PostgreSQL. Решение: совместимый distro backport либо
проверенная миграция base image с PHP extensions/PG/XML regression. Риск не принят и не скрыт.
Источники: [Debian tracker](https://security-tracker.debian.org/tracker/CVE-2026-6653),
[upstream patch](https://github.com/GNOME/libxml2/commit/463bbeeca1805b5c4828f50d0fefc4eebaf620df).

Свежий Trivy inventory всех шести Platform images (БД сканера обновлена в этом проходе):

| Image | CRITICAL | HIGH | HIGH/CRITICAL с доступным FixedVersion |
| --- | ---: | ---: | ---: |
| Core dev | 1 | 81 | 0 |
| Core prod | 1 | 60 | 0 |
| Commercial, новый `2f064842dc8a` | 1 | 60 | 0 |
| PostgreSQL | 1 | 63 | 0 |
| Redis | 0 | 0 | 0 |
| Mailpit | 0 | 0 | 0 |

Нулевой fixable count не означает безопасность; UNKNOWN findings также сохранены в JSON.
Security evidence хранится через Codex Security artifact storage вне репозитория:
`C:\Users\Yuran\.codex\state\plugins\codex-security\scans\Platform\artifacts-ab2770cbc283f21f32f548ba352f7e16ca6799138a9831af98b61d68a3b586df`.
В `artifacts/dependency-validation` — before/after/final и отдельный ручной candidate review;
шесть `*-image.json` содержат точные digests и findings. Делегированный investigator стал
недоступен; отдельная проверка bypass/совместимости выполнена вручную. Suppressions не добавлены.

Проверки текущего этапа (предыдущие результаты этапа 14 ниже не заменяют их):

| Проверка | Свежий результат |
| --- | --- |
| Commercial Linux PHPUnit, пустая test БД, provider fixture off | **468 tests / 5027 assertions PASS** |
| PHPStan level 7; Symfony YAML/container lint | **PASS / 0 errors** |
| Frontend Vitest | **360 tests / 70 files PASS** |
| Build / typecheck / ESLint / Prettier / styles / tokens / icons | **PASS**, sprite 30 symbols |
| Dependency security regression / npm audit | **2 tests PASS / 0 vulnerabilities** |
| Core guards / source contracts / RU-EN labels | **13 tests PASS** |
| Billing UI fixture / responsive + axe | **6 groups PASS**, 393/1440 px |
| Workflow actionlint / shell syntax / Compose / database tooling | **PASS**, Python 3 tests |
| Documentation links | **888 links / 50 MD PASS** |

Полный Commercial запуск: `docker exec -e CONTROL_BROWSER_PAYMENT_FIXTURE=0
-e CONTROL_RENEWAL_LEAD_DAYS=7 -e CONTROL_SCHEDULER_ENABLED=false <isolated-app>
vendor/bin/phpunit`. Для него clock offset=0; ускоренные scheduler settings не используются.
Новый полный Core/cross-plane suite и все прежние browser suites этим этапом не повторялись:
Core source/schema не менялись. Нагрузочная production capacity этим прогоном не измерялась.
Стартовый JS **636.46 kB**, gzip **192.35 kB**; warning >500 kB остаётся открытым.

Новый Commercial image **`2f064842dc8a`** применён к HTTP, scheduler и outbox worker;
все три healthy. Полные environment/mount hashes до/после совпали, test/clock boundary в
production image отсутствует. Commercial live/ready, Core health и frontend 5173 — **200**.
Рабочие credentials/пользователи не менялись. Остальные Platform/MyBots images не пересоздавались.

Финальный periodic E2E повторно **3 группы PASS** после UI изменений; в БД подтверждены
ровно два usage periods после одного продления. Осмотрены снимки:
[downgrade](evidence/current/billing-periodic-downgrade.png),
[продление после отказа](evidence/current/billing-periodic-recovered.png),
[истёкший доступ](evidence/current/billing-periodic-expired.png),
[телефон, RU](evidence/current/billing-periodic-expired-mobile-ru.png).
На 393 px отсутствует горизонтальный overflow страницы; весь длинный таббар прокручивается
отдельно. UI fixture regression также прошёл после добавления пояснения об inactive access.
Полные PHPUnit/periodic логи и results JSON сохранены вне проекта:
`C:\Users\Yuran\AppData\Local\Platform\verification\20261105-periodic-billing.zip`,
SHA256 `0fb5522fc7d488552d1f9a051a1bcdd44f9234f49338aa37bfaa52e6718bd75b`.

Очистка Docker завершена: удалены **3 тестовых контейнера, 1 том, 2 сети, 3 test images и
actionlint image**; cache освобождён на **1.699 GB**. Итог daemon: **13 контейнеров
(12 running, MyBots app Created), 8/8 images used, 4/4 рабочих тома, build cache 0 B**.
Preview 5196 остановлен; frontend 5173 работает. Рабочие данные и MyBots сохранены.

**Файловая очистка заблокирована:** автоматическая проверка снова отклонила конкретный
`Remove-Item` нового проверенного временного каталога с причиной `blocked by policy`,
без дополнительного объяснения. Обход через другой инструмент не выполнялся. Остались
`apps/frontend/screenshots` (**102 файла**), прежний `platform-acceptance-12634f4` (**85**) и
новый `platform-billing-12634f4` (**15**). Новый каталог содержит случайный test.env/TLS key;
прежний содержит также снимок рабочего env — не публиковать/не добавлять в Git. Сохранённые
screenshots в docs/evidence/current и архивы не удалять. После просмотра evidence очистить вручную:

```powershell
Remove-Item -LiteralPath 'C:\Platform\apps\frontend\screenshots' -Recurse -Force
Remove-Item -LiteralPath 'C:\Users\Yuran\AppData\Local\Temp\platform-acceptance-12634f4' -Recurse -Force
Remove-Item -LiteralPath 'C:\Users\Yuran\AppData\Local\Temp\platform-billing-12634f4' -Recurse -Force
```

### Этап 14: предыдущая приёмка операторов, платного Billing и мобильных компонентов

Выполнено локально, без commit/push и без запуска GitHub Actions. Production verdict
остаётся **NOT_READY** по открытым условиям ниже. Source coverage не изменилось:
**229 HTTP operations = Core 146 + Commercial 83; CODE 215 / SERVICE 14 / GENERIC 0 / missing 0**.

- **Два независимых Commercial оператора:** `e2e-commercial-operators.mjs` проходит
  настоящие HTTP/PG/SMTP/MFA. Через интерфейс выполнены все девять operator actions:
  intake/resolve, incident detect/event/close, current/propose/approve/reject компенсации.
  Проверены просроченная recent authentication с сохранением evidence и явным повторным
  submit после step-up, запрет self-review, несовпадающий hash, reject/correct/repropose,
  независимый approve, отзыв финансовых прав при открытой форме. После отзыва клиент
  перечитывает capabilities, закрывает форму и очищает чувствительные данные; privacy
  продолжает работать. Подтверждение повторно не создаёт provider refund.
  Начальный recovery case и внешние evidence URN явно синтетические; реальный банковский
  перевод не выполнялся. Права рабочих пользователей не менялись.
- **Платный Billing:** `e2e-paid-billing.mjs`, **6 групп PASS**. Платный checkout с quantity,
  точные invoice/payment DTO и суммы, отсутствие раннего entitlement, повтор idempotency key,
  вторая вкладка, reload/login, authoritative webhook с повторами и перестановкой событий,
  чек, карта, платный upgrade, неизменная история, scheduled downgrade/cancel, частичный
  плюс остаточный refund, запрет over-refund. Через реальные gateways проверены uncertain
  create и same-key recovery, decline и waiting-for-capture; доступ появляется только после
  settlement. Физическая смена периода в браузере не ускорялась.
- **Граница provider fixture:** только `services_test.yaml`, CLI fixture и `compose.browser.yaml`
  для одноразового browser CI. Обязательны test environment, isolation flag, длинный nonce и
  фактическая БД `platform_commercial_test`. HTTP API, gateways, services и constraints настоящие;
  подменяется только внешний provider wire. Неизвестные хосты/пути отклоняются без сети.
  Production image не содержит tests/fixture classes; проверка отсутствия выполнена.
  Полный Commercial suite запускается с fixture **выключенным**.
- **Исправлены два реальных дефекта платного сценария:** nullable `SavedPaymentMethod.title`
  соответствует OpenAPI и получает понятную подпись вместо падения всей Billing страницы;
  `RecordSavedPaymentMethod` явно привязывает boolean, поэтому вторая карта больше не
  вызывает PostgreSQL `invalid input syntax for type boolean: ""` / HTTP 500.
  Регрессии проверяют nullable DTO, malformed DTO, вторую карту, один default и повтор snapshot.
- **Мобильный UI:** body 13/18, card heading 15/20, button M 12/16 через canonical Mobile tokens;
  карточки и видимые кнопки компактнее, touch target общих кнопок сохраняется 44 px.
  Поля ввода остаются 16 px при обычном масштабе, чтобы не вызывать iOS focus zoom.
  Все четыре native disclosure заменены общим `AppDisclosure` с локальным SVG chevron,
  aria-expanded, клавиатурой, inert содержимым и motion variables.
- **Шапки и модалки:** небольшие `AppBreadcrumbs` вместо крупной подписи в шапке;
  промежуточные уровни скрываются за `…`, доступны в общем меню, UUID в подписях не показываются.
  Общий `AppDialog` задаёт header, toolbar, прокручиваемый body и footer. JSON-ответы используют
  тот же fullscreen компонент. Статус скачивания рядом с кнопкой выровнен вертикально;
  браузерная проверка геометрии и скриншот выполнены после скачивания.
- **Календарь и время:** `AppTemporalPicker` автоматически подключён через существующий
  `AppInput` для date/time/datetime-local, включая операторские формы. Локальные SVG,
  RU/EN, ручной ввод и ISO model, месяцы/годы/часы/минуты/секунды через общий dropdown,
  min/max/step, disabled/readonly, стрелки/Home/End/PageUp/PageDown, Clear/Today,
  корректное вложение в модалку и Escape без закрытия родителя. Невозможное местное время
  в DST gap отклоняется; преобразование в UTC остаётся обязанностью формы/API boundary.
  Примеры — `/settings/components`.
- **Меню:** при responsive замене cascade/drill сохраняется клавиатурный фокус;
  неподвижный курсор после resize не переключает открытую ветку. Полный menu verifier PASS.
- **CI:** новые paid/operator E2E и mobile components verifier подключены в browser job;
  перед mutating группами отдельный reset одноразовых fixtures. Исправлен тестовый WebAuthn
  origin: numeric loopback заменяется на localhost, поскольку numeric RP ID Chromium отвергает.
  Mutation guards не ослаблены; рабочий hosts файл не менялся.

Текущие проверки:

| Проверка | Результат и граница |
| --- | --- |
| Commercial Linux PHPUnit | **465 tests / 5001 assertions PASS**, fixture выключен |
| PHPStan level 7 | **0 errors** |
| Frontend Vitest | **360 tests / 70 files PASS** |
| Build / TypeScript / ESLint / Prettier | **PASS**; стартовый JS **635.69 kB**, gzip 192.18 kB — warning остаётся |
| Tokens / styles / local icons | **PASS**, sprite **30 symbols** |
| npm audit, CI threshold high | **PASS: 0 HIGH/CRITICAL**, но **14 MODERATE** в dev dependency chains glob/minimatch; dependency audit не полностью чистый |
| Node guards / source contracts / translations | **13 tests PASS**, включая 53 Core операции и RU/EN labels |
| Mobile calendar/time/dialog | **6 RU/EN states PASS**, 320/393/1440; DST gap rejected; axe A/AA |
| Typography | **50 routes / 408 states / 8101 text checks PASS**, два локальных семейства шрифтов |
| Workflows | **64 fixture states PASS**, 53 Core + 10 Commercial bindings |
| Admin / auth / security / profile / credentials / privacy / configuration | **PASS**; actual virtual authenticator в credentials verifier |
| Appearance / controls / UX / menus / responsive | **PASS**; 32 controls assertions, 30 interaction и 10 dialog cycles |
| Accessibility | **30 states**, zero automated WCAG A/AA violations; это не ручной аудит всех assistive technologies |
| Real paid / two operators | **6 / 4 группы PASS**, явно синтетический внешний платёжный wire |
| Local authenticated load smoke | **106 + 528 requests**, concurrency 1/5, ошибок 0, p95 **60/65 ms**, worst route p95 71/73 ms; не production capacity |
| Workflow lint / DB catalogs / references / links | **PASS**; Core 53 tables, Commercial 61 tables, 884 local links; Python role tooling 3 tests |

Core backend code этим этапом не менялся; результат **540 tests / 14212 assertions** ниже
относится к предыдущему полному прогону. Новый полный Core suite, cross-plane и прежние
mutating Core E2E не запускались заново. Все перечисленные frontend fixture suites свежие;
они не подменяют проверку живых OAuth/payment providers.

Снимки текущего результата:
[MFA на телефоне](evidence/current/core-security-mfa-mobile.png),
[операторский интерфейс](evidence/current/workflows-commercial-mobile.png),
[календарь](evidence/current/mobile-calendar-ru.png),
[дата и время](evidence/current/mobile-datetime-ru.png),
[единая модалка](evidence/current/mobile-dialog-en.png),
[ответ и статус скачивания](evidence/current/core-privacy-response-download.png),
[платный Billing](evidence/current/billing-paid-active.png),
[два оператора](evidence/current/operators-real-finance.png).

Образы Core dev/prod, Commercial и PostgreSQL пересобраны и применены к девяти рабочим
consumers; все 11 Platform контейнеров healthy. Environment и mounts этих девяти сервисов
сравнены до/после и совпадают. Core accounts **2**, Commercial control users **6** сохранены;
пароли, MFA и роли рабочих аккаунтов не менялись. Health/live + ready 8080/8082/8090,
каталог Commercial и frontend 5173 — **HTTP 200**. PostgreSQL остаётся **18.6-1.pgdg13+2**,
PHP — **8.3.36 ZTS**. Точные image IDs и свежий inventory приведены ниже.

Одноразовый стенд удалён: **3 контейнера, 1 том, 2 сети, test image и actionlint image**;
освобождён build cache **3.321 GB**. В daemon осталось **13 контейнеров (12 running,
прежний MyBots app Created), 8/8 images used, 4/4 рабочих тома, cache 0 B**.
MyBots сохранил ID/image/mounts; его PostgreSQL StartedAt между начальным snapshot и
итогом изменился с `2026-11-02T11:12:20Z` на `2026-11-04T15:54:55Z`, ещё до текущего
пересоздания Platform. Причина этого перезапуска не установлена; утверждать неизменность
времени запуска нельзя. Контейнер MyBots app остался Created.

Логи и JSON текущих локальных проверок сохранены вне проекта:
`C:\Users\Yuran\AppData\Local\Platform\verification\20261105-platform-local-checks.zip`,
SHA256 `35ac89c6b090128293b8736cf878e8bce92337dc2b337bda3609c8efa540eb32`.
Preview 5196/5198 остановлены; рабочий frontend 5173 оставлен запущенным.

**Не завершена файловая очистка:** автоматическая проверка инструмента отклонила
`Remove-Item` с причиной `blocked by policy`, без дополнительных пояснений — как для
рекурсивного удаления двух заранее проверенных каталогов, так и для удаления четырёх
конкретных временных файлов. Остались **96** ignored screenshot/result файлов и **85**
файлов временного стенда/сканера. Временный каталог включает `test.env`, TLS key,
`override.json` и `before-update-private.json` — последний содержит снимок рабочего
окружения с секретами. Эти файлы не добавлены в Git. Постоянный DPAPI credential сохранён.
Docker resources стенда уже отсутствуют; выбранные PNG и архивы проверок сохранены.

Завершить очистку вручную в PowerShell после просмотра нужных artifacts:

```powershell
Remove-Item -LiteralPath 'C:\Platform\apps\frontend\screenshots' -Recurse -Force
Remove-Item -LiteralPath 'C:\Users\Yuran\AppData\Local\Temp\platform-acceptance-12634f4' -Recurse -Force
```

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
| Следующий GitHub run: отсутствующий SVG target README | Макет design/мобайл.svg включён в Git; локальный ignored-файл больше не маскирует broken link в чистом checkout |
| Frontend: generated tokens отличаются от JSON | danger-800 добавлен в canonical JSON, CSS сгенерирован штатно |
| Скрытые следующие style failures | Убраны hardcoded timings, восстановлен radius token, используются общие motion/theme variables |
| Browser: mobile drill panel ниже viewport | После смены уровней измеряется новый DOM, ResizeObserver подключается к новому panel; добавлен resize regression |
| PostgreSQL: 26 fixable Go findings | Пересобран gosu, исправляемых HIGH/CRITICAL стало 0 |

Job names сокращены; matrix показывает PostgreSQL/Redis/Mailpit, без digest в заголовке.
Digest остаются в источниках образов. Проверенный [CI №19](https://github.com/YCleffo/Platform/actions/runs/41414808885)
для `12634f4` завершился SUCCESS: 11/11 jobs. Он подтверждает реализованные в workflow проверки,
но не полное покрытие backend интерфейсом; повторная endpoint-сверка приведена в разделе 4.

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
  и не очищает Git history. Прежний credential ротирован 3 ноября; старый пароль проверенно не действует.**
- Старый аудит заменён этим файлом; исходные иконки/шрифты сохранены.
- Итоговая чистка этого этапа: 7 тестовых контейнеров, 4 тома и 2 сети удалены.
  Удалены 8 неиспользуемых образов (3 тестовых, Trivy/actionlint/yq, provisioner worker и старый dangling app),
  очищен восстанавливаемый Buildx cache — 10,97 GB. Осталось 8 образов, каждый привязан
  к сохранённым контейнерам, включая MyBots в состоянии Created. Рабочие 11 Platform и 2 MyBots сохранены.
- После нового Core Security E2E дополнительно удалены 3 контейнера, 3 тома и сеть;
  существующие app/DB/Redis images переиспользованы, новых тестовых образов не собиралось.
  Итого за оба стенда: 10 тестовых контейнеров, 7 томов, 3 сети. В конце снова 8/8 образов
  используются сохранёнными контейнерами, Buildx cache 0 B; временный actionlint удалён.

## 3. БД: что действительно проверено

| Проверка | Core / Runtime | Commercial |
| --- | --- | --- |
| Прикладные таблицы | 50 core + 3 platform_runtime | 61 commercial |
| Применённые миграции | 34 | 42 |
| Live catalog против сохранённой схемы | PASS | PASS |
| Invalid/unready indexes | 0 | 0 |
| Unvalidated constraints | 0 | 0 |
| pg_dump и восстановление в отдельную БД | PASS, 53 таблицы | PASS, 61 таблица |
| Row counts после восстановления | Совпали по всем таблицам | Совпали по всем таблицам |
| Row counts до/после замены рабочих контейнеров | Без изменений | Без изменений |
| Фактическая HTTP/runtime роль | platform_core_runtime | platform_commercial_runtime |
| Фактическая scheduler роль | platform_core_maintenance | platform_commercial_maintenance |
| SUPERUSER / CREATEDB / CREATEROLE / BYPASSRLS | Все false | Все false |

**Переключение выполнено 3 ноября.** DB roles реально установлены в рабочие HTTP/workers;
owner credential сохранён для migration/bootstrap. Изолированные negative SQL gates и
рабочие health/login/workers прошли после переключения. Рабочие данные и MyBots сохранены.

DBML: Core 50 таблиц / 86 физических FK, Runtime 3 / 1, Commercial 61 / 97 — PASS.
Schema reference, coverage inventory, Python typecheck и ссылки документации проверяются штатными
генераторами. Каталог не доказывает полную корректность финансовых данных, межбазовых логических
ссылок, external provider reconciliation или production disaster recovery.

В Symfony router после добавления операторских read models **83 HTTP method/path operations**;
множество совпадает с OpenAPI. Core routes содержат **146 literal operations**, включая
test-only proof endpoint, закрытый вне изолированного runtime.
Это инвентарь API, не процент frontend coverage.

## 4. Матрица frontend coverage

### Итог текущего этапа: все объявленные прикладные HTTP operations имеют frontend caller

**Учтены 229 HTTP method/path operations: Core 146, Commercial 83.** Прикладные 215
подключены к production UI/auth-коду; 14 служебных health/M2M/webhook endpoints не требуют
отдельной SPA-страницы. **GENERIC JSON-форм — 0, неиспользуемых wrappers — 0, операций без binding — 0.**

| Контур | Всего HTTP | UI-код / automatic auth | Generic JSON UI | Неиспользуемый wrapper | Нет binding | Служебные |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Core | 146 | 140 | 0 | 0 | 0 | 6 |
| Commercial | 83 | 75 | 0 | 0 | 0 | 8 |
| Итого | 229 | 215 | 0 | 0 | 0 | 14 |

Это карта исходников и наблюдавшихся запросов, **не production readiness и не line/branch coverage**.
53 прежние общие Core operations переведены на явные поля, связанные справочники и подтверждения.
Подключены 10 прежних Commercial gaps: direct membership и 9 operator actions. Дополнительно
добавлены 8 минимальных read models/capability endpoints для этих интерфейсов.
Общий verdict остаётся **NOT_READY**: живые provider/OAuth flows, production registries,
операторские назначения и security findings имеют отдельные критерии ниже.

### Core Auth: исходные разрывы и текущая реализация

[AuthLinkBuilder](../apps/backend/src/Core/Auth/Application/AuthLinkBuilder.php) генерирует
ссылки на приведённые ниже страницы. До исправления они попадали в SPA fallback 404.
Теперь [SPA router](../apps/frontend/src/app/router/index.ts) содержит отдельные Core routes:

| Backend сценарий | URL / состояние | Фактический результат |
| --- | --- | --- |
| Активация Core account из письма | /activate?token=… | Форма пароля и подтверждения; отдельный Core API |
| Сброс пароля Core из письма | /password-reset?token=… | Форма пароля; запрос письма через /admin/forgot-password |
| Восстановление Core MFA из письма | /mfa-recovery?token=… | Подтверждение текущим паролем; запрос через /admin/mfa-recovery; сессия не выдаётся |
| Обязательная смена Core пароля при входе | login status=password_change_required | Форма текущего/нового пароля, затем MFA при требовании сервера |

Добавлены проверки формы, локализованные ошибки, non-enumerating confirmation, обработка
MFA continuation и отмена позднего login/MFA ответа при уходе со страницы. Пароли и link
tokens не сохраняются в browser storage. Email URL допускает reload до завершения;
после успешного ответа token удаляется из URL. Referrer policy — `no-referrer`.

**Реализовано:** passwordless MFA recovery через Google/Telegram. SMTP, Core API, БД,
Redis, PKCE/state, отзыв факторов/сессий и новый SSO-вход прошли browser E2E.
Только OAuth transport заменён явно изолированным fixture; live providers не проверены.
Commercial /verify-email и /reset-password по-прежнему используют свою identity/API boundary.

### Закрытые разрывы предыдущего этапа

| Группа | Прежний пробел | Текущая реализация |
| --- | ---: | --- |
| Core общие JSON/UUID actions | 53 | Явные schemas/fields, entity pickers, пагинация, current-value read, protected roles, state-aware lifecycle и confirmations |
| Commercial finance operator | 4 | List/current/propose/approve/reject, независимая operator session, MFA/recent-auth и запрет self-review |
| Commercial privacy operator | 5 | Авторизованные request/incident directories, intake/resolve/detect/event/close |
| Commercial direct membership | 1 | Подтверждаемое назначение существующего активного Control User; invitation flow сохранён |

Исходные 63 операции проверены UI fixture runner; реальные server suites проверены отдельно.
Наличие формы не создаёт операторские права, consent registry или внешние доказательства.

За пределами HTTP-матрицы проверены также исходники console commands. Provisioning,
deployment update/decommission/reconcile/credential rotation, scheduler/outbox, renewal
collection и provider reconciliation остаются CLI/worker процессами без отдельного
операторского GUI. У ряда этих функций нет публичного operator HTTP API: frontend
нельзя добавить одним вызовом существующего customer API. Не запускать такие команды
из браузера напрямую и не показывать клиенту credential/signing key.

Router содержит **50 именованных маршрутов**: 23 Commercial/shared/settings/fallback и
27 Core admin/auth с отдельным входом и восстановлением. Обозначения:

- **E2E** — реальный одноразовый Core либо Commercial backend + браузер; контур указан явно.
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
| /accounts/:id/billing | billing_profiles, subscriptions/items/changes, invoices/lines, payments/methods, receipts, refunds | UI fixture и реальный zero-price/MFA/SMTP E2E PASS; платный provider lifecycle остаётся открытым |
| /accounts/:id/usage | entitlements, counters/periods | READ реальных entitlements/usage; изменения usage принадлежат backend/M2M |
| /accounts/:id/analytics | агрегаты membership/billing/audit/usage | READ; добавлены period/session/account generation guards и reverse-order tests; формулы/суммы требуют отдельных проверок |
| /accounts/:id/audit | commercial.audit_events, cursor/filter API | READ; tested late append/filter reset, applied filter pagination, deduplication и retry с сохранением строк; не полная матрица backend filters |
| /settings/security | sessions, password, MFA/recovery APIs | E2E чтение; отдельные enrollment/step-up сценарии есть в invitation suite; полный набор новых mutations — CODE |
| /settings/privacy | personal-data export | E2E скачивание JSON; не операторский privacy workflow |
| /settings | browser preferences | Browser PASS: theme, locale, density, font, padding, radius, shadows, motion, Undo, persistence, multi-tab, reduced motion, blocked storage |
| /settings/components | shared controls/menu/dialog | Browser PASS keyboard/touch/mouse, checkbox/switch/range, search/cascade/Back/context menu/dialog |
| fallback 404, публичные auth states | router | Public responsive и guards проверены на 320–1440 px |

### Core admin

Ранее 11 страниц после входа проверены через **UI fixtures**. Новые typed workflows имеют
отдельные real backend E2E ниже; рабочие /admin/modules и /admin/settings также прочитаны
в браузере под действующим оператором. Чтение рабочего экрана не доказывает весь lifecycle.

| Route | Таблицы / API | Текущее состояние |
| --- | --- | --- |
| /admin/login | /api/auth/login, me, MFA, refresh, logout, password/change-required | Core browser E2E PASS: обычный вход, required change, MFA continuation; поздний ответ проверен HTTP fixture |
| /activate, /password-reset, /mfa-recovery | Core auth email links | Core browser E2E PASS: password-based и passwordless Google/Telegram recovery; только OAuth transport fixture |
| /admin/forgot-password, /admin/mfa-recovery | Core request endpoints / outbox | Core browser E2E PASS: настоящее SMTP-письмо, отдельный Core token |
| /admin | Core list APIs | Счётчики; distribution accounts считает первую страницу до 100 |
| /admin/organizations | organizations, memberships | Поля организации и выбор участника из справочника; read/manage разделены, удаление подтверждается; UI fixtures PASS |
| /admin/persons | persons, person_contacts | Поля имени/даты рождения, current detail перед edit, contacts/type/primary switch и archive confirmation; UI fixtures PASS |
| /admin/accounts | accounts, roles, service_credentials | Поля human/service/system, person picker с дозагрузкой, state-aware lifecycle, email invitation, scopes/expiry и одноразовый secret; UI fixtures PASS |
| /admin/rbac | roles, permissions, role_permissions, account_roles | Typed role fields, protected/system guard, current permissions + multiple picker, account/role selection; EN/RU и loading geometry PASS |
| /admin/modules | modules, module_settings | Typed cards/detail, enable/disable confirmation, module settings forms; real browser/PG lifecycle PASS |
| /admin/settings | system_settings, feature_flags, public config | Typed values/feature forms, module lookup, anonymous publication preview; real browser/PG lifecycle PASS |
| /admin/privacy | requests, holds, erasure, incidents, representations, consents | Запросы: typed list/filter/pagination и проверенный offline intake; legal holds/representations/erasure/incidents/distribution переведены на schemas, справочники и подтверждения |
| /admin/privacy/requests/:id | request/deadlines/execution, corrections, blocks/termination, operator responses | Typed forms с подтверждениями, subject-bound resources, next-state policy; выдача ответа только для offline intake |
| /admin/audit | core.audit_events; superadmin API | Список и серверные фильтры action prefix / success / actor account с пагинацией; superadmin only |
| /admin/security | Core password change | Форма смены пароля; общие вкладки безопасности |
| /admin/security/mfa | Core MFA status/setup/confirm/disable/recovery codes | Real backend browser E2E PASS: enrollment/replay/regeneration/new MFA login/disable/recent expiry; HTTP fixtures и live status READ также проверены |
| /admin/security/sessions | Core own sessions/revoke/revoke-others | Device cards, current badge, refresh и confirmations; реальные адресный/bulk revoke сохраняют текущую сессию, остальные токены получают 401 |
| /admin/profile | Core own preferences/settings schema v2 | E2E: language/timezone, sparse saves, отмена, retry, null reset и новый вход; motion/transparency применяются в Core без изменения browser preferences |
| /admin/profile/consents | Core own definitions/grant/withdraw | E2E: просмотр plaintext, explicit checkbox, version conflict, grant/withdraw; при пустом реестре — редактируемые RU/EN шаблоны каркаса |
| /admin/profile/privacy | Core own request list/create и active representations | E2E: 8 типов, поиск представляемого лица, scopes, destructive acknowledgement, bounded pagination и uncertain POST; доступ обычному Core account |
| /admin/profile/privacy/:id | Core own detail/data/processing-information, representative responses | E2E: статусы/сроки, одноразовая выдача, fullscreen JSON и скачивание исходных байтов; SHA256/size совпадают с PostgreSQL execution evidence |
| /admin/security/passkeys | Core own passkeys + discoverable authentication | E2E: registration, signed login, rename/revoke, last method и recent-auth; UV virtual authenticator, реальный backend |
| /admin/security/identities | Core providers/own identities/link/unlink | E2E: popup, cancel/conflict, state/PKCE/session, свежий внешний вход и защита последнего метода; OIDC transport синтетический |
| /admin/finance | Commercial compensation API имеет отдельную авторизацию | Переход в `/operator`; отдельный вход, server capabilities, financial evidence и независимый review. Core bearer не используется |

Таблицы с JSON editor не считать полноценным UX. API wrappers и открывающиеся диалоги не
заменяют проверку разрешённых/запрещённых действий, readonly/system/protected records и 409/422.

### Что frontend пока не покрывает

| Область backend | Пробел |
| --- | --- |
| Новые Core и Commercial typed workflows | CODE + UI fixtures + real server suites; полный браузерный lifecycle с настоящим backend для каждой из 63 операций этим этапом не заявляется |
| Commercial operator production rollout | Нужны реальные privacy/financial authorization records и независимые финансовые операторы; права рабочим пользователям не выдавались автоматически |
| Deployments/domains/releases/maintenance/licenses | Пользовательские/операторские lifecycle screens не найдены; сначала зафиксировать release scope |
| Outbox/inbox/idempotency/provider attempts/signing keys | Служебная инфраструктура; не строить прямой CRUD секретов ради покрытия таблиц |
| Education «Сегодня» из design/мобайл.svg | API и страницы не реализованы; Commercial Analytics не является этим экраном |

### Полная сверка HTTP endpoint → frontend

База `12634f4` с текущими изменениями рабочего дерева; методы Core извлечены из [routes.php](../apps/backend/config/common/routes.php),
Commercial — из реально работающего Symfony router и сверены с [OpenAPI](../contracts/openapi/control-plane.yaml):
83/83, расхождений нет. Динамические параметры
нормализованы при сопоставлении. Строки ниже сохраняют backend-названия параметров.

Это карта **привязок в коде**, не новый E2E прогон всех операций и не проверка всех payload,
ролей, фильтров, ответов и состояний. Наличие CODE/GENERIC не означает готовый бизнес-сценарий.

- CODE — отдельный frontend код/форма/автоматическое обновление сессии и production caller.
- GENERIC — общий admin table/action с JSON/UUID; без полноценного typed workflow.
- WRAPPER — метод API есть, но не используется production frontend.
- НЕТ — привязка не найдена.
- SERVICE — health, M2M, server webhook; отдельная SPA-страница не требуется.

Core OAuth callback API вызываются из SPA после возврата провайдера на `/auth/callback/:provider`,
поэтому теперь правильно отнесены к CODE. Два login/recovery callback уже использовались раньше;
их прежняя классификация SERVICE исправлена. В последнем этапе подключены оставшиеся
10 Commercial operations и добавлены 8 read models; живые providers требуют отдельной проверки.


<details>
<summary>Core — 146 операций</summary>


| Backend operation | Привязка frontend |
| --- | --- |
| `GET /api/health/isolation` | SERVICE — nonce proof только для изолированного Core browser E2E; вне него 404 |
| `POST /api/m2m/runtime/entitlements/apply` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /health` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /health/live` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /health/ready` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /api/public/config` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigPublicPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigPublicPanel.vue) |
| `POST /api/auth/login` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [adminAuthStore.ts](../apps/frontend/src/core/admin/adminAuthStore.ts) |
| `POST /api/auth/password/change-required` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [adminAuthStore.ts](../apps/frontend/src/core/admin/adminAuthStore.ts) → [AdminLoginView.vue](../apps/frontend/src/modules/admin/AdminLoginView.vue) |
| `POST /api/auth/passkeys/authentication/start` | CODE — adminAuth.ts → adminAuthStore.loginWithPasskey → AdminLoginView; real WebAuthn E2E |
| `POST /api/auth/passkeys/authentication/complete` | CODE — adminAuth.ts → adminAuthStore.accept → AdminLoginView; signed assertion checked by Core |
| `POST /api/auth/passkeys/registration/start` | CODE — [credentials.ts](../apps/frontend/src/shared/api/core/credentials.ts) → [AdminPasskeysView.vue](../apps/frontend/src/modules/admin/AdminPasskeysView.vue); real E2E |
| `POST /api/auth/passkeys/registration/complete` | CODE — credentials.ts → AdminPasskeysView; native attestation + PostgreSQL |
| `GET /api/auth/providers` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → Core auth views |
| `POST /api/auth/external/{provider}/start` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → Core auth views |
| `GET /api/auth/external/{provider}/callback` | CODE — adminAuth.ts → CoreRecoveryCallbackView / adminAuthStore.completeExternalLogin; synthetic OIDC transport E2E |
| `POST /api/auth/refresh` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [adminHttp.ts](../apps/frontend/src/shared/api/core/adminHttp.ts) |
| `POST /api/auth/mfa/verify` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [adminAuthStore.ts](../apps/frontend/src/core/admin/adminAuthStore.ts) |
| `POST /api/auth/mfa/recovery/request` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [CoreRecoveryRequestView.vue](../apps/frontend/src/modules/admin/auth/CoreRecoveryRequestView.vue) |
| `POST /api/auth/mfa/recovery/confirm` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [CoreAuthLinkView.vue](../apps/frontend/src/modules/admin/auth/CoreAuthLinkView.vue); external reauth пока не завершён |
| `POST /api/auth/mfa/recovery/external/{provider}/start` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → Core auth views |
| `GET /api/auth/mfa/recovery/external/{provider}/callback` | CODE — adminAuth.ts → CoreRecoveryCallbackView; synthetic OIDC recovery transport E2E предыдущего этапа |
| `POST /api/auth/activate` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [CoreAuthLinkView.vue](../apps/frontend/src/modules/admin/auth/CoreAuthLinkView.vue) |
| `POST /api/auth/password-reset/request` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [CoreRecoveryRequestView.vue](../apps/frontend/src/modules/admin/auth/CoreRecoveryRequestView.vue) |
| `POST /api/auth/password-reset/confirm` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [CoreAuthLinkView.vue](../apps/frontend/src/modules/admin/auth/CoreAuthLinkView.vue) |
| `POST /api/auth/logout` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [adminAuthStore.ts](../apps/frontend/src/core/admin/adminAuthStore.ts) |
| `GET /api/auth/me` | CODE — [adminAuth.ts](../apps/frontend/src/shared/api/core/adminAuth.ts) → [adminAuthStore.ts](../apps/frontend/src/core/admin/adminAuthStore.ts) |
| `PATCH /api/auth/me/preferences` | CODE — [account.ts](../apps/frontend/src/shared/api/core/account.ts) → [CorePreferencesView.vue](../apps/frontend/src/modules/admin/profile/CorePreferencesView.vue); real backend E2E |
| `POST /api/auth/me/password/change` | CODE — форма смены пароля в [AdminSecurityView.vue](../apps/frontend/src/modules/admin/AdminSecurityView.vue) |
| `GET /api/auth/me/settings` | CODE — [accountSettingsStore.ts](../apps/frontend/src/core/admin/accountSettingsStore.ts) → AdminLayout / CorePreferencesView; real backend E2E |
| `PATCH /api/auth/me/settings` | CODE — [account.ts](../apps/frontend/src/shared/api/core/account.ts) → CorePreferencesView; real backend E2E |
| `GET /api/auth/me/consents` | CODE — [account.ts](../apps/frontend/src/shared/api/core/account.ts) → [CoreConsentsView.vue](../apps/frontend/src/modules/admin/profile/CoreConsentsView.vue); real backend E2E |
| `POST /api/auth/me/consents/{code}` | CODE — account.ts → CoreConsentsView; real backend E2E с проверкой version/hash |
| `DELETE /api/auth/me/consents/{code}` | CODE — account.ts → CoreConsentsView; real backend E2E с durable history |
| `POST /api/core/privacy/representations` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/representations/{id}/verify` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/representations/{id}/revoke` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/auth/me/privacy/requests` | CODE — [CorePrivacyRequestsView.vue](../apps/frontend/src/modules/admin/profile/CorePrivacyRequestsView.vue), [privacy.ts](../apps/frontend/src/shared/api/core/privacy.ts) |
| `GET /api/auth/me/privacy/requests` | CODE — [privacyRequestsStore.ts](../apps/frontend/src/core/admin/privacyRequestsStore.ts) |
| `GET /api/auth/me/privacy/requests/{id}` | CODE — [CorePrivacyRequestView.vue](../apps/frontend/src/modules/admin/profile/CorePrivacyRequestView.vue) |
| `GET /api/auth/me/privacy/requests/{id}/data` | CODE — [privacy.ts](../apps/frontend/src/shared/api/core/privacy.ts) |
| `GET /api/auth/me/privacy/requests/{id}/processing-information` | CODE — [privacy.ts](../apps/frontend/src/shared/api/core/privacy.ts) |
| `GET /api/auth/me/privacy/representations` | CODE — [CorePrivacyRequestsView.vue](../apps/frontend/src/modules/admin/profile/CorePrivacyRequestsView.vue), [CorePrivacyRequestView.vue](../apps/frontend/src/modules/admin/profile/CorePrivacyRequestView.vue) |
| `GET /api/core/privacy/requests/{id}/data` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `GET /api/core/privacy/requests/{id}/processing-information` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `GET /api/auth/me/privacy/representations/{representationId}/requests/{id}/data` | CODE — [privacy.ts](../apps/frontend/src/shared/api/core/privacy.ts) |
| `GET /api/auth/me/privacy/representations/{representationId}/requests/{id}/processing-information` | CODE — [privacy.ts](../apps/frontend/src/shared/api/core/privacy.ts) |
| `POST /api/auth/me/privacy/representations/{representationId}/requests` | CODE — [CorePrivacyRequestsView.vue](../apps/frontend/src/modules/admin/profile/CorePrivacyRequestsView.vue) |
| `GET /api/core/privacy/requests/options` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperationDialog.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperationDialog.vue) |
| `GET /api/core/privacy/requests` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/requests/intake` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `GET /api/core/privacy/requests/{id}` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/requests/{id}/transition` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/requests/{id}/correction-evidence` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/requests/{id}/corrections` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/requests/{id}/deadlines/{deadlineCode}/extend` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/requests/{id}/blocks` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `GET /api/core/privacy/requests/{id}/blocks` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/blocks/{id}/release` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/requests/{id}/processing-termination/execute` | CODE — [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts), [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue) |
| `POST /api/core/privacy/legal-holds` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/privacy/legal-holds` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/privacy/legal-holds/{id}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/legal-holds/{id}/release` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/erasure-cases` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/privacy/erasure-cases/{id}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/erasure-cases/{id}/execute` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/privacy/erasure-cases/{id}/evidence` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/erasure-cases/{id}/evidence/attest` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/incidents` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/privacy/incidents/{id}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/incidents/{id}/transition` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/incidents/{id}/notification-record` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/distribution-consents` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/privacy/distribution-consents/{id}/withdraw` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/auth/me/presence/heartbeat` | CODE — [presenceStore.ts](../apps/frontend/src/core/admin/presenceStore.ts), [usePresenceHeartbeat.ts](../apps/frontend/src/core/admin/usePresenceHeartbeat.ts) |
| `GET /api/auth/me/identities` | CODE — credentials.ts → [AdminIdentitiesView.vue](../apps/frontend/src/modules/admin/AdminIdentitiesView.vue); real E2E |
| `POST /api/auth/me/identities/{provider}/link/start` | CODE — credentials.ts → externalLink popup; real Core + synthetic OIDC transport |
| `GET /api/auth/me/identities/{provider}/link/callback` | CODE — credentials.ts → externalLink / CoreRecoveryCallbackView; session/state/PKCE + synthetic transport |
| `DELETE /api/auth/me/identities/{provider}` | CODE — credentials.ts → confirmation/recent-auth/last-method; real E2E |
| `GET /api/auth/me/passkeys` | CODE — credentials.ts → AdminPasskeysView; real E2E |
| `PATCH /api/auth/me/passkeys/{id}` | CODE — credentials.ts → typed label dialog; real E2E |
| `DELETE /api/auth/me/passkeys/{id}` | CODE — credentials.ts → confirmation/recent-auth/last-method; real E2E |
| `GET /api/auth/mfa` | CODE — [security.ts](../apps/frontend/src/shared/api/core/security.ts) → [AdminMfaView.vue](../apps/frontend/src/modules/admin/AdminMfaView.vue) |
| `POST /api/auth/mfa/totp/setup` | CODE — [security.ts](../apps/frontend/src/shared/api/core/security.ts) → [AdminMfaView.vue](../apps/frontend/src/modules/admin/AdminMfaView.vue) |
| `POST /api/auth/mfa/totp/confirm` | CODE — [security.ts](../apps/frontend/src/shared/api/core/security.ts) → [AdminMfaView.vue](../apps/frontend/src/modules/admin/AdminMfaView.vue) |
| `DELETE /api/auth/mfa/totp` | CODE — [security.ts](../apps/frontend/src/shared/api/core/security.ts) → [AdminMfaView.vue](../apps/frontend/src/modules/admin/AdminMfaView.vue) |
| `POST /api/auth/mfa/recovery-codes/regenerate` | CODE — [security.ts](../apps/frontend/src/shared/api/core/security.ts) → [AdminMfaView.vue](../apps/frontend/src/modules/admin/AdminMfaView.vue) |
| `GET /api/auth/sessions` | CODE — [security.ts](../apps/frontend/src/shared/api/core/security.ts) → [AdminSessionsView.vue](../apps/frontend/src/modules/admin/AdminSessionsView.vue) |
| `DELETE /api/auth/sessions/{id}` | CODE — [security.ts](../apps/frontend/src/shared/api/core/security.ts) → [AdminSessionsView.vue](../apps/frontend/src/modules/admin/AdminSessionsView.vue) |
| `POST /api/auth/sessions/revoke-others` | CODE — [security.ts](../apps/frontend/src/shared/api/core/security.ts) → [AdminSessionsView.vue](../apps/frontend/src/modules/admin/AdminSessionsView.vue) |
| `GET /api/core/organizations` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/organizations` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/organizations/{id}/members` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/organizations/{id}/members` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/organizations/{id}/members/{personId}/leave` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/persons` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/persons` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/persons/{id}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `PATCH /api/core/persons/{id}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/persons/{id}/archive` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/persons/{id}/contacts` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/persons/{id}/contacts` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{accountId}/service-credentials` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/accounts/{accountId}/service-credentials` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{accountId}/service-credentials/{credentialId}/rotate` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{accountId}/service-credentials/{credentialId}/revoke` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/accounts` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/accounts/{id}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{id}/approve` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{id}/disable` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{id}/enable` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{id}/archive` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{id}/invite` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/accounts/{id}/roles` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/accounts/{id}/roles/{roleId}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `DELETE /api/core/accounts/{id}/roles/{roleId}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/audit-events` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/roles` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `POST /api/core/roles` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `PATCH /api/core/roles/{id}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `DELETE /api/core/roles/{id}` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/roles/{id}/permissions` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `PUT /api/core/roles/{id}/permissions` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/permissions` | CODE — [typed catalog](../apps/frontend/src/modules/admin/workbench/catalog.ts), [fields/workflows](../apps/frontend/src/modules/admin/workbench/OperationDialog.vue) |
| `GET /api/core/modules` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [AdminModulesView.vue](../apps/frontend/src/modules/admin/AdminModulesView.vue) |
| `GET /api/core/modules/{code}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [AdminModulesView.vue](../apps/frontend/src/modules/admin/AdminModulesView.vue) |
| `POST /api/core/modules/{code}/enable` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [AdminModulesView.vue](../apps/frontend/src/modules/admin/AdminModulesView.vue) |
| `POST /api/core/modules/{code}/disable` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [AdminModulesView.vue](../apps/frontend/src/modules/admin/AdminModulesView.vue) |
| `GET /api/core/settings/system` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigSettingsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigSettingsPanel.vue) |
| `GET /api/core/settings/system/{key}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigSettingsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigSettingsPanel.vue) |
| `PUT /api/core/settings/system/{key}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigSettingsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigSettingsPanel.vue) |
| `DELETE /api/core/settings/system/{key}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigSettingsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigSettingsPanel.vue) |
| `GET /api/core/modules/{moduleCode}/settings` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigSettingsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigSettingsPanel.vue) |
| `GET /api/core/modules/{moduleCode}/settings/{key}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigSettingsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigSettingsPanel.vue) |
| `PUT /api/core/modules/{moduleCode}/settings/{key}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigSettingsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigSettingsPanel.vue) |
| `DELETE /api/core/modules/{moduleCode}/settings/{key}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigSettingsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigSettingsPanel.vue) |
| `GET /api/core/feature-flags` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigFlagsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigFlagsPanel.vue) |
| `GET /api/core/feature-flags/{code}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigFlagsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigFlagsPanel.vue) |
| `PUT /api/core/feature-flags/{code}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigFlagsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigFlagsPanel.vue) |
| `DELETE /api/core/feature-flags/{code}` | CODE — [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) → [ConfigFlagsPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigFlagsPanel.vue) |
| `GET /api/core/privacy/work-items/representation` | CODE — [EntityPicker.vue](../apps/frontend/src/modules/admin/workbench/EntityPicker.vue), [authorized read model](../apps/backend/src/Core/Privacy/Api/PrivacyWorkItemsAction.php) |
| `GET /api/core/privacy/work-items/erasure` | CODE — [EntityPicker.vue](../apps/frontend/src/modules/admin/workbench/EntityPicker.vue), [authorized read model](../apps/backend/src/Core/Privacy/Api/PrivacyWorkItemsAction.php) |
| `GET /api/core/privacy/work-items/incident` | CODE — [EntityPicker.vue](../apps/frontend/src/modules/admin/workbench/EntityPicker.vue), [authorized read model](../apps/backend/src/Core/Privacy/Api/PrivacyWorkItemsAction.php) |
| `GET /api/core/privacy/work-items/distribution` | CODE — [EntityPicker.vue](../apps/frontend/src/modules/admin/workbench/EntityPicker.vue), [authorized read model](../apps/backend/src/Core/Privacy/Api/PrivacyWorkItemsAction.php) |

</details>


<details>
<summary>Commercial — 83 операции</summary>


| Backend operation | Привязка frontend |
| --- | --- |
| `GET /api/control/accounts` | CODE — [account.ts](../apps/frontend/src/shared/api/commercial/account.ts) → [accountStore.ts](../apps/frontend/src/core/account/accountStore.ts) |
| `POST /api/control/accounts` | CODE — [account.ts](../apps/frontend/src/shared/api/commercial/account.ts) → [accountStore.ts](../apps/frontend/src/core/account/accountStore.ts) |
| `GET /api/control/accounts/{accountId}` | CODE — [account.ts](../apps/frontend/src/shared/api/commercial/account.ts) → [accountStore.ts](../apps/frontend/src/core/account/accountStore.ts) |
| `GET /api/control/accounts/{accountId}/memberships` | CODE — [account.ts](../apps/frontend/src/shared/api/commercial/account.ts) → [useAccountMemberships.ts](../apps/frontend/src/modules/commercial/account/useAccountMemberships.ts) |
| `POST /api/control/accounts/{accountId}/memberships` | CODE — [AccountMembersView.vue](../apps/frontend/src/modules/commercial/account/AccountMembersView.vue), [account API](../apps/frontend/src/shared/api/commercial/account.ts) |
| `PATCH /api/control/accounts/{accountId}/memberships/{membershipId}` | CODE — [account.ts](../apps/frontend/src/shared/api/commercial/account.ts) → [useMembershipActions.ts](../apps/frontend/src/modules/commercial/account/useMembershipActions.ts) |
| `DELETE /api/control/accounts/{accountId}/memberships/{membershipId}` | CODE — [account.ts](../apps/frontend/src/shared/api/commercial/account.ts) → [useMembershipActions.ts](../apps/frontend/src/modules/commercial/account/useMembershipActions.ts) |
| `PUT /api/control/accounts/{accountId}/billing-profile` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/billing-state` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/control/accounts/{accountId}/onboarding/catalog-reviewed` | CODE — [account.ts](../apps/frontend/src/shared/api/commercial/account.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/analytics` | CODE — [analytics.ts](../apps/frontend/src/shared/api/commercial/analytics.ts) → [AccountAnalyticsView.vue](../apps/frontend/src/modules/commercial/analytics/AccountAnalyticsView.vue) |
| `GET /api/control/accounts/{accountId}/audit-events` | CODE — [audit.ts](../apps/frontend/src/shared/api/commercial/audit.ts) → [AccountAuditView.vue](../apps/frontend/src/modules/commercial/audit/AccountAuditView.vue) |
| `POST /api/control/auth/register` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/verification/resend` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/verify` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/login` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/refresh` | CODE — автоматическое обновление в [refresh.ts](../apps/frontend/src/core/api/refresh.ts) |
| `POST /api/control/auth/logout` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/password/reset/request` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/password/reset/confirm` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/password/change` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/reauthenticate` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [ReauthenticateDialog.vue](../apps/frontend/src/modules/commercial/security/ReauthenticateDialog.vue) |
| `POST /api/control/auth/mfa/login/verify` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `GET /api/control/auth/mfa/status` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [ReauthenticateDialog.vue](../apps/frontend/src/modules/commercial/security/ReauthenticateDialog.vue) |
| `POST /api/control/auth/mfa/totp/setup` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [useMfaEnrollment.ts](../apps/frontend/src/modules/commercial/security/useMfaEnrollment.ts) |
| `POST /api/control/auth/mfa/totp/confirm` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [useMfaEnrollment.ts](../apps/frontend/src/modules/commercial/security/useMfaEnrollment.ts) |
| `POST /api/control/auth/mfa/recovery-codes/regenerate` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/mfa/disable` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `GET /api/control/auth/sessions` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [useSessions.ts](../apps/frontend/src/modules/commercial/security/useSessions.ts) |
| `DELETE /api/control/auth/sessions/{sessionId}` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/auth/sessions/revoke-others` | CODE — [auth.ts](../apps/frontend/src/shared/api/commercial/auth.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `GET /api/control/catalog/plans` | CODE — [catalog.ts](../apps/frontend/src/shared/api/commercial/catalog.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/entitlements` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/finance/plan-change-compensations/{changeId}` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `POST /api/control/finance/plan-change-compensations/{changeId}` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `POST /api/control/finance/plan-change-compensations/attestations/{attestationId}/approve` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `POST /api/control/finance/plan-change-compensations/attestations/{attestationId}/reject` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `GET /health/live` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /health/isolation` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /health/ready` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /health` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `POST /api/control/accounts/{accountId}/invitations` | CODE — [invitations.ts](../apps/frontend/src/shared/api/commercial/invitations.ts) → [useInvitationActions.ts](../apps/frontend/src/modules/commercial/invitations/useInvitationActions.ts) |
| `GET /api/control/accounts/{accountId}/invitations` | CODE — [invitations.ts](../apps/frontend/src/shared/api/commercial/invitations.ts) → [useAccountInvitations.ts](../apps/frontend/src/modules/commercial/invitations/useAccountInvitations.ts) |
| `DELETE /api/control/accounts/{accountId}/invitations/{invitationId}` | CODE — [invitations.ts](../apps/frontend/src/shared/api/commercial/invitations.ts) → [useInvitationActions.ts](../apps/frontend/src/modules/commercial/invitations/useInvitationActions.ts) |
| `POST /api/control/accounts/{accountId}/invitations/{invitationId}/resend` | CODE — [invitations.ts](../apps/frontend/src/shared/api/commercial/invitations.ts) → [useInvitationActions.ts](../apps/frontend/src/modules/commercial/invitations/useInvitationActions.ts) |
| `POST /api/control/invitations/preview` | CODE — [invitations.ts](../apps/frontend/src/shared/api/commercial/invitations.ts) → [useInvitationAcceptance.ts](../apps/frontend/src/modules/commercial/invitations/useInvitationAcceptance.ts) |
| `POST /api/control/invitations/accept` | CODE — [invitations.ts](../apps/frontend/src/shared/api/commercial/invitations.ts) → [useInvitationAcceptance.ts](../apps/frontend/src/modules/commercial/invitations/useInvitationAcceptance.ts) |
| `POST /api/control/invitations/decline` | CODE — [invitations.ts](../apps/frontend/src/shared/api/commercial/invitations.ts) → [useInvitationAcceptance.ts](../apps/frontend/src/modules/commercial/invitations/useInvitationAcceptance.ts) |
| `POST /api/control/accounts/{accountId}/subscriptions/{subscriptionId}/invoice` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/invoices/{invoiceId}` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/m2m/deployment/status` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /api/m2m/entitlements/latest` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `POST /api/m2m/usage` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /api/control/me` | CODE — [account.ts](../apps/frontend/src/shared/api/commercial/account.ts) → [authStore.ts](../apps/frontend/src/core/auth/authStore.ts) |
| `POST /api/control/accounts/{accountId}/invoices/{invoiceId}/payment` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/payments/{paymentId}` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/payment-methods` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/control/accounts/{accountId}/payment-methods/{methodId}/default` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `DELETE /api/control/accounts/{accountId}/payment-methods/{methodId}` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/control/accounts/{accountId}/payments/{paymentId}/checkout` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/payments/{paymentId}/receipt` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/privacy/me/export` | CODE — [privacy.ts](../apps/frontend/src/shared/api/commercial/privacy.ts) → [PrivacySettingsView.vue](../apps/frontend/src/modules/settings/PrivacySettingsView.vue) |
| `POST /api/control/privacy/operator/requests/intake` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `POST /api/control/privacy/operator/requests/{id}/resolve` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `POST /api/control/privacy/operator/incidents` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `POST /api/control/privacy/operator/incidents/{id}/events` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `POST /api/control/privacy/operator/incidents/{id}/close` | CODE — [operator operations](../apps/frontend/src/modules/commercial/operator/operations.ts), [OperatorConsole.vue](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue) |
| `POST /api/control/accounts/{accountId}/payments/{paymentId}/refunds` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/payments/{paymentId}/refunds` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/refunds/{refundId}` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/control/accounts/{accountId}/subscriptions` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/subscriptions/{subscriptionId}` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/control/accounts/{accountId}/subscriptions/{subscriptionId}/cancel` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/control/accounts/{accountId}/subscriptions/{subscriptionId}/auto-renew/cancel` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/control/accounts/{accountId}/subscriptions/{subscriptionId}/plan-changes` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/plan-changes/{changeId}` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `POST /api/control/accounts/{accountId}/plan-changes/{changeId}/cancel` | CODE — [billing.ts](../apps/frontend/src/shared/api/commercial/billing.ts) → [AccountBillingView.vue](../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue) |
| `GET /api/control/accounts/{accountId}/usage` | CODE — [usage.ts](../apps/frontend/src/shared/api/commercial/usage.ts) → [AccountUsageView.vue](../apps/frontend/src/modules/commercial/usage/AccountUsageView.vue) |
| `POST /api/control/webhooks/yookassa` | SERVICE — серверная интеграция/health; отдельный SPA endpoint не нужен |
| `GET /api/control/operator/me` | CODE — [operator session/workspace](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue), [read model](../apps/control-plane/src/Infrastructure/Http/OperatorWorkspaceController.php) |
| `GET /api/control/operator/privacy/requests` | CODE — [operator session/workspace](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue), [read model](../apps/control-plane/src/Infrastructure/Http/OperatorWorkspaceController.php) |
| `GET /api/control/operator/privacy/incidents` | CODE — [operator session/workspace](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue), [read model](../apps/control-plane/src/Infrastructure/Http/OperatorWorkspaceController.php) |
| `GET /api/control/operator/compensations` | CODE — [operator session/workspace](../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue), [read model](../apps/control-plane/src/Infrastructure/Http/OperatorWorkspaceController.php) |

</details>

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
[fullscreen JSON с HTTP fixtures](evidence/current/admin-response.png),
[Core password change mobile / RU](evidence/current/core-auth-mobile.png),
[Core password change desktop / EN](evidence/current/core-auth-desktop.png),
[Billing mobile, HTTP fixtures](evidence/current/billing-mobile.png),
[Billing desktop, HTTP fixtures](evidence/current/billing-desktop.png),
[Billing с реальным zero-price backend](evidence/current/billing-real-zero-price.png).

Новый frontend этап: [Core MFA mobile](evidence/current/core-security-mfa-mobile.png),
[Core MFA desktop / RU / light](evidence/current/core-security-mfa-desktop.png),
[Core device sessions](evidence/current/core-security-sessions-desktop.png).
Это явно синтетические HTTP fixtures, не скриншоты чужой либо production сессии.
Отдельно: [Core MFA на настоящем одноразовом backend](evidence/current/core-security-real-enabled.png).

Core account/profile: [настройки desktop](evidence/current/core-profile-preferences-desktop.png),
[согласия RU desktop](evidence/current/core-profile-consents-desktop-ru.png),
[шаблон mobile](evidence/current/core-profile-template-mobile.png),
[настоящий backend](evidence/current/core-profile-real.png).
Скриншоты осмотрены; устранены растянутые строки карточек, добавлены видимые labels языка/часового
пояса и реактивный перевод success-сообщений при переключении языка. Проверка последнего
поведения добавлена в browser regression.

Billing screenshots осмотрены: реквизиты/период/статусы читаемы, ID переносится, горизонтального
overflow на 393/1440 px нет. Снимки fixtures сделаны после исчезновения временных notifications.

## 6. Реальные результаты локальной проверки

Новые operator privacy screenshots осмотрены:
[операторский список](evidence/current/core-privacy-operator-list.png),
[offline intake на mobile / RU / 140%](evidence/current/core-privacy-operator-intake-mobile.png),
[обращение на настоящем backend](evidence/current/core-privacy-operator-real-detail.png),
[одноразовый ответ на настоящем backend](evidence/current/core-privacy-operator-real-response.png).
Сохранены self-service privacy screenshots предыдущего этапа:
[список запросов](evidence/current/core-privacy-list-desktop.png),
[полноэкранный ответ](evidence/current/core-privacy-response-desktop.png),
[создание на mobile / RU / 140%](evidence/current/core-privacy-create-mobile.png),
[реальный backend response](evidence/current/core-privacy-real-response.png).
Сохранены также credential evidence предыдущего этапа:
[passkeys на настоящем backend](evidence/current/core-credentials-real-passkeys.png),
[external identities на настоящем backend](evidence/current/core-credentials-real-identities.png),
[RU light / 140% text](evidence/current/core-credentials-identities-desktop-ru.png),
[mobile create dialog](evidence/current/core-credentials-passkey-mobile.png).
Дополнительно проверены границы modal panel относительно viewport; мобильные вкладки
больше не занимают несколько рядов над формой.

### Предыдущий полный этап: 4 ноября 2026, рабочее дерево без commit/push

Последний этап закрывает 53 общие Core формы и 10 Commercial gaps. Заново выполнены полные
Linux suites Core и Commercial, Psalm, PHPStan, frontend QA, проверки новых форм и общие
UI fixture regressions. Последние исправления AppBadge и admin header повторно проверены
на 50 маршрутах, новых workflows, admin/accessibility и credentials/privacy/configuration/Billing.
На рабочих БД выполнены только login/read/logout и проверка сохранности; пароли, роли,
MFA и прикладные данные этим этапом не менялись.

Cross-plane, Billing load, прежние реальные mutating auth/security/profile/credentials/privacy/
configuration E2E, DB role gates и image security inventory ниже — сохранённые результаты
предыдущих этапов этого рабочего дерева. Их новый полный прогон этим этапом не выполнен.
На дату предыдущего этапа эти сценарии оставались открыты. Свежая локальная paid/operator
приёмка — в этапе 14; живые providers по-прежнему не подтверждены.

Новые осмотренные screenshots с HTTP fixtures:
[Core workflows](evidence/current/workflows-core-desktop.png),
[явная форма полномочия](evidence/current/workflows-core-form.png),
[Commercial operator desktop](evidence/current/workflows-commercial-desktop.png),
[Commercial operator mobile](evidence/current/workflows-commercial-mobile.png),
[добавление существующего участника](evidence/current/workflows-direct-membership.png).
Эти снимки доказывают отрисовку с синтетическими данными, а не прохождение реальной оплаты.

Осмотрены новые снимки с HTTP fixtures:
[обзор](evidence/current/typography-dashboard-desktop.png),
[системные настройки](evidence/current/typography-settings-desktop.png),
[мобильная форма RU / 140%](evidence/current/typography-settings-mobile.png),
[настройки внешнего вида](evidence/current/typography-public-settings.png).

Configuration screenshots осмотрены:
[анонимная публикация](evidence/current/core-config-public.png),
[форма настройки на mobile / RU / 140%](evidence/current/core-config-setting-mobile.png),
[модуль и числовая настройка на настоящем backend](evidence/current/core-config-real-module.png),
[feature flag на настоящем backend](evidence/current/core-config-real-flags.png).

| Проверка | Фактический результат |
| --- | --- |
| Core, полный Linux suite текущего этапа | **540 tests / 14 212 assertions PASS**, новый privacy directory API test **1 / 13 PASS**; предыдущие configuration API tests **8 / 121**, Core email renderer **5 / 15** входят в полный suite |
| Commercial, полный Linux suite текущего этапа | **458 tests / 4 982 assertions PASS**; новый OperatorWorkspace integration suite **5 / 28 PASS**, включая реальные SQL finance list и authorization revocation |
| Cross-plane, предыдущий этап | **8 tests / 334 assertions PASS**, заново не запускался |
| Psalm / PHPStan level 7 | **PASS** на текущем backend-коде: Core Psalm JSON `[]`, Commercial PHPStan **0 errors** |
| Frontend Vitest | **346 tests / 67 files PASS**; добавлены whitelist/validation contracts форм и независимая operator session с late-response/logout races |
| Typecheck / ESLint / Vue format / production build | **PASS**; typecheck включает app и Node/Vite config, build запускает обе проверки; главный JS chunk **611.19 kB**, warning >500 kB остаётся |
| Tokens / styles / local SVG | **PASS**, 28 символов; исходные SVG и локальные шрифты сохранены |
| Typography / layout browser matrix | **50 маршрутов / 408 состояний / 7 817 проверок текста PASS**: 320/393/768/1440 px, 100/140% текста, RU/EN, light/dark, 2 загруженных локальных font assets, overflow 0. Матрица сверяется со всеми named routes через AST router; HTTP fixtures включают заполненные, пустые и безопасные error-состояния, не полный business lifecycle |
| Новые typed workflows / contracts | **64 UI fixture сценария PASS**, наблюдались requests всех **53 Core + 10 Commercial** операций; audit filters, warmed-list 403, uncertain write после reopen, MFA step-up без financial replay, отдельные bearer и customer session после operator logout. Дополнительно **2 Node tests PASS**: методы/пути/permissions против PHP routes и наличие динамических RU/EN action/field/choice labels (**410 checks**) |
| Typography на рабочем Core | **5 страниц PASS**, только чтение: overview/settings/modules/profile/security. Desktop H2 **20/28 px / 600**, body **14/20 / 400**, metadata **12/16**; горизонтального overflow нет. Собственная проверочная сессия завершена |
| Core mutation guard | **11 tests PASS**, nonce direct API + frontend proxy |
| Core real browser E2E | **PASS**: activation/reset/password-based и Google/Telegram recovery, replay/expiry, fresh SSO login, required change с/без MFA |
| Negative authorization | **PASS**: A согрел persons → logout → B с accounts.read, persons API 403; строки A не показаны |
| Cache / HTTP races | **PASS**: late 200/403/500, refresh/logout/A→B, same-session rotation, cached-hit microtask, forced denial eviction и decoration guards |
| Core public real backend states | **20 состояний**, 320/393/768/1440, overflow нет, axe A/AA violations 0 |
| Core auth / admin / controls / public accessibility fixtures | **42 / 33 / 32 / 30 состояний или assertions PASS** |
| Новые Core MFA/sessions UI fixtures | **18 состояний PASS** на production preview: RU/EN, light/dark, 320/393/768/1440, reduced motion, 140% text, QR/manual key, recovery acknowledgement/copy, session revoke/cancel и recent-auth redirect; axe A/AA violations 0 |
| Новые Core read-only live contracts | **PASS**: отдельный вход → GET MFA + sessions → logout; рабочий MFA не изменялся |
| Новые Core Security real browser E2E | **PASS** на HTTPS production preview, настоящих PostgreSQL/Redis/Core под runtime role: addressed/bulk revoke + 401 старых токенов, TOTP enrollment/replay/regeneration, новый MFA login, disable с проверкой factors/codes в БД и отказ сервера по истёкшему recent-auth. Никакого OAuth/provider fixture в этом сценарии нет |
| Core profile UI fixtures | **11 состояний PASS** на production preview: basic account без admin permissions, sparse save/error/retry/reset, 320/393/768/1440, RU/EN, light/dark, 140% text, reduced motion, consent version conflict/grant/withdraw/protected document и пустой реестр с шаблонами; axe A/AA 0, overflow нет |
| Core profile real browser E2E | **3 группы PASS** на HTTPS production preview: preferences и schema-v2 settings через UI с проверкой PostgreSQL, consent grant/withdraw с history, null reset без потери locale/timezone, concurrent partial PATCH и logout/login; настоящий Core/PG/Redis под restricted runtime role |
| Account settings OpenAPI | **PASS** YAML и локальные refs; контракт приведён к фактической schema v2, добавлены preferences/consents и review conflict 409 |
| Core credentials UI fixtures | **12 состояний PASS**, production preview + Chromium virtual authenticator: создание/отмена native prompt, rename/revoke, last-method, popup cancel/conflict/link/unlink, provider с loginEnabled=false, recent-auth, passkey→MFA continuation, RU/EN, 320/393/768/1440, light/dark, 140% текста/reduced motion; axe A/AA 0, overflow нет |
| Core credentials real browser E2E | **6 групп PASS** на HTTPS production preview, настоящий Core/PG/Redis под runtime DB role: native attestation/signature, durable key + rename + повторный passkey login, last-key 409, external link/owner conflict/cancel/новый SSO login, revoke с альтернативным методом, last-provider 409/добавление replacement key/unlink и повторный login, recent-auth expiry. Аутентификатор виртуальный, только OIDC transport синтетический |
| Новый credential read burst | **100 requests / concurrency 20 / 0 errors / p95 431 ms** в финальном HTTPS прогоне, без retries, проверка собственных IDs/отсутствия чужих записей; локальная короткая проверка, не production capacity |
| Credential contract | Существующий `auth-passkeys.yaml` исправлен: `status=success`, актуальные tokens/challengeToken DTO, safe lists и external identity lifecycle; старые `success:boolean`, `challengeId/mfaToken/availableMethods` не соответствовали серверу |
| Core privacy UI fixtures | **21 состояние PASS**: pagination/retry, 8 типов, risky acknowledgement, double submit, self/representative responses, submit-only scope, revoked grant, uncertain POST, wrong-subject rejection, потерянный stateful GET без replay, stalled lookup/timeout/retry; 320/393/768/1440, RU/EN, light/dark, 140% text/reduced motion, axe A/AA 0, overflow нет |
| Core privacy real browser E2E | **6 групп PASS**, HTTPS production preview + настоящий Core/PostgreSQL/Redis под runtime role: создание, статусы/сроки, четыре выдачи, точные raw bytes/SHA256/size в execution evidence, repeated delivery 404, cross-account 404/422, отзыв полномочия через HTTP, реальная пагинация >25 |
| Новый privacy read burst | **100 requests / concurrency 20 / 0 errors / p95 239 ms**, финальный HTTPS прогон; без retries, с проверкой scope. Локальный короткий read burst, не production capacity |
| Privacy test boundary | Только operator disclosure metadata синтетические: explicit `CORE_PRIVACY_BROWSER_FIXTURE=1` + test env/DB/Redis/nonce guards; 6 unit tests проверяют запрет вне стенда. Production legal metadata не выдумывались |
| Operator privacy UI fixtures | **23 состояния PASS**: server pagination/retry, typed offline intake, uncertain POST после закрытия/открытия формы, confirmation evidence и multi-field corrections, extension один раз, purpose/resource blocks, targeted release, authoritative termination outcome, online/offline delivery boundary, точный raw download, 422 без ложного success, late route/session guards, read-only permissions и saved presence. RU/EN, light/dark, 320/393/768/1440, 140% text/reduced motion; axe A/AA 0, overflow нет |
| Operator privacy real browser E2E | **7 групп PASS**, два обычных HTTPS-прогона подряд без флагов Chromium: настоящий Core/PG/Redis под restricted runtime role, MFA operator login, offline intake, person/contact multi-field corrections с null/boolean, extension и три block scopes/release, две одноразовые выдачи с raw SHA256/size в PG, повтор 404, online operator delivery 404, termination execution/evidence и отказ basic account. Контактные строки при termination сохраняются: это не доказательство erasure |
| Presence real browser / Redis | **PASS**: heartbeat только после сохранённого opt-in, Redis TTL 1..90 и отдельный client ID; opt-out скрывает presence, logout удаляет запись этого клиента. Visibility/offline/backoff/late A→B ответы дополнительно покрыты unit/UI tests |
| Operator read burst | **100 requests / concurrency 20 / 0 errors / p95 269 ms**, финальный HTTPS-прогон без retries; предыдущий обычный прогон p95 255 ms. Локальная короткая проверка, не production capacity |
| Configuration UI fixtures | **17 состояний PASS**: system/module values, publication acknowledgement, anonymous preview, flag module lookup/switch/range/structured properties, fresh edit, unknown write/review, confirmed delete, read-only permissions. EN/RU, light/dark, 320/393/768/1440, 140% text/reduced motion; axe A/AA 0, overflow нет. Общий admin regression: **33 состояния PASS** |
| Configuration real browser E2E | **7 групп PASS** на HTTP production preview + настоящий Core/PostgreSQL/Redis под runtime DB role: MFA operator, public/private settings, anonymous response, module detail/enable/disable, individual module setting read/update/delete, feature flag create/read/delete, basic account 403 и подтверждённые удаления с проверкой PostgreSQL. Последний read burst **100 requests / concurrency 20 / 0 errors / p95 287 ms**; это локальная короткая проверка, не production capacity |
| Configuration / email contracts | Новый `core-configuration.yaml`: **17 операций** с реальными permissions, DTO/input/size limits; все **5 OpenAPI YAML/local refs PASS**. Core renderer **5 tests / 15 assertions PASS**, новый Commercial email context unit **1 / 8 PASS**, Commercial PHPStan level 7 **0 errors** |
| Privacy contract и test setup | `core-privacy.yaml` v1.1: **24 операции** — 9 self-service, 14 operator и presence; typed filters/forms/permissions, minimal purpose-code registry, `intakeChannel`, UTC seconds, raw one-shot/no-store semantics. Все 4 OpenAPI YAML/local refs PASS; fixture использует настоящий immutable lifecycle без отключения триггеров |
| Analytics/Audit regression | **6 tests PASS**: reverse responses, old failure/finally, A→B→A, late cursor page, applied filters/dedup/retry, session invalidation |
| Billing HTTP UI fixtures | **6 сценариев PASS**: profile/edit, pending reload/double submit, partial refund reservation, receipt/history, downgrade cancellation, auto-renew/immediate cancellation |
| Billing zero-price real backend | **PASS**: SMTP registration, MFA enrollment/step-up, profile/catalog, invoice/payment, active entitlement, reload/login, auto-renew и немедленная отмена; provider charge не выполняется |
| DB role gates, обе БД | **PASS**: по 11 SQLSTATE 42501 denials; реальные audit insert/row lock/запрещённый UPDATE/retention DELETE с rollback |
| Compose credential resolution | **3 tests PASS**: resolved values/quotes, URI decoding, missing password |
| Рабочие DB roles | **Установлены**: HTTP/email/outbox runtime, scheduler maintenance; owner только migration/bootstrap |
| Ротация раскрытого Core credential | **Выполнена**: stamp изменён, старый пароль/session/challenges отозваны, MFA/roles сохранены; новый HTTP login и logout PASS |
| Рабочие данные | Core accounts/persons/roles **2/2/2**; Commercial users/accounts **6/5**, subscriptions/invoices/payments **0/0/0** сохранены |
| Runtime текущего этапа | Core production `e65faf3cf016`, Commercial `e7d22353d544`, PostgreSQL `ce4743f457af`. Рабочие environment values и mounts сохранены; PostgreSQL по-прежнему **18.6-1.pgdg13+2**. После пересоздания БД повторены login/read/logout обоих контуров: **10 Core GET + Commercial me PASS**, Core bearer → operator **401**, обычный customer → operator **403**. 5173 и health/ready 8080/8082/8090 — **200**, все 11 Platform containers healthy; MyBots не менялся |
| Composer validate/audit, npm production audit | Composer — предыдущие проверки PASS. Текущий frontend production audit: **0 vulnerabilities**; полный `npm audit --audit-level=high` PASS: **19 moderate** в dev tools, без HIGH/CRITICAL; force downgrade не применялся |
| Actionlint | **PASS** на текущем ci.yml, actionlint 1.7.11; новые workflow contract и UI regression steps включены. GitHub run не запускался |
| Shell / PowerShell / archive regression, предыдущий этап | **PASS**, заново не запускались; архив проверялся на временном fixture repository |
| Schema / DBML / reference / coverage / Pyright | **PASS**, ошибки строгих типов нового role provisioner устранены; gates не ослаблены |
| Docker/test cleanup текущего этапа | Удалены **5 test containers / 3 test volumes / 2 test networks / 2 test images**, actionlint image и **3.397 GB** build cache. Итог daemon: **13 containers / 8 used images / 4 working volumes / cache 0 B**. Постоянный credential/rollback DPAPI сохранён |

Нагрузка: настоящий Commercial HTTP под restricted runtime role, PostgreSQL, SMTP и nonce.
Четыре стадии по 15 секунд, один пользователь/сессия, смесь catalog/me/accounts/sessions,
без request retries:

| Concurrency | Requests | Errors | p95, ms | Худший route p95, ms |
| ---: | ---: | ---: | ---: | ---: |
| 1 | 510 | 0 | 40 | 43 |
| 5 | 2181 | 0 | 52 | 59 |
| 20 | 3508 | 0 | 136 | 157 |
| 50 | 3397 | 0 | 399 | 463 |

**9 596 requests, 0 errors; thresholds PASS.** Это локальный closed workload,
не production capacity и не длительный multi-user soak.

Команды: `vendor/bin/codecept run --no-colors`, `vendor/bin/phpunit --exclude-group cross-plane`,
`vendor/bin/phpunit --group cross-plane`, `vendor/bin/psalm --no-cache`,
`vendor/bin/phpstan analyse --no-progress` — только в guarded Linux test containers.
Frontend: `npm test`, `npm run typecheck`, `npm run lint`, `npm run format:check`,
`npm run build`, `npm run check:tokens`, `npm run check:styles`, `npm run check:icons`,
`npm run verify:typography` (production preview, безопасные HTTP fixtures),
`npm run test:core-auth-guard`, `node scripts/e2e-core-auth.mjs`,
`node scripts/verify-billing.mjs`, `node scripts/e2e-billing-recovery.mjs`,
`npm run verify:core-security`, `npm run test:e2e:core-security`.
Новый этап: `npm run verify:core-profile`, `npm run test:e2e:core-profile`;
оба добавлены в соответствующие browser jobs CI, mutating guard и upload evidence.
Следующий этап: `npm run verify:core-credentials`, `npm run test:e2e:core-credentials`;
также включены в CI, guard и upload. Job переименован в `Core · account browser E2E`.
`AUTH_WEBAUTHN_RP_ID/ORIGINS` настроены явно для CI origin; обычные backend suites сохраняют
свой `localhost:5173/8080` allowlist. Первые локальные full прогоны дали origin errors:
browser runtime разрешал только `localhost:5180`, а существующие API fixtures подписывают
`localhost:5173`. Functional CLI и HTTP config одноразового стенда согласованы, затем
отдельные passkey API **8 tests / 74 assertions** и весь Core suite прошли. Production
allowlist не расширялся ради тестов. В старом session UI regression добавлено ожидание
авторитетного list reload после mutation acknowledgement, без произвольного sleep.

Privacy этап: `npm run verify:core-privacy`, `npm run test:e2e:core-privacy`;
оба добавлены в workflow, mutation guard и artifact upload. Для обработки processing-information
в Core browser job включён только guarded test fixture metadata; обычные suites и production
оставляют этот флаг `0`. Первичный HTTPS прогон однажды завис на authority lookup; два
последующих реальных прогона прошли. Тогда причина транспорта не была установлена. Добавлены
ограничения ожидания: 15 секунд для metadata/submit, 60 для выдачи, явный retry безопасного
чтения и неопределённый результат POST без автоматической повторной отправки. Fixture
browser regression специально удерживает ответ и проверяет освобождение loading и retry.
Mutating E2E/load требуют env/proof одноразового runtime; рабочие БД запрещены.

Operator/presence этап: `npm run verify:core-privacy-operator` и
`npm run test:e2e:core-privacy-operator` включены в существующие CI jobs, mutation guard
и artifact upload. Повторяющийся HTTPS stall локализован в HTTP/2 frontend proxy Vite:
диагностический прогон с отключённым HTTP/2 прошёл; затем TLS dev/preview настроен через
ALPNCallback на HTTP/1.1. Два обычных HTTPS E2E подряд прошли без browser flags, финальный
`PerformanceNavigationTiming.nextHopProtocol` — `http/1.1`. Проверки сертификата сохранены;
production gateway не менялся. Это проверка локального proxy, не оценка всех HTTP/2 серверов.
Найденная Node/Vite type error исправлена; проверка `tsconfig.node.json` включена в typecheck/build.
Сохраняй timeout/uncertain-result policy: исправление транспорта не делает one-shot операции
безопасными для автоматического повтора.

Prettier **3.9.10** закреплён lockfile; один Vue attribute на строку в многострочном теге,
отдельная закрывающая скобка, LF; CLI/editor используют одну конфигурацию.
Новый GitHub run не запускался: commit/push без разрешения запрещены пользователем.
Старый GitHub SUCCESS для `12634f4` не подтверждает текущее рабочее дерево.

Одноразовый Vite на 5176 остановлен; три выбранных Billing screenshots сохранены в документации.
Удалены 31 локальный сгенерированный PNG/JSON и папка `screenshots/`, 67 временных файлов
проверки, включая приватные env/manifests/dumps и scan cache; временная папка также удалена.
Рабочий frontend 5173 отвечает HTTP 200. Постоянные DPAPI backup/credential из раздела 7 сохранены пользователю.
После нового frontend этапа также удалены 13 сгенерированных PNG/JSON и 8 временных файлов
Core UI стенда, включая manifest/env и TLS key/certificate. Preview на 5177/5178 остановлены.
Четыре новых Core Security evidence сохранены в документации; рабочий dev server 5173 оставлен.

После account/profile этапа удалён project `platform-profile-12634f4`: **3 containers /
3 volumes / 1 network**. Новые тестовые образы не создавались, использовались существующие.
Core dev/prod заменены рабочими пересборками; временный actionlint удалён. Итог daemon:
**13 containers (12 running, MyBots app Created как до проверки), 8/8 images used,
4/4 volumes used, Buildx cache 0 B**; очищено 1.089 GB build cache.
Preview 5178/5179 остановлены, рабочий 5173 отвечает 200. Удалены 21 сгенерированный
PNG/JSON и 12 временных файлов, включая env/manifest/fixture logs/TLS private key;
пустые временные каталоги также удалены. Четыре выбранных profile evidence сохранены выше.
Постоянные пользовательские DPAPI backup/credential сохранены. HEAD остаётся `12634f4`,
commit/push/GitHub run не выполнялись.

После passkeys/identities этапа удалён `platform-credentials-12634f4`: **3 containers /
3 volumes / 1 network**. Временные preview 5180/5182 остановлены, actionlint image удалён.
Новых application/test images не создавалось; Core получил WebAuthn env в прежних образах.
Итог: **13 containers / 8 used images / 4 working volumes / Buildx cache 0 B**.
Девять контейнеров вне Core сохранили ID/image/start/mounts; четыре Core healthy, образы
и mounts сохранены. Рабочий RP/origin проверен по фактическим контейнерам, HTTP health — 200;
Core counts **2/2/2**, Commercial **6/5/0/0/0** совпали с исходными. Рабочий frontend 5173
сохранён. Четыре новых PNG оставлены в evidence, сгенерированные screenshots/results и
приватные env/manifests/logs/сертификат/ключ удалены. Постоянные DPAPI backup/credential сохранены.

После privacy этапа удалён `platform-privacy-12634f4`: **3 containers / 3 volumes /
1 network**. Новые test images не создавались; production Core HTTP image пересобран
и применён как `240d889adf3d`. Три dev/worker контейнера используют прежний dev image
с актуальным bind source, config rebuild и restart. Production image не содержит tests,
`.env`, `.git`, coverage entrypoint и PHPUnit; writable runtime paths проверены.
Все четыре Core healthy, девять остальных ID/image/start/mounts не изменились;
Core **2/2/2**, Commercial **6/5/0/0/0** сохранены, live/ready 8080/8082/8090 — 200.
Новый discovery на обоих Core HTTP требует авторизацию (401), не попадает в fallback 404.
Итог Docker: **13 containers (12 running + прежний MyBots Created) / 8 used images /
4 working volumes / Buildx 0 B**. Удалены actionlint и 1.004 GB записей build cache
(часть слоёв shared с рабочими images). Preview 5184/5186 остановлены; 5173 сохранён.
Удалены 26 сгенерированных PNG/JSON и 12 приватных временных файлов, включая TLS key,
certificate, env, manifest и fixture logs; пустые каталоги тоже удалены. Четыре новых
evidence PNG сохранены в документации. Постоянные DPAPI backup/credential сохранены.
Новый production image этим этапом полностью Trivy не пересканирован; inventory ниже
остаётся историческим, production verdict не повышен. HEAD не менялся, commit/push не было.

После operator/presence этапа удалён `platform-operator-12634f4`: **3 containers /
3 volumes / 1 network**. Core production image `c852697aef74` применён к HTTP fast service;
dev/два workers перезапущены после config rebuild на прежнем `61a0f7113067`. Все четыре
healthy, writable paths и отсутствие tests/.env/.git/PHPUnit в production image проверены.
Девять остальных containers и все working mounts сохранены. Core counts **2/2/2**,
Commercial **6/5/0/0/0** прежние; live/ready 8080/8082/8090 — 200, новый options требует auth.
Финальный daemon: **13 containers (12 running + прежний MyBots Created), 8/8 images used,
4/4 volumes used, Buildx cache 0 B**. Временный actionlint image удалён; очищено 1.089 GB
build cache (shared layers рабочих images сохранены). Preview 5188/5190 остановлены,
test HTTP 18088 закрыт; рабочий frontend 5173 отвечает 200. Удалены **42** сгенерированных
PNG/JSON и **20** временных файлов стенда, включая env/manifests/logs и TLS private key/cert;
пустые каталоги удалены. Четыре выбранных operator evidence PNG сохранены выше.
Постоянные DPAPI backup/credential сохранены. HEAD `12634f4` прежний, commit/push/GitHub run
не выполнялись. Новый production image полностью Trivy этим этапом не пересканирован.

После configuration/email этапа удалён `platform-config-12634f4`: **3 containers /
3 volumes / 1 network**. Новые test images не создавались. Рабочие Core production
`b06d2ab8b0e8` и Commercial `ba8b712a517d` применены к Core fast HTTP и трём Commercial
HTTP/worker containers; остальные девять сохранили ID/image/start/mounts. Все работающие
контейнеры healthy. Core counts **2/2/2**, Commercial **6/5/0/0/0**, schema catalogs и
migration history обеих БД сохранены. Рабочий 5173 и health 8080/8082/8090 отвечают 200.
Заданный пользователем пароль повторно проверен API и браузером, повторной ротации не было.
Удалены preview 5192, test HTTP 18090, actionlint image и **1.159 GB** build cache.
Итог: **13 containers (12 running + прежний MyBots Created), 8/8 images used, 4/4 working
volumes, Buildx cache 0 B**. Удалены **13** сгенерированных screenshot/result файлов,
отдельный Vitest JSON и **15** временных файлов, включая env/manifests/inspect snapshots/logs;
пустые каталоги удалены. Четыре выбранных configuration PNG сохранены в evidence.
Постоянные DPAPI backup/credential сохранены. HEAD `12634f4`, commit/push/GitHub run отсутствуют.
В том предыдущем этапе новый image scan не выполнялся. Свежий scan этапа 14 приведён ниже;
production verdict остаётся **NOT_READY**.

Текущий этап typed workflows использовал отдельный project `platform-forms-12634f4`.
После финальных suites удалены его **5 контейнеров, 3 тома, 2 сети, 2 test images**,
временный actionlint image и **3.397 GB** build cache. В daemon осталось **13 контейнеров
(12 running + прежний MyBots Created), 8/8 images used, 4/4 working volumes, cache 0 B**.

При сборке стенда был пересобран общий тег `platform-postgres:local`: Docker удалил старые
image metadata, хотя старые рабочие контейнеры ещё работали. Перед их пересозданием сделаны
два `pg_dump -Fc` и проверены backup catalogs. Новый бинарник имеет ту же точную версию
**18.6-1.pgdg13+2**; оба PostgreSQL контейнера пересозданы с прежними volumes и environment.
Затем перезапущены семь Platform PHP services для обновления соединений. Working counts
**2/2/2** и **6/5/0/0/0** сохранены, все Platform healthchecks healthy. После этого повторены
оба login/read/logout и authorization denials. Core fast image — `e65faf3cf016`, Commercial —
`e7d22353d544`, PostgreSQL — `ce4743f457af`; MyBots не пересоздавался.

Отдельно проверен пересобранный Core production HTTP **8082**: login, все четыре новых
авторизованных directory GET с проверкой envelope/items/total, logout **204**. Временный
preview **5194** остановлен. Удалены **77** сгенерированных PNG/JSON и **59** временных
файлов стенда, включая env/manifests/logs и оба временных DB dumps после проверки данных.
Пять выбранных workflow PNG сохранены в `docs/evidence/current`; постоянные DPAPI credential
и rollback сохранены. HEAD `12634f4` прежний; staged changes, commit/push и новый GitHub run отсутствуют.

Обнаруженная опасность cleanup: `down --volumes` с полным базовым Compose также пытается
удалять явно именованные рабочие `platform_postgres_data` и `platform_redis_data`.
Docker отказал, потому что они используются; тома сохранены и сверены. Для следующего локального
cleanup **не применяй `--volumes` к полному Compose**: сначала `down` без удаления volumes,
затем удали только заранее проверенный список test volumes по project labels. Общий
`docker system prune --volumes` запрещён. Это правило особенно важно при остановленном рабочем стенде.

Kubernetes управляет размещением, перезапуском и масштабированием контейнеров в кластере.
Для этого локального Compose-стенда он не требуется. Фактический Docker Desktop Kubernetes
выключен (`disabled`, cluster stopped); настройка не изменялась.

### Остаточный security inventory образов

**Свежий inventory 5 ноября 2026**, официальный Trivy **0.76.1**, SHA256 дистрибутива проверен.
Vulnerability DB UpdatedAt **2026-11-05T01:19:45.907162652Z**,
DownloadedAt **2026-11-05T04:55:25.5574063Z**. Все шесть scans завершены успешно:
`--image-src docker --scanners vuln --severity HIGH,CRITICAL`, без `ignore-unfixed`.
Сканировались точные итоговые образы, применённые к рабочим сервисам.

| Образ | Image ID | HIGH+CRITICAL | CRITICAL | Fixable |
| --- | --- | --- | --- | --- |
| Core prod | `ecc0958301a0` | 60 | 1 | 0 |
| Core dev | `c437b7b5df65` | 81 | 1 | 0 |
| Commercial app | `112be5f2699c` | 60 | 1 | 0 |
| PostgreSQL | `6ff563e8cd95` | 63 | 1 | 0 |
| Redis | `21090af93f83` | 0 | 0 | 0 |
| Mailpit | `9b3a463be153` | 0 | 0 | 0 |

Свежая DB сначала выявила **CVE-2026-109518** в `apt` и `libapt-pkg7.0` **3.0.3**
в Core dev и PostgreSQL. Они обновлены до **3.0.4**, добавлены build version guards,
образы пересобраны; повторный scan подтверждает отсутствие этих fixable findings.
В PostgreSQL обновлялся только package manager; PostgreSQL binary/version и PGDATA сохранены.
Источник исправленной версии: [Debian security tracker](https://security-tracker.debian.org/tracker/CVE-2026-109518).

Сканер сообщает **CVE-2026-6653 / libxml2**, CRITICAL, установленная версия
`2.12.7+dfsg+really2.9.14-2.1+deb13u3`, FixedVersion отсутствует; finding присутствует
в обоих PHP images и PostgreSQL. Это scanner inventory, достижимость конкретного attack path
в Platform отдельно не доказана. Отсутствие
FixedVersion не означает отсутствие риска. Production release требует исправления либо
явного решения ответственного о принятии конкретного остаточного риска; такого решения нет.
Чистый Composer audit и fixable gate не заменяют общий inventory.

Полные шесть JSON, scanner checksums, точные image IDs и metadata DB сохранены вне Git:
`C:\Users\Yuran\AppData\Local\Platform\security\20261105-platform-images.zip`.
SHA256: `d41258c4d7cbcd296ba8bbd25a614815a3b9a45728c95ac20bf9e84f08cbd3f9`.
Старый локальный cache сканера завершился bbolt panic; DB загружена заново с официального
`ghcr.io/aquasecurity/trivy-db:2`, все шесть scans повторены. Ошибка инструмента не считалась
успешным результатом. [Параметры Trivy](https://trivy.dev/docs/dev/references/configuration/cli/trivy_image/).

## 7. Что делать дальше — по приоритету

### Закрыто в коде и рабочем локальном runtime

1. **External MFA recovery:** UI start/callback, state hash без сырого proof/code в storage,
   одноразовый callback и очистка URL, Google/Telegram login continuation. Семь HTTP actions
   исправлены: provider читается из `CurrentRoute`. Real backend E2E использует test OAuth transport.
2. **Admin isolation:** cache/in-flight scoped к session epoch; старые ответы, refresh,
   decoration, dialogs и toasts не пишутся в новую сессию; свежий 403 очищает cache.
3. **DB roles:** runtime/maintenance/migrator разделены в рабочих контейнерах. Runtime не
   получает DDL/TRUNCATE/role management/audit UPDATE/DELETE. Maintenance может выполнять
   retention, но Core migration 034 запрещает изменение audit ID. После миграций выполняй
   `python scripts/database/provision-runtime-roles.py`.
4. **Credential:** раскрытый пароль действительно работал и ротирован через `core:rotate-password`.
   Старые sessions/challenges отозваны; regression закрывает старый first factor после ротации.
   MFA/роли сохранены. Новый пароль не выведен в логи и не записан в репозиторий.
5. **Billing UX:** server recovery `/billing-state`, history/search, сохранённый profile,
   draft/pending recovery, partial refund с учётом pending quantities, context/request guards.
   Исправлены потеря edits перед save, unmount формы при account refresh, nested busy guard
   отмены auto-renew и сериализация пустого catalog features как массива.
   Invoice/payment create теперь возвращают полный DTO по существующему OpenAPI; nullable
   периоды неоплаченной подписки и invoice принимаются без выдуманных дат. После MFA retry
   выводится сообщение успеха; completed onboarding не блокирует следующую подписку после отмены.
   Новые regression tests и реальный zero-price E2E включены в workflow.
6. **Новый frontend этап — Core Security:** отдельные URL `/admin/security/mfa` и
   `/admin/security/sessions`, общий layout вкладок и корректная sidebar selection.
   Typed guards соответствуют camelCase Core DTO и array sessions. MFA DELETE отправляет
   password/code в body. QR и manual key, verification errors, recovery acknowledgement/copy,
   regenerate/disable warning и явный re-login при `auth.recent_required`; секреты только в памяти,
   очищаются при закрытии/unmount. Сессии показывают устройство/IP/приблизительную локацию,
   даты и current badge; отзыв требует подтверждения, текущая сессия сохраняется.
   Live backend READ двух endpoints и 18 fixture browser states проверены отдельно.
   Все 8 новых операций также прошли настоящий guarded Core browser lifecycle на HTTPS
   production preview с restricted DB role. Новый тест включён в Core browser CI и artifact upload;
   self-signed trust exception ограничено test request context, глобальный TLS не отключался.
   Analytics/Audit защищены поколением запроса, account/session и lifetime; пагинация
   использует applied filters, убирает дубликаты и сохраняет строки при ошибке следующей страницы.

7. **Закрыт следующий frontend этап — Core own account:** `/admin/profile` и
   `/admin/profile/consents`, sidebar и вкладки, доступ self-service без admin permissions.
   Язык/часовой пояс с поиском IANA и browser shortcut, sparse save, отмена edits и ошибки
   с retry; шесть server preferences на общих анимированных switches. Null reset сбрасывает
   только эти листья, сохраняет module overrides и language/timezone. Сохранённые account
   motion/transparency действуют внутри Core; browser appearance и OS reduced motion сохраняются.
   Store очищается по session epoch, поздние ответы отвергаются. Прозрачность управляется
   общей CSS variable policy, новые UI timings не захардкожены.
   Согласия: карточки/статусы/даты, plaintext document dialog, explicit unchecked acknowledgement,
   grant/withdraw confirmation, cancellation, retry и повторный просмотр после 409.
   Готовый заменяемый RU/EN контент каркаса —
   [consentTemplates.ts](../apps/frontend/src/modules/admin/profile/consentTemplates.ts).
   Пустой production registry показывает эти примеры; просмотр шаблона не записывает согласие.
   Зарегистрированные документы проходят реальный API lifecycle. Добавлен optional plaintext
   document в ConsentDefinition, SHA256 проверяется по UTF-8 тексту; reviewed version/hash
   нельзя подменить при grant. Legacy empty-body клиенты сохранены.
   Исправлены два найденных integration дефекта: OpenAPI описывал schema v1 вместо фактической
   v2, preferences PATCH мог перезаписать пропущенное поле устаревшим principal. Теперь
   SQL обновляет только явно переданные locale/timezone; concurrent PATCH покрыт реальной БД.
   Проверки: 10 новых Vitest tests, unit/functional/API regressions Core, 11 fixture UI states
   с axe/responsive и три группы настоящего browser/HTTP/PG/Redis lifecycle.
   Пересобраны Core dev `61a0f7113067` и prod `e88ecf9b2240`, четыре Core PHP-контейнера
   пересозданы и healthy. Остальные девять контейнеров сохранили ID/image/start/mounts;
   рабочие account/person/role и Commercial row counts совпали, HTTP health 8080/8082/8090 — 200.

8. **Закрыт следующий frontend этап — passkeys и внешние аккаунты.** Новые URL
   `/admin/security/passkeys` и `/admin/security/identities`; кнопка passkey login на
   `/admin/login`. Typed forms без JSON/UUID, именование ключей, даты/backup state,
   destructive confirmations, empty/loading/error/retry и explicit recent-auth re-login.
   На mobile вкладки прокручиваются горизонтально, active tab остаётся видимой без
   вертикального скачка. Все RU/EN labels/success/errors реактивны; timings и цвета общие.
   [webauthn.ts](../apps/frontend/src/shared/webauthn.ts) конвертирует binary/options/response,
   передаёт native AbortSignal и различает cancel/unsupported/insecure/origin/conflict.
   Passkey login проходит общий auth state machine с обязательной MFA/password-change continuation.
   Серверные UV/RP/origin/challenge/client/session/last-method ограничения сохранены.
   [externalLink.ts](../apps/frontend/src/modules/admin/auth/externalLink.ts) открывает popup
   синхронно по клику, не передаёт ему Core tokens, обнуляет opener, использует одноразовый
   state hash marker и same-origin BroadcastChannel; callback очищает URL. Исходная вкладка
   завершает link под своей session. Предусмотрены blocked popup, cancel, timeout, ownership
   conflict, last method, recent auth, unmount/session-epoch cancellation и устаревший callback.
   Внешние avatar URL автоматически не загружаются.
   Добавлены **14 meaningful Vitest tests** (binary/DTO/cancel/late results/popup binding),
   12 UI fixture states с native virtual authenticator, 6 реальных E2E групп и read burst.
   Переменные WebAuthn RP/origins раньше не передавались Compose-сервисам; теперь передаются
   через общий Core environment и test override. Применены к четырём рабочим Core контейнерам,
   без создания новых образов или миграций. По умолчанию использовать **http://localhost:5173**;
   IP `127.0.0.1` не является заменой configured RP `localhost`.
   Контракт [auth-passkeys.yaml](../contracts/openapi/auth-passkeys.yaml) приведён к реальному API;
   реализация browser boundary сверена с [W3C WebAuthn Level 3](https://www.w3.org/TR/webauthn-3/).
   Полноценный physical Windows Hello/Touch ID/phone/security-key matrix и live Google/Telegram
   требуют отдельных проверок. Новых production bypass/fixture flags не добавлено.
   Дополнительная проверка всех OpenAPI refs нашла старую битую ссылку Commercial isolation
   response на отсутствующий `schemas/Object`: заменена на точную nonce schema. Проверка
   локальных refs добавлена в существующий `control-config-lint.php` и CI gate; все три
   контракта проходят. Runtime Commercial этим исправлением не менялся.

9. **Закрыт следующий frontend этап — Core self-service privacy.** Две страницы
   `/admin/profile/privacy` и `/admin/profile/privacy/:id`, вкладка в «Моя учётная запись».
   [CorePrivacyRequestsView.vue](../apps/frontend/src/modules/admin/profile/CorePrivacyRequestsView.vue)
   показывает карточки, страницы по 25 + sentinel, retry без потери строк и форму восьми типов.
   Для представительства — поиск по безопасному серверному списку, scopes и disabled варианты;
   для erasure/termination требуется отдельное подтверждение. Неопределённый POST блокирует
   повтор до явной проверки списка, submit-only grant не обещает права чтения.
   Новый authenticated `GET /api/auth/me/privacy/representations` возвращает только active/verified
   полномочия текущего human Person с privacy scopes: без proof, evidence и общего person lookup.
   Own list сохраняет legacy array и получает `limit` 1..100 / `offset` 0..100000. Request DTO
   теперь содержит nullable `representationId`; SQL migrations и схемы БД этим этапом не менялись.
   [CorePrivacyRequestView.vue](../apps/frontend/src/modules/admin/profile/CorePrivacyRequestView.vue)
   использует authoritative status/deadline dates, показывает terminal states и отдельно
   проверяет право выдачи. Документ открывается в глобальном fullscreen dialog; сохраняются
   **исходные raw bytes**, а не pretty-printed копия. Они сверены с PG execution SHA256/size.
   Серверная выдача stateful/одноразовая: после выполнения request становится completed,
   повтор получает 404. Поэтому transport имеет `retryTransient:false` только для этих GET;
   обычное обновление access token после 401 сохранено. Повторная сериализация, silent retry
   после 502/503/504 и сохранение содержимого документа в browser storage не допускаются.
   Ответ остаётся доступен в памяти при сбое metadata refresh, очищается при route/logout;
   wrong-subject/schema payload отвергается до preview/download. Blob URLs освобождаются.
   Request generation/session epoch guards закрывают поздние detail/page/document ответы.
   Добавлены 19 frontend regressions и 8 backend tests, новый
   [контракт](../contracts/openapi/core-privacy.yaml), fixture и настоящий E2E в CI.
   Для processing-information всё ещё нужны настоящие operator name/address и purpose
   disclosure definitions. Test-only synthetic registry это требование не закрывает;
   production при неполной конфигурации сохраняет отказ. Не объявляй этот release READY.

10. **Закрыт следующий frontend этап — operator privacy requests и presence.**
    `/admin/privacy` получил typed requests tab и `/admin/privacy/requests/:id` вместо
    generic request actions. Реализация:
    [PrivacyOperatorRequests.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequests.vue),
    [PrivacyOperatorRequestView.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperatorRequestView.vue),
    [PrivacyOperationDialog.vue](../apps/frontend/src/modules/admin/privacy/PrivacyOperationDialog.vue),
    [privacyOperator.ts](../apps/frontend/src/shared/api/core/privacyOperator.ts).
    Server filters/status/type/overdue, пагинация с сохранением applied filters и строк при ошибке;
    paginated person picker, проверенный offline intake, field errors и explicit acknowledgements.
    Поиск person picker действует по загруженным страницам; дополнительная загрузка доступна,
    полный серверный поиск не заявляется. Confirmation evidence, person/contact corrections
    несколькими полями за один POST, extension только один раз, subject/purpose/resource blocks,
    targeted release и termination с авторитетным outcome/execution reference — через формы.
    Контакты ограничены субъектом обращения; terminal state нельзя назначить вручную.
    Неопределённый mutation outcome блокирует следующий submit до явной проверки сервера,
    включая закрытие и повторное открытие intake dialog. Late route/session responses очищаются.
    Операторская выдача разрешена только verified offline intake; онлайн-обращения остаются
    в self/representative flow. Nullable `intakeChannel` добавлен в DTO без schema migration;
    legacy DTO без поля принимается клиентом с безопасно скрытой кнопкой выдачи.
    Новый `GET /api/core/privacy/requests/options` требует read permission и отдаёт только
    отсортированные purpose codes, без evidence, персональных данных и полного policy registry.
    Реальный E2E нашёл серверный дефект: Yii route defaults передаются строками, а actions
    сравнивали `operator === true`. Теперь используется явный строковый mode `intake`;
    offline 200 / повтор 404 / online operator 404 проверены API и браузером. Строгий timestamp
    API сохранён, клиент нормализует receivedAt/expiresAt к UTC с точностью до секунд.
    Одноразовый response показывает глобальный fullscreen dialog и скачивает исходные bytes,
    сверенные с PG SHA256/size; transient retry выключен, неопределённая выдача не повторяется.
    [presenceStore.ts](../apps/frontend/src/core/admin/presenceStore.ts) и
    [usePresenceHeartbeat.ts](../apps/frontend/src/core/admin/usePresenceHeartbeat.ts) отправляют
    heartbeat только для human Core session после сохранённого show_online_status=true,
    когда вкладка видима и online. Интервал 30 секунд, timeout 10, backoff 60/120, один запрос;
    hide/offline/opt-out/logout/session change отменяют ожидание. Late ответы A не меняют B.
    Состояние показывается в privacy card настроек. Случайный client ID живёт только в памяти
    страницы, одинаков для Core auth/HTTP; logout удаляет presence именно этого клиента.
    Commercial transport и browser storage не получили этого идентификатора.
    Исправлен найденный axe дефект: dark primary hover в canonical design tokens изменён
    с #5B86FF на #3868ED, контраст белого текста 3.334 → 4.804; tokens.css сгенерирован,
    accessibility rule не подавлялся. Анимации/отступы используют общие переменные.
    Добавлены **18 frontend tests**, **2 backend API tests**, 23 UI fixture states и 7 реальных
    HTTPS E2E групп с коротким read burst. SQL schema/миграции/рабочие DB roles не менялись.
    На момент того этапа legal holds, representations, erasure и incidents оставались generic;
    они переведены на явные поля и справочники этапом 13 ниже.

11. **Закрыт следующий frontend этап — три оставшихся Core API и configuration forms.**
    [AdminModulesView.vue](../apps/frontend/src/modules/admin/AdminModulesView.vue) заменяет
    generic modules table карточками, поиском, актуальным module detail и подтверждённым
    enable/disable. Настройки выбранного модуля открываются здесь же, без второго списка модулей.
    [AdminSettingsView.vue](../apps/frontend/src/modules/admin/AdminSettingsView.vue) содержит
    системные настройки, feature flags и проверку публичной конфигурации. Вкладки/действия
    учитывают отдельные read/manage permissions; доступ к flags больше не требует settings.read.
    Новый [configuration.ts](../apps/frontend/src/shared/api/core/configuration.ts) проверяет DTO,
    module/key ownership и передаёт AbortSignal/timeout. До edit/delete читается актуальная запись.
    [ConfigValueEditor.vue](../apps/frontend/src/modules/admin/configuration/ConfigValueEditor.vue)
    вводит text/number/boolean/null/object/list через поля, без JSON textarea. Сохраняются
    false/0/null и вложенные значения; проверяются числа, duplicate/prototype keys, secret-like
    keys и лимит 16 KB. Это редактор структуры значений: бизнес-схемы отдельных модулей сервер
    пока не отдаёт, `settingsSchemaVersion` показывается как metadata, не как выдуманная схема.
    Module setting form не отправляет isPublic. Для публикации system value, удаления,
    module lifecycle и flag change нужны явные acknowledgements. Feature form выбирает модуль
    по имени/code, использует общий switch и keyboard-accessible range. Directory без read
    permission не запрашивается, существующая binding сохраняется. Empty flag config `[]`
    нормализуется из PHP DTO в объект; фактическая effective availability этим UI не вычисляется.
    [ConfigPublicPanel.vue](../apps/frontend/src/modules/admin/configuration/ConfigPublicPanel.vue)
    действительно вызывает `/api/public/config` с `credentials:omit`, без Bearer и cache:
    оператор проверяет опубликованные значения. Неизвестные public keys автоматически не
    применяются к теме/auth/UI. Приватные settings/module settings/flags не публикуются клиентом.
    [useConfigCollection.ts](../apps/frontend/src/modules/admin/configuration/useConfigCollection.ts)
    хранит данные только в lifetime текущей Core session. Late responses отсекаются; 403 очищает
    records/forms, 503 сохраняет строки и блокирует mutations до успешного refresh. Неопределённый
    write не повторяется; закрытие dialog блок не снимает. Успешный mutation acknowledgement
    отделён от последующего неудачного list reload. После logout форма и values очищаются.
    17 операций теперь CODE, **14 переведены из GENERIC, 3 — из НЕТ**. В Core больше нет
    обнаруженных HTTP operations без caller. **На момент этого configuration этапа** оставались
    53 generic Core operations и 10 Commercial operations без binding; они закрыты этапом 13 ниже.
    Весь backend готовым не объявлять.
    Добавлены **23 frontend tests**, 17 fixture UI states и 7 real browser groups с PG assertions;
    оба browser runners включены в существующий CI/guard/artifact upload. SQL schema не менялась.
    По запросу пользователя также уточнены password-reset/changed email subjects/body:
    `[Platform Core]` и `[Platform Cabinet]` различают два независимых аккаунта. Четыре письма
    текущего сброса — request + changed для каждого контура. Core выбирает локаль, Commercial
    security templates пока фиксированы на английском; полная email localization — отдельный пробел.

12. **Унифицированы кегли и начертания во всём frontend.**
    В настройках/модулях браузер подставлял native H2 **24/24/700**, H3 **18.72/24/700**
    и body **16/24**, тогда как обзор использовал H2 **20/28/600**, body **14/20**.
    Некоторые подписи наследовали line-height 24 вместо 16, профиль — heading weight 700.
    [typography.css](../apps/frontend/src/shared/styles/typography.css) задаёт defaults
    заголовков, labels/legend, small и mono через canonical tokens; body использует medium.
    Desktop H1/H2/H3: **24/32, 20/28, 18/24**, weight 600; mobile: **22/28, 18/24, 16/20**.
    Body medium **14/20**, small **12/16**; крупные поля L сохраняют намеренный **16/24**,
    поля M используют **14/20**. Размер/межстрочный интервал масштабируются вместе.
    Локальные роли в страницах/контролах дополнены соответствующими line-height/weight/tracking.
    Canonical JSON, исходные шрифты и иконки в этом проходе не изменялись.
    [AppInput.vue](../apps/frontend/src/shared/ui/fields/AppInput.vue) применяет layout class/style
    к внешнему wrapper: max-width поискового поля теперь ограничивает всю рамку. Native
    attributes/listeners остаются на input; focus/autofill не меняют кегль.
    Style gate запрещает числовые size/weight/line-height вне font-face и требует явные
    line-height/weight при token font-size. [verify-typography.mjs](../apps/frontend/scripts/verify-typography.mjs)
    проверяет computed styles, семантические роли, local fonts и overflow всех named routes;
    добавлен в browser CI matrix. Добавление маршрута без проверки ломает этот gate.
    Зелёная style matrix не закрывает функциональные/API/security пробелы этого аудита.

13. **Закрыты 53 прежние общие Core operations и 10 неподключённых Commercial операций.**
    `workbench/catalog.ts` задаёт whitelist полей и реальные методы/permissions; JSON request
    editors удалены вместе с неиспользуемыми `AdminOperationPanel.vue` и `types.ts`.
    Сохраняются false/0/null, неизвестные поля не отправляются, path IDs не смешиваются с body.
    Исправлены прежние неверные payloads erasure (`sourceRequestId`, без requestId/scopeType)
    и incident transition (`status`, без toStatus). Person edit читает актуальный detail;
    permission replacement загружает текущий набор, protected/system roles не редактируются.
    Lifecycle actions зависят от status. Audit получил настоящие серверные filters.
    Selectors имеют поиск и явную дозагрузку; без read permission справочник не запрашивается.
    Четыре privacy directories возвращают только id/name/status; distribution ограничен actor scope.
    Результаты показаны fullscreen, credential secret очищается после закрытия. Late responses
    отсекаются по generation/session epoch; uncertain writes не повторяются автоматически.
    `/operator` — отдельная memory-only session на существующем Commercial issuer, cookies
    не используются, customer/Core stores не заимствуются. Реальные authorization records
    проверяются сервером при каждом чтении/изменении. `/operator/me` имеет явный 403 для
    неоператора; ресурсные отказы сохраняют существующую concealment policy 404.
    Financial evidence review не меняет references/hash, self-review запрещён, MFA/recent auth
    проверяются сервером. Step-up находится вне формы операции, поля сохраняются, retry явный.
    Все экземпляры security forms имеют уникальные ID. Для direct membership нужны существующий
    active Control User, подходящая role, acknowledgement и MFA; invitation flow сохранён.
    Нужные 8 read models добавлены в OpenAPI; schema/миграции не менялись.
    UI fixtures проверяют исходные 63 операции; это не доказательство реальной оплаты либо
    production policies. Рабочие пользователи не получали новые операторские права.
    Обнаруженный UI regression исправлен: длинные badge labels переносятся, admin page title
    занимает оставшееся место и сокращается до ellipsis вместо столкновения с действиями.
    При 403 ResourceTable очищает не только строки, но и старые total/offset; error больше
    не сопровождается прежним счётчиком пагинации. Проверены 320 px/RU/140% текста.

Новый пароль и rollback backup находятся вне репозитория, под ACL Windows user/SYSTEM
и DPAPI CurrentUser: `%LOCALAPPDATA%\Platform\private\20261103-073009`.
`core-operator.dpapi` содержит новый credential, `rollback.dpapi` — backup БД и `.env`.
Не добавляй их в Git. Раскрытый старый пароль при rollback не восстанавливай.

4 ноября по прямому указанию пользователя пароль `ura.nikogosuan@gmail.com` синхронизирован
в Core и Commercial через штатные password-reset flows. В обоих контурах проверены
HTTP login → me → logout. Роли и MFA сохранены, прежние сессии отозваны штатным reset;
DPAPI credential обновлён. Значение пароля в репозитории не хранится.
Без нового явного указания пользователя этот пароль не менять.

Получить credential локально, без печати пароля в вывод агента:

```powershell
$credential = ./scripts/operations/read-private-credential.ps1 -Path "$env:LOCALAPPDATA\Platform\private\20261103-073009\core-operator.dpapi"
# При необходимости пользователь сам копирует пароль:
$credential.GetNetworkCredential().Password | Set-Clipboard
```

### P0 перед production: security и внешние интеграции

- Разобрать оставшиеся HIGH/CRITICAL distro findings, включая libxml2; fixable=0 не означает
  безопасность. Решения о принятии риска нет, verdict остаётся **NOT_READY**.
- Проверить реальные OAuth credentials, HTTPS redirect URI, consent/cancel/revoke и recovery
  с живыми Google/Telegram accounts. Изолированный transport fixture этого не доказывает.
- Заполнить настоящие operator identity/address и definitions всех production processing
  purposes; проверить выдачу processing-information с этими данными. Не копировать test
  metadata в production и не выдавать произвольный юридический текст за настроенный реестр.
- Различать coverage исходников и production приёмку: 215 прикладных operations теперь имеют caller, но весь lifecycle каждого сценария на production не подтверждён.

### P1 до полной приёмки Billing: внешняя среда и периодические процессы

Локальная платная цепочка с настоящим backend и синтетическим provider wire **закрыта
этапом 14**: checkout → authoritative webhook → receipt → entitlement, uncertain/decline/
capture, вторая вкладка/reload, idempotency, paid upgrade, scheduled downgrade/cancel,
partial/full refund и over-refund denial. Повторять её как «ещё не реализованную» не нужно.

Остаётся приёмка с настоящими sandbox credentials YooKassa: внешнее подтверждение,
доставка webhook по публичному HTTPS, фактический чек и refund sandbox. Сейчас ключ пуст,
а return URL в рабочем окружении шаблонный; синтетические значения не подставлялись.
Google/Telegram OAuth credentials также пусты в `.env` и работающем Core. Если секреты
появятся в другом local secret source, сначала безопасно проверь конфигурацию, не печатая
значения. Затем пройди живые consent/cancel/revoke/recovery и оба контура авторизации.

Периодическая локальная цепочка закрыта этапом 15: shared isolated PHP/PG clock,
настоящий scheduler, downgrade/renewal/dunning, provider outage, UI и late settlement.
Ограничения clock и синтетического provider wire описаны выше. Следующий шаг — повторить
существенные сценарии в sandbox внешнего провайдера и проверить production scheduling/alerts.
Отмена auto-renew сохраняет текущий период; немедленная отмена и refund — разные действия.

### P2. Auth races, role UX, pagination и данные

- Предыдущие 53 общие Core operations и 10 Commercial gaps закрыты в коде текущего этапа.
  Следующий frontend этап — приёмка полноценных workflows с реальным backend, production
  операторскими назначениями и representative policies, а не добавление неиспользуемых wrappers.
  Сохраняй source permission parity, session races, keyboard/axe и state-aware confirmations.
  Прямые signing/credential/worker CRUD endpoints для счётчика coverage не создавать.
- Прячь/disable actions по permissions и protected/system state; серверные проверки обязательны.
- Dashboard distribution по первой сотне не выдавай за полный набор. Entity pickers текущего этапа умеют явно загружать следующие страницы; поиск ограничен загруженными записями и подписан соответствующим образом.
- Для новых Billing/Usage/Analytics/Audit и Core admin API/auth нужны отдельные meaningful
  tests; общий счётчик tests не является line/branch coverage этих модулей.
- Локальная приёмка двух независимых операторов закрыта этапом 14. Перед production
  проверь настоящие назначения и evidence storage policy. Не выдавай рабочие права автоматически ради UI smoke.
- Зафиксируй release scope для Education/deployment screens: у CLI/worker операций нет
  соответствующего публичного operator HTTP API. Для них сначала нужен backend contract.
- Уменьши стартовый JS chunk 636.46 kB по измеренному route/dependency профилю; warning пока открыт.
- Dev chains glob/minimatch исправлены этапом 15, npm audit **0**. Сохраняй отдельный security
  regression gate и совместимость старого callable minimatch для ESLint при будущих обновлениях.

## 8. Документация и критерии закрытия

Сохраняй README запуска, архитектурные границы, API/event contracts, миграции, schema catalogs,
privacy/security/backup/restore/provisioning runbooks. Они нужны разработчику и эксплуатации;
generated coverage участвует в CI, его нельзя просто удалить как «лишний документ».

Устаревшие датированные аудиты и разовые smoke-скрипты не возвращать. Инвентарь/статусы
этого прохода хранить здесь. Архитектура и сценарии описаны в `docs/frontend/README.md`;
`apps/frontend/README.md` содержит команды запуска/проверки и ссылку на архитектуру. Оба
обновлены для раздельных сессий, external recovery, Billing и актуальных E2E boundaries.

Для каждой следующей задачи обнови здесь: поведение до/после, путь к реализации,
точную команду, результат и оставшуюся границу. Завершение означает:

1. Все изменённые contracts/types/source согласованы; generators и CI gates проходят без suppressions.
2. Изменённые пользовательские сценарии проходят с реальным isolated backend и negative cases.
3. Нет layout shifts/overflow в изменённых состояниях на 320/393/768/1440 px, EN/RU,
   light/dark, reduced motion и увеличенном шрифте; есть screenshots и ручной осмотр.
4. Поведение доказано на реально пересобранных HTTP/worker images, не только host unit tests.
5. Тестовые контейнеры/тома/сети/секреты удалены, рабочие данные и MyBots сохранены.
6. Проверен именно новый GitHub run для отправленного SHA; не писать «CI зелёный» по старому запуску.
