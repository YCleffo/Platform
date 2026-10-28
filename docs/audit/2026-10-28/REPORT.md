# Проверка Platform — 2026-10-28

**Вердикт: `CORE_NOT_READY`.** Проверка конечного набора сценариев не означает
проверку всех возможных комбинаций бизнес-состояний. Production capacity,
реальные provider operations и инфраструктурная готовность отдельно не приняты.

Исходная ревизия: `e242a43`, затем текущий working-tree diff. Изменения не
опубликованы; remote CI для этих bytes не запускался. SHA-256 исходников и
проверенные результаты перечисляются в `evidence/`.

## Что исправлено

1. **MFA step-up открытой password-сессии возвращал 500.** `reauthenticate`
   записывал `mfa_authenticated_at`, сохраняя метод `password`, что нарушало
   SQL CHECK. Метод и evidence теперь меняются атомарно; recovery code остаётся
   одноразовым. Требования CORE-01/02, COMM-01/02.
2. **Billing onboarding отвергал пустой/отсутствующий address.** HTTP теперь
   различает объект `{}` и массив `[]`, сохраняет пустой адрес как JSON object,
   отклоняет arrays/null. OpenAPI типизирован и отражает фактический 200
   перехода `catalog-reviewed`. Требования COMM-01/02, BILL-01/02.
3. **CLI workers наследовали Caddy health probe.** Новые dedicated scheduler
   и email worker используют `ops:health` своего heartbeat. Два уже запущенных
   development worker показали Docker unhealthy при успешной фактической CLI
   health; их существующая конфигурация автоматически не переписывалась.
   Требование PROV-03 и operational readiness boundary.
4. **Гонка crash/restart теста.** EOF дочернего процесса мог наблюдаться раньше
   terminal signal status. Добавлено ограниченное ожидание; SIGKILL=9, прежний
   provider request key и один финансовый эффект по-прежнему обязательны.
   Требования PAY-03/04/05/06.

Production migration и финансовые правила не ослаблены. Новые проверки
каталога подтверждают typed features, разные currency/period/zero prices,
скрытый draft, immutable published terms и сохранность issued invoice snapshots
при retire/new version. Схемы OpenAPI и tests обновлены вместе с поведением.

## Функциональная проверка

| Граница | Результат |
| --- | --- |
| Core Codeception, seed `2120741368`, штатный `backend-test` | PASS: 508 tests / 14 050 assertions |
| Commercial PHPUnit, чистый guarded test reset | PASS: 377 tests / 4 581 assertions |
| Strict cross-plane, свежий образ после успешного retry | PASS: 8 tests / 319 assertions; реальные HTTP M2M и Yii CLI |
| Backend Psalm, locked dependencies | PASS: JSON `[]`, без suppressions |
| Commercial PHPStan | PASS: 0 errors |
| Symfony service wiring | PASS: `lint:container` |
| HTTP + SMTP/Mailpit business smoke | PASS: 75 checks, 4 сообщения для финального synthetic user |
| Core app restart | PASS на повторе: durable row сохранена, PostgreSQL не перезапускался, scheduler выполнил job |
| Backup artifacts и retention | PASS: manifest, corruption, partial, missing archive, status и minimum sets |
| Deployment backup artifacts | PASS: purpose/identity/SHA/missing/partial/final due/retention/purge |
| DBML | PASS: parser, все таблицы/поля/nullable/FK, live PostgreSQL comparison |
| Mermaid | PASS: 13 схем отрендерены; purchase preview визуально проверен |

Финальная функциональная сумма: **893 tests / 18 950 assertions**, плюс отдельные
75 HTTP checks и shell artifact tests. При пересборке cross-plane image один
запуск прервался из-за GitHub codeload TLS EOF; следующая полная пересборка и
strict suite прошли. Неуспешный build не учитывается как успешная проверка.

Первый E20 restart attempt во время параллельной нагрузки завершился неуспешно;
изолированный повтор с трассировкой прошёл. Это не доказательство устойчивого
restart во время 10 000 VU. Предварительный Windows-набор не подменяет Linux:
на Windows отсутствует POSIX kill/local-secret-store contract, а stale test
cache давал отсутствующие routes. Окончательная функциональная проверка выполнена
в Linux containers.

## Карта бизнес-сценариев

| Область | Что действительно проверялось | Остаточная граница |
| --- | --- | --- |
| Identity | Person/Account boundary, creation/invitation/activation, verified identifiers, lifecycle | Core публичного общего register не имеет |
| Central registration | Real HTTP register, verification email/link, duplicate verification, case normalization | Внешняя почта и пользовательский frontend не проверялись |
| Auth | Password/reset, access/refresh rotation/reuse, revoke/logout, stamps, expiry, external identities | Настоящие внешние OIDC provider/browser/device ceremonies отдельно |
| MFA/passkeys | TOTP, recovery single use, step-up, challenge/concurrency, WebAuthn runtime tests | Физический authenticator и end-user UI не проверялись |
| Authorization | Last usable superadmin, last Commercial owner, cross-account denial, organization scope, service scopes | Два одновременно активных полноценных Tenant окружения не прогонялись |
| Catalog | Draft/publish/retire, free/paid, currency/period/features, immutable prices and issued history | UI/API редактирования тарифов отсутствует; проверена модель и public read catalog |
| Purchase/billing | Invoice snapshots, free public HTTP settlement/replay, paid settlement integration | Paid public HTTP/provider/Tenant trace целиком остаётся отдельной приёмкой |
| Plan changes | Paid/free upgrade/downgrade, cancellation, proration, late payment, races, refund recovery | Не все варианты соединены в один public HTTP → bank → Tenant trace |
| Renewal | Preparation/collection/dunning/grace, paid/unpaid boundary, auto-renew cancellation | Provider sandbox charges и поздняя финальная граница уже оплаченного периода отдельно |
| Refund/receipt | Over-refund guards, allocations, provider uncertainty, retries, receipt independence, operator recovery | Реальная банковская и фискальная внешняя интеграция не выполнялась |
| Usage | Quota/counter/gauge, period lifecycle, scoped M2M и concurrent writes | Публичное денежное overage billing не следует из наличия counters |
| Entitlements | Signed exact bytes, trust/installation/expiry/sequence, replay, partial failure recovery | Полная матрица всех public customer actions до Tenant не закрыта |
| Provisioning | Persisted steps, ownership, release digest, credentials, update/decommission and retries | Полное новое реальное production provisioning/HA/DNS/TLS не принято |
| Mail/workers | Actual SMTP to Mailpit, stable replay, leased outbox failures/dead-letter tests, restart | Доставка внешнему mailbox/прочтение и длительная worker throughput нагрузка не доказаны |
| Privacy | Consent/representation, DSAR export/correction, holds/blocks, retention/erasure/evidence/incidents | Production policies, legal evidence и внешние providers требуют отдельного подтверждения |

Подробные [схемы процессов](../../processes/README.md) показывают соответствующие
переходы и failure paths. Сохраняется прежняя конечная E01–E20 матрица в журнале
готовности: более узкий компонентный тест не повышается до полной приёмки.

## Нагрузка

Отдельный `platform-load`, production-target images, `APP_ENV=test`, debug off,
две самостоятельные PostgreSQL 18 DB, Redis и Mailpit. 10 000 отдельных
идентичностей/сессий на приложение, 10 000 subscriptions, три тарифа. Positive
settlement выполнялся только при подготовке fixtures, без вызовов банка.

Шесть реальных read endpoints, один запрос на VU каждые 5 секунд при достаточной
скорости ответа. Closed ramp: 100 → 1 000 → 10 000, 60 секунд peak, ramp down.
Это не WebSocket presence, не login storm и не 10 000 независимых браузеров.

| Прогон | Запросы | HTTP errors | p95 | p99 | Итог |
| --- | ---: | ---: | ---: | ---: | --- |
| Smoke 10 VU | 45 | 0% | 65,60 ms | 346,12 ms | PASS |
| 100 VU после перегрузки | 1 550 | 0% | 45,52 ms | 302,20 ms | PASS |
| Первый 10 000 VU | 81 977 | 82,14% | 10 316,18 ms | 10 998,84 ms | FAIL, exit 99 |
| Итоговые образы, 10 000 VU | 81,277 | 82.75% | 10516.15 ms | 12604.69 ms | FAIL, exit 99 |
| 10 VU после финальной перегрузки | 45 | 0.00% | 82.32 ms | 310.27 ms | PASS |

У первого 10 000 VU peak business failure = 98,22%, peak p95 = 10 552,57 ms.
Все 10 000 VU действительно достигнуты. Генератор ограничен 4 CPU / 12 GiB;
он не исчерпал память, но достигал своего CPU budget. CPU приложений и DB также
рос. Нельзя по этому shared-host прогону приписать всё одному компоненту.
В server logs первого прогона не обнаружены сообщения о deadlock, OOM, exhausted DB
connections или FATAL. Это не исключает иные причины задержки.

Общий HTTP rate включает ошибки. Его нельзя объявлять sustainable throughput.
Даже успешные 100 VU означают только проверенный короткий профиль, не верхнюю
границу ёмкости. Длительный soak, отдельный generator, профилирование CPU/SQL,
жёсткие server quotas и write-heavy workflows остаются необходимыми.
Команды, dataset и пороги: [load README](../../../scripts/load/README.md).

## Данные и документация

Физический срез: 50 Core + 3 Runtime + 60 Commercial tables; 1 196 столбцов
и 181 FK. Core 32/32 и Commercial 40/40 migrations. Все четыре `.dbml`
построены из `pg_catalog`; v1 filename — совместимый alias актуального Core.
Запланированные таблицы убраны из актуальной модели.

Сохранены SQL defaults, enums, constraints, indexes, functions и triggers.
Частичные/вычисляемые indexes и deferred semantics не изображаются ложными
обычными UNIQUE/FK: их exact SQL находится в notes и JSON. Полный
[справочник](../../database/SCHEMA_REFERENCE.md) содержит все поля, а
[coverage inventory](../../core-database-coverage.md) — прямые SQL code/test references.

Переписаны общие guides, модель и database architecture, убраны устаревшие
утверждения о deferred plan changes/отсутствии provisioning. Детальные доменные
контракты и исторический журнал сохранены, с новой навигацией и явной границей
актуальности. Созданы процессы, load runbook, reproducible exporters/parser
checks и документационный CI job. Новый CI job локально проверяется, удалённый
workflow для незакоммиченного diff не запускался.

## Что удерживает `CORE_NOT_READY`

- Целевой local 10 000 VU профиль не прошёл, production capacity неизвестна.
- Остаются полные paid public HTTP/provider/Tenant E-сценарии и multi-Tenant runtime acceptance.
- Нет реального YooKassa/fiscal sandbox evidence и remote CI для точной ревизии.
- Production backup/PITR restore, HA/failover, alerts, DNS/TLS и deployment rehearsal не выполнены.
- Старый development backup worker имеет stale health: последний зафиксированный
  platform backup success — 2026-10-26 17:20:51 +05:00 (12:20:51 UTC), что превышает его 48-hour window.
  Проверка архивов не заменяет свежий backup/restore.
- Старые dedicated CLI containers требуют управляемого пересоздания для новой Docker probe.
- Frontend и Windows agent отсутствуют как работающие приложения.

## Сохранённое evidence

[Checks](evidence/checks.json), [source manifest](evidence/source-manifest.json), [load metrics](evidence/load-results.json), [HTTP trace без credentials](evidence/http-business-smoke.json).

Итоговый нагрузочный запуск: 2026-10-28 19:48–19:51 +05:00. Его resource snapshots содержат hashes сценария и immutable image IDs. Логи диагностик остаются локально в `runtime/audit-2026-10-28`; Codeception diagnostics не добавлялись в Git.

## Конечный нагрузочный срез по endpoints

| Endpoint | Business failures | p95 | p99 |
| --- | ---: | ---: | ---: |
| `core_me` | 76.30% | 10681.41 ms | 12317.78 ms |
| `core_settings` | 77.30% | 10654.83 ms | 12293.52 ms |
| `control_account` | 85.65% | 10435.24 ms | 12634.30 ms |
| `control_entitlements` | 85.72% | 10423.22 ms | 12804.45 ms |
| `control_sessions` | 85.78% | 10476.11 ms | 12864.28 ms |
| `catalog` | 85.72% | 10443.24 ms | 12802.61 ms |

Итоговое peak плато: 97.61% business failures, p95 11522.11 ms. Raw summaries сохранены рядом с отчётом.

Максимумы в собранных resource samples (не обязательно одновременны):

| Контейнер | CPU, 100% = одно ядро | Memory usage |
| --- | ---: | ---: |
| `platform-load-core-1` | 748.56% | 456.00 MiB |
| `platform-load-control-1` | 300.41% | 1379.33 MiB |
| `platform-load-core-db-1` | 277.89% | 147.10 MiB |
| `platform-load-commercial-db-1` | 332.74% | 216.80 MiB |
| `platform-load-redis-1` | 1.35% | 6.22 MiB |
| `platform-load-generator` | 416.47% | 3522.56 MiB |

Docker host: 16 CPU, около 31.3 GiB. Post-load readback сохранил все 10 000 fixture users, active subscriptions, paid invoices и succeeded payments. В финальных server logs также не встретились перечисленные OOM/deadlock/connection exhaustion/fatal сообщения; это конечная проверка конкретных логов.
