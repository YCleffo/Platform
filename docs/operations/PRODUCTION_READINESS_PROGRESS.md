# Production readiness: состояние работ

Срез: 2026-10-13. Это рабочий журнал выполнения приложенного ТЗ, а не акт
приёмки production. Источники истины: миграции, код, OpenAPI и выполненные
проверки. Непроверенный пункт не отмечается как завершённый.

## Матрица фактического состояния ядра (новое ТЗ, 2026-10-13)

| Функция | Где реализована | Что работает | Что отсутствует | Риск | Тест | Статус |
|---|---|---|---|---|---|---|
| Каталог и первая покупка | `PlanCatalog`, `CreateSubscription`, `IssueInvoice`, `SettlePayment` | Версионированный published plan, draft → paid → active; бесплатная первая покупка и переход на платный тариф | Остальная матрица тарифных переходов и бизнес-политика равной цены | Средний | `SubscriptionCreationTest`, `PaymentSettlementTest`, `SubscriptionPlanChangeTest` | PARTIAL |
| Upgrade/downgrade | `ProrationCalculator`, `RequestSubscriptionPlanChange`, `ApplySubscriptionPlanChange`, `ProcessScheduledPlanChanges`, `subscription_plan_changes` | Immutable расчёт и история, API, доплата, платный/бесплатный scheduled downgrade, отмена, settlement и права; полный YooKassa refund для late payment и освобождение после его подтверждения | Recovery для других провайдеров и окончательного отказа возврата, конкурентный settlement и полная fault/E2E матрица | Критический | `SubscriptionPlanChangeTest` 9/9 с процессной гонкой; Control Plane 332/332 | PARTIAL |
| Продление и expiry | `IssueRenewalInvoice`, `AdvanceSubscriptionPeriod`, `ExpireSubscription`, `ProcessRenewalDunning` | Оплаченное продление и grace, конкурентные проверки | Проверки всех fault/restart случаев и смены тарифа | Высокий | `Renewal*Test`, `SubscriptionExpirationTest` | PARTIAL |
| Платежи/webhook/возвраты | `Payment/*`, `Refund/*`, `Fiscalization/*` | Локальный settlement, inbox, provider GET, reconciliation, refund ledger | Полная fault матрица и производственный sandbox | Высокий | `YooKassa*Test`, `Refund*Test` | PARTIAL |
| Entitlements и межбазовая связь | `EffectiveEntitlements`, `DeploymentEntitlementIssuer`, Core runtime verifier | Подписанный snapshot, monotonic sequence, смена прав после оплаченного upgrade | Полное DR и recovery при рассинхронизации | Критический | `CrossPlaneBootstrapTest` 1/1 с переходом прав, `SubscriptionPlanChangeTest`, `EffectiveEntitlementsTest` | PARTIAL |
| Identity/RBAC/изоляция | Control auth, Core Account/Person, `PostgresRoleRepository` | Отдельные identity и проверка последнего human superadmin | Полная security/IDOR матрица | Высокий | Control auth и backend Codeception | PARTIAL |
| Provisioning | `ProvisionDeploymentExecutor`, `LocalDockerInfrastructureProvider` | Локальный Docker provider, retry и защищённые секреты Linux | Production provider и полная restart/fault матрица | Высокий | `ProvisionerBoundaryTest`, `CrossPlaneBootstrapTest` | PARTIAL |
| API/CI/эксплуатация | OpenAPI, `.github/workflows/ci.yml`, Compose | Часть route parity, PHPStan и локальные regression suites | Общий error contract, удалённый CI, HA/PITR/observability/load | Высокий | `OpenApiRouteParityTest`; CI не запускался удалённо | PARTIAL |

Матрица отражает код и предыдущие локальные проверки. Их результаты ниже —
исторические до повторного прогона после новых изменений.

## Выполнено в текущей рабочей копии

- [x] P0-A / CORE-01/02, COMM-01/02, PAY-03, ENT-01/03: инварианты собраны в
  `docs/architecture/CORE_INVARIANTS.md`; `AGENTS.md` требует этот контекст для
  следующих изменений.
- [x] NFR-Security cache policy: Control Plane auth, account/financial,
  privacy и M2M responses получают `no-store, private`; проверены успешные и
  ошибочные ответы. Каталог и health сохраняют прежнюю политику.
- [x] PAY-03 (один конкретный replay): повтор checkout с тем же ключом после
  webhook settlement использует исходную попытку; новый ключ после оплаты не
  инициирует новую charge. Интеграционный тест проверяет invoice,
  subscription, audit и число provider CREATE.
- [x] PROV-01, NFR-Security (локальная граница секретов): local file secret
  store явно допускает только Linux и fail closed до записи на Windows.
  Положительная проверка POSIX group 33/modes исполняется в Linux test image;
  CI unit suite перенесён в этот образ.
- [x] Частичная P0-B OpenAPI: строгие ответы checkout, создания подписки,
  сохранённых платёжных методов и effective entitlements, типизированные запросы создания подписки и
  пустого тела изменения платёжного метода, contract parity test.
- [x] BILL-03/04, только расчёт: `ProrationCalculator` v1 использует integer
  minor units, UTC microseconds, проверку переполнения и half-up для каждого
  компонента. Тесты проверяют середину/границы периода, отрицательный net,
  большое число и отказ на неверном вводе. Это ещё не tariff lifecycle.
- [x] NFR-Observability, локальный guard: отсутствие backlog row приводит к
  безопасному отказу операторской команды вместо обращения к `false`.
- [x] CORE-08: проверка последнего действующего superadmin учитывает только
  активные human-аккаунты с активным Person. Тест отдельно создаёт service
  account с ролью superadmin и подтверждает, что он не считается заменой.
- [x] Backend regression fixtures согласованы с границей Identity: тесты
  пользовательской аутентификации создают human-аккаунты; service account
  проверяется на отказ в доступе. Тестовый helper извлекает одноразовые ссылки
  из зашифрованного outbox payload через штатный cipher, без хранения URL в
  открытом виде. Падения passkey, invitation/reset, MFA и privacy повторены
  отдельно и прошли.
- [x] QA static gate: полный Control Plane PHPStan теперь проходит без ошибок
  в `src` и `tests`; исправлены типы и проверки входных данных без baseline и
  подавления диагностик. CI устанавливает backend Composer dependencies перед
  анализом cross-plane теста.
- [x] RENEW-01, PAY-04 (точечные инварианты): фоновое истечение считает только
  реально истёкшие подписки, а не уже оплаченные и продлённые в той же
  транзакции. Проверен порядок webhook `payment.canceled` после фактического
  успеха у провайдера: один settlement, invoice paid, subscription active.

## Подтверждённые проверки

- Control Plane: чистая isolated Commercial DB: 33 миграции; ранее выполнены
  N-1 → N → N-1 → N для последней миграции. Полный PHPUnit после последних
  изменений: **323/323, 3353 assertions**; отдельный класс reordered webhook
  прошёл 8/8, 86 assertions. Linux unit:
  **111/111, 492 assertions**. Полный PHPStan: **0 ошибок**. `git diff --check`
  прошёл. Cross-plane bootstrap/sync/ready E2E на пересобранном образе:
  **1/1, 11 assertions**. Последний полный PHPUnit пройден через временные
  Docker volumes из-за сбоя Docker Desktop bind mount; volumes удалены после
  проверки. Remote CI текущих изменений не запускался.
- Полный backend Codeception suite с фиксированным seed 2120741368 после
  исправлений: **508/508, 14050 assertions**. Первый прогон выявил устаревшие
  service-account и plaintext-email фикстуры, устаревшее ожидание privacy
  capability и слабую проверку последнего superadmin; точечные тесты и полный
  повторный прогон прошли. Использовались только `platform_test` и
  `platform_commercial_test`; Commercial DB сбрасывалась защищённой командой
  `control:test:reset` и восстановлена миграциями.

## Открытые требования: P0

- [ ] SUB-02..07, BILL-03..05: миграция plan-change ledger и immutable
  calculation snapshots; сериализация concurrent requests; immediate upgrade
  только после оплаты; scheduled downgrade на границе периода; failed upgrade
  сохраняет старый план. Нужно согласовать выбор subscription items в renewal
  invoice и entitlements: сейчас activated items immutable, а renewal берёт
  все items. Калькулятор не подключён к business transition.
- [ ] PAY-03..08, RENEW-01..05, REF-01..04, FISC-01..03: расширить
  end-to-end, race и fault матрицу из ТЗ. Существующие сервисы/тесты уже
  покрывают значительную часть сценариев, но полный gate с fault injection
  и sandbox ещё не выполнен. Реальных списаний не проводить.
- [ ] CORE-13, ENT-01..03: проверить eventual convergence при reordered
  delivery, восстановлении обеих БД и entitlement expiry. Текущий cross-plane
  bootstrap прошёл, полная DR матрица не проверена.
- [ ] NFR-Security: закрыть оставшиеся generic финансовые и privacy OpenAPI
  `Object`, формальный error/idempotency contract, безопасное логирование и
  ASVS-derived abuse tests.
- [ ] NFR-Observability: централизованные structured logs, metrics histograms,
  traces, payment/reconciliation/worker dashboards, symptom alerts и
  synthetic verification. Сейчас есть health и job status, полного стека нет.
- [ ] NFR-Database/Backup/Availability: выбрать production topology,
  подтвердить HA/failover, continuous WAL/PITR, encrypted offsite storage и
  полный restore/consistency drill. Цели SLO/RPO/RTO из ТЗ пока предложения,
  не действующие гарантии. Пользователь подтвердил, что хостинг ещё не выбран;
  значения SLO/RPO/RTO также не согласованы.
- [ ] PROV-02/04, deployment/rollback: production provider, immutable digest
  в staging/prod, canary, previous-image rollback и expand/contract migration
  rehearsal. Существующий provider — local Docker для dev/test.
- [ ] NFR-Capacity и chaos: nominal/spike/soak, provider timeout, worker crash,
  Redis/DB failure, failover и recovery по согласованным SLO. Результатов
  production-like нагрузки пока нет.
- [ ] QA baseline: PHPStan, backend и Control Plane regression suites,
  migration N-1 → N, cross-plane bootstrap E2E локально прошли. Остались
  расширенный provider contract, fault/race/load gates и удалённый CI на
  финальном commit.

## Открытые требования: P1 и P2

- [ ] USAGE-02..06: immutable metering ledger, event-id deduplication,
  closing window, adjustments и reproducible overage invoice. Текущий usage
  hard quota не является usage billing.
- [ ] P1: API compatibility/deprecation policy, email/phone normalization
  version, cross-DB restore reconciliation, supply-chain digest/SBOM/signing,
  HIGH vulnerability policy, production secret manager, operational runbooks.
- [ ] P2/planned: второй provider только после общего contract suite;
  DNS/TLS/custom-domain stages, On-Prem activation, Vue frontend и Windows
  agent по отдельно определённым product contracts.

## Следующий конкретный шаг

Реализовать транзакционный plan-change ledger с immutable расчётами и
интеграцией в invoice/payment/entitlement/renewal. Конкретная зависимость в
текущей схеме: `Version20260924001200` запрещает менять activated
`subscription_items`; `Version20260924001400` требует соответствия
`invoice_lines.subscription_item_id` и `plan_price_id`; `IssueRenewalInvoice`
сейчас берёт все items без периода действия. Новую схему и переход нужно
проектировать вместе, иначе запрос upgrade может создать invoice, который
не сможет применить entitlement или изменит следующий renewal раньше оплаты.

1. Добавить additive-миграцию plan-change ledger с request key, состояниями,
   old/new price и quantity, effective instant, rule version, сохранённым
   результатом `ProrationCalculator`, связью с invoice/payment; обеспечить
   совместимость N-1 приложения и неизменность расчёта после создания.
2. В одном locked transaction принимать запрос `billing.manage`, проверять
   published price, currency/period, текущий active item и отсутствие другого
   незавершённого change. Повтор запроса возвращает тот же ledger record;
   конфликтный payload отклоняется; конкурентные запросы сериализуются.
3. Для положительного upgrade провести доплату через существующий Payment Core;
   только authoritative settlement атомарно применяет новый item и outbox.
   Неуспех/неизвестный результат провайдера сохраняет прежние entitlements.
4. Downgrade применять на следующей границе периода; renewal invoice должен
   сниматься с будущих effective items, а текущие entitlements оставаться
   прежними до границы. Проверить late settlement, cancel, refund и повторную
   выдачу подписанного entitlement document.
5. Покрыть migration up/down/replay, API contract, failed/duplicate/concurrent
   upgrade, scheduled downgrade, money rounding и cross-plane sync. Затем
   расширить provider fault/race, DR и load gates. До этих gate система не
   production-ready.

## 2026-10-13: plan-change implementation and local evidence

Affected requirements: CORE-12/13, SUB-02..07, BILL-03..05, PAY-03..08,
ENT-01..03. This section supersedes the older plan-change next-step list
above; the historical entries remain for traceability.

- Added additive `Version20261013000100` with guarded, immutable
  `subscription_plan_changes` terms, unique request key and open source index.
  Applied once to isolated `platform_commercial_test` using Doctrine migrations;
  the migration's protective down refuses to erase populated history.
- Added additive `Version20261013000200` with guarded `refunded` terminal state;
  applied to the same isolated test DB. Refund finalization verifies an
  authoritative succeeded full refund before freeing the source for another
  change. The target draft is cancelled on late settlement.
- Added request/read/cancel API and OpenAPI operations; request requires
  `billing.manage` and recent MFA, compares request hashes, serializes on the
  source subscription, and snapshots the prorated calculation. Existing source
  items and invoices stay immutable. Upgrade settlement switches rights in the
  paid invoice transaction. Scheduled downgrade runs before renewal billing;
  a priced replacement awaits payment. Cancellation voids an unpaid invoice.
- Replayed local focused integration tests: `SubscriptionPlanChangeTest` 9/9,
  including two-process same-key request race: one plan change and one invoice.
  Replayed full ordinary Control Plane suite after the free-to-paid and clock
  fixes and race coverage: 332/332, 3533 assertions. The first full run of a database-time
  activation experiment failed 9 renewal tests; this behavior change was
  revised to preserve historical settlement timestamps, and both the focused
  renewal tests (37/37) and final full suite passed. PHPStan on the final
  `platform-control-plane-test` image with staged backend autoload: 0 errors.
  Symfony `debug:router` lists
  all three new routes. The full suite includes `OpenApiRouteParityTest`.
  Extended and replayed cross-plane bootstrap E2E with unpaid upgrade retaining
  old signed rights and paid upgrade delivering a new monotonic signed snapshot
  into Tenant Plane: 1/1, 28 assertions. Both new
  migrations reached latest on the isolated commercial test DB, with zero
  unavailable/new versions. After copying the final OpenAPI file into the
  isolated contract volume, route/contract parity passed 3/3, 39 assertions.
  `git diff --check` passed.
- A later full run initially hit two persistent-test-fixture collisions:
  `ConcurrencyTest` reused a fixed IP after many local runs, and the new race
  cleanup reused a global idempotency key. Test fixtures now use unique values;
  focused rerun passed 2/2 and the final full run passed 332/332. No production
  data was modified or removed.
- Windows provisioner check: `LocalProvisioningSecretStore::guard()` rejects
  non-Linux hosts before creating a secret directory or calling POSIX `chgrp`;
  `ProvisionerBoundaryTest` explicitly verifies that branch. Linux Docker
  tests still assert group 33 and exact 0750/0640 permissions. This is a
  deliberate platform split, not a disabled security assertion. A native
  Windows PHPUnit run was not performed in this session.
- Remaining P0: YooKassa `paid_unapplied` now reserves a full refund in the
  payment transaction; the existing refund reconciler retries an uncertain
  provider result with the same key and finalizer frees the source on full
  refund. Other provider/fiscal configurations and
  definitive refund failures still require operator recovery. Add genuine
  concurrent request/settlement, provider replay/out-of-order, and cross-plane
  entitlement propagation tests for the new transitions. Confirm downgrade
  interaction with expiry/dunning and explicit user cancellation semantics.
- Remaining P1/P2: broader E01-E20 E2E/fault matrix, production-like load,
  restore rehearsal and operational metrics/alerts; see the older requirement
  inventory above. No hosting or production secrets have been provided.

Verdict: `CORE_NOT_READY`. Local checks do not establish production readiness.
Next step: add concurrent settlement against cancellation/expiry, then complete
E01-E20 fault matrix and restore
rehearsal. Production-like load and remote CI remain separate gates.

## 2026-10-14: financial contention and compensation checkpoint

Affected requirements: CORE-12/13, SUB-01/06/07, BILL-01/02/04,
PAY-01/03/04/05/06/07/08, REF-02/03/04, ENT-01/02/03. This section
supersedes the 2026-10-13 next-step text above.

- Fixed scheduled downgrade's source/change lock inversion. It now takes the
  source renewal session lock and source row before the plan change row, in
  the same order as settlement and cancellation.
- Added actual two-process plan-change races. The test parent holds the
  source row, releases both worker barriers, and requires both PostgreSQL
  backend sessions to report `wait_event_type='Lock'` before it releases the
  row. Each case runs twice. Observed passing: R01 settlement/cancel change,
  R02 settlement/cancel source, R03 settlement/expiry, R04 free downgrade/expiry,
  R05 paid downgrade settlement/expiry, R06 downgrade/renewal issuance,
  R08 duplicate settlement, R09 different-key requests, R10 same-key replay.
  R07 scheduled downgrade/dunning and provider reconciliation overlap remain
  separate NOT_RUN cases. Assertions cover state, selected money/audit/outbox
  effects, usage and effective rights; a reusable full invariant checker does
  not yet exist.
- Applied additive migration `Version20261014000100` to the isolated
  `platform_commercial_test` database. Late unapplied payments now persist
  `compensation_status` and reason. Unsupported provider/fiscal cases become
  `recovery_required`; supported YooKassa becomes `pending`; confirmed full
  refund becomes `succeeded`. A scheduler job moves canceled provider refunds
  to `recovery_required` once, retaining the financial block. Operator CLI
  `control:plan-changes:recovery-cases` and `control:ops:status` expose cases
  and counts without provider secrets. No ordinary customer route can attest
  an external refund. A privileged, evidence-backed external/manual recovery
  path is still missing, so this P0 is PARTIAL.
- Applied additive migration `Version20261014000200` to the isolated test DB.
  New plan-change requests share `commercial.idempotency_keys` with other
  financial mutations; an insert guard also blocks reuse of historical
  plan-change keys by another operation. Same-key, changed-payload,
  cross-operation and two-process replay tests pass. A global uniqueness
  constraint/backfill of legacy general keys is still NOT VERIFIED.
- Added plan-change-specific authoritative provider ordering test: pending,
  success, duplicate success, stale pending and stale canceled observations
  preserve one paid invoice and one entitlement switch. Broader W01-W10
  webhook/reconciler/crash matrix remains PARTIAL.
- Made the existing late paid-downgrade policy explicit: the invoice remains
  payable and a verified late settlement starts the full replacement period
  at settlement time. An integration test covers expiry followed by payment.
  Cancellation, receipt and cross-plane breadth remain open.
- Updated OpenAPI read shape, commercial architecture and DBML for the
  compensation fields. The previous 2026-10-13 evidence still covers only
  one cross-plane upgrade E2E, not X01-X18.

Latest completed local commands on the isolated Docker services:

```text
docker compose --profile control-test build control-plane-test --quiet
docker compose --profile control-test run --rm --no-deps -v platform_plan_test_backend:/backend -v platform_plan_test_contracts:/contracts control-plane-test php bin/console doctrine:migrations:migrate --no-interaction
docker compose --profile control-test run --rm --no-deps -v platform_plan_test_backend:/backend -v platform_plan_test_contracts:/contracts control-plane-test php vendor/bin/phpunit --filter SubscriptionPlanChangeTest --do-not-cache-result
docker compose --profile control-test run --rm --no-deps -v platform_plan_test_backend:/backend -v platform_plan_test_backend_vendor:/backend/vendor -v platform_plan_test_contracts:/contracts control-plane-test php vendor/bin/phpstan analyse --no-progress
docker compose --profile control-test run --rm --no-deps -v platform_plan_test_backend:/backend -v platform_plan_test_contracts:/contracts control-plane-test php vendor/bin/phpunit --filter OpenApiRouteParityTest --do-not-cache-result
docker compose --profile control-test run --rm --no-deps -e CROSS_PLANE_REQUIRED=1 -v platform_plan_test_backend:/backend -v platform_plan_test_backend_vendor:/backend/vendor -v platform_plan_test_contracts:/contracts control-plane-test php vendor/bin/phpunit --group cross-plane --do-not-cache-result
```

Observed: migrations 00100 and 00200 applied once; focused plan-change suite
21/21, 656 assertions; last full Control Plane run before the final same-key
barrier test 343/343, 3993 assertions; PHPStan 0 errors; OpenAPI parity
3/3, 39 assertions; cross-plane upgrade 1/1, 28 assertions. The final full
suite after the same-key barrier test remains to be rerun. No real charges.

Remaining P0, in order: finish R07 and provider-reconciler overlap with full
ledger assertions; implement an authorized, evidence-backed path out of
`recovery_required` after unsupported/definitive refund failure without
claiming money returned early; finish W01-W10 and F01-F10; validate all
cancellation states and X01-X18 in Tenant Plane. Then E01-E20, restart/fault,
local restore, observability and invariant stress/load gates. No hosting,
production SLO/RPO/RTO, remote CI or production runtime evidence exists.

Verdict: `CORE_NOT_READY`.

## 2026-10-14: global key contention and isolated restore rehearsal

Affected requirements: CORE-12/13, PAY-03/04/05/06, SUB-06/07, REF-03,
ENT-01/02/03. This checkpoint supersedes the test counts and next step above.

- Migration `Version20261014000300` extends the existing idempotency insert
  guard. Every new key insert obtains a PostgreSQL transaction advisory lock
  and rejects another scope, including writers from older application builds.
  A two-process test holds that lock until both DB sessions are observably
  waiting; after release one insert succeeds and the other is rejected. This
  protects new writes. The isolated test DB has zero pre-existing cross-scope
  duplicate keys, but production data has not been audited. A global unique
  index and evidence-preserving resolution of any historic duplicates remain
  open. No existing financial key record was deleted.
- R07 is prevented by the current commercial rule: a scheduled plan change
  excludes a renewal invoice on the source, while an already issued renewal
  invoice excludes a new plan change. Dunning requires that invoice. An
  integration test checks the scheduled-change side and the existing R06
  two-process test checks issuance contention. No separate source dunning
  settlement race can be constructed without violating the guarded contract.
- A two-process upgrade test now overlaps the authoritative payment path with
  YooKassa reconciliation under an observed source-row lock. Both workers
  complete, and one paid invoice, one subscription switch and one copy of
  each audited/payment/outbox effect remain. This is provider reconciliation
  overlap evidence; webhook inbox deduplication and ordering still need their
  own full W01-W10 matrix.
- The first complete run after adding that test exposed fixture leakage: its
  committed YooKassa account was still active and its receipt remained pending,
  affecting unrelated webhook and fiscal tests. The fixture now disables its
  provider and fiscal mode before independent workers start. Only the named
  `platform_commercial_test` database was restored from the already verified
  pre-fixture custom dump; no working or production database was touched.
  Two consecutive full runs on that restored and then reused isolated DB pass.
- The privacy retention integration test used to assume its target audit row
  was inside the first 1000 eligible records. Reused isolated test data broke
  that assumption. It now checks the protected rows directly and drains
  bounded batches after releasing the hold. No privacy cleanup behavior was
  weakened.
- Local restore rehearsal used only `control-postgres-test`: a custom-format
  `pg_dump` of `platform_commercial_test` was restored into a new
  `platform_restore_rehearsal_test` database. Migration counts and the counts
  of accounts, plan changes, invoices, payments, refunds, idempotency records,
  audit and outbox rows matched exactly immediately after restore. Doctrine
  reported 38/38 applied migrations and 0 pending on the clone; the restored
  Control Plane `control:ops:status` command ran successfully. This proves a
  local logical restore and read-only boot, not PITR, worker replay or a
  production RPO/RTO. The restore clone remains isolated for inspection.
- Two consecutive full Control Plane runs before the refund restart test:
  350/350, 4119 assertions each. The provider overlap address test: 1/1,
  24 assertions. Earlier focused plan-change suite: 26/26, 736 assertions.
  PHPStan: 0 errors. OpenAPI route parity: 3/3, 39 assertions. Cross-plane
  group: 1/1, 37 assertions. `git diff --check` passes.
- Tenant Plane ran from the pre-staged backend source/vendor named volumes
  because Docker Desktop could not mount `C:\Platform\apps\backend` into a
  fresh container. Unit: 344/344, 12513 assertions; Console: 2/2, 9;
  Functional: 92/92, 845. A temporary test HTTP container gave API:
  70/70, 719 assertions and was removed afterward. An initial API run in a
  one-shot CLI container had no HTTP listener and produced only connection
  errors; the server-backed rerun passed. Remote CI remains unrun.
- Review tightened the new financial backlog query from a prefix match to the
  exact canonical compensation refund key. The final Control Plane gate after
  this correction passed 350/350, 4119 assertions and PHPStan 0 errors.
- Added a file-backed mock refund provider and two independent PHP worker
  processes for F04/F05. After refund reservation commits, the first process
  records an external success and is then killed with SIGKILL before any
  local success write; the local refund stays pending. A fresh process retries
  with the identical provider request key,
  sees the same external refund, commits one local success, and the finalizer
  reaches `refunded` exactly once. This is deterministic fault injection with
  a real local process kill and a file-backed mock, not a provider sandbox
  transaction. The fixture completes its
  own fake fiscal receipts and disables its provider account before leaving
  the shared isolated DB. The focused case passes 1/1, 32 assertions; two
  consecutive full Control Plane runs pass 351/351, 4151 assertions each;
  PHPStan 0 errors and `git diff --check` pass after it.
- W08 now has a plan-change-specific test: `refund.succeeded` notification
  arrives before refund reconciliation, duplicate notification reads the same
  inbox result without another provider GET, reconciliation selects zero
  pending refunds, and the finalizer marks the change refunded once. The
  address test passes 1/1, 22 assertions. The full suite after switching
  the restart fault to SIGKILL passed 352/352, 4173 assertions. PHPStan found
  a test-worker `getmypid()` type warning; it was fixed and the focused kill
  test plus PHPStan passed again. The final full suite after that type guard
  also passed 352/352, 4173 assertions.

Remaining P0: evidence-backed external compensation policy and authorized
write path for `recovery_required`; broader F01-F10 and W01-W10 failure and
provider ordering matrix, X01-X18 cross-plane
matrix and worker crash replay. P1/P2: full E01-E20, restore with workers,
alert delivery, load and remote CI. No hosting, production SLO/RPO/RTO or
production runtime evidence exists. Verdict: `CORE_NOT_READY`.

### Exact continuation checklist

`PASS` means the named invariant was asserted in an isolated local test;
`PARTIAL` means the narrower listed case passed but the full case did not.
No row below is production evidence.

| Matrix | Local status and evidence gap |
| --- | --- |
| R01-R05 | PASS, two observed DB-lock repetitions each. |
| R06 | PASS, two observed DB-lock repetitions. |
| R07 | PASS for the exclusion policy: source renewal invoice cannot coexist with scheduled downgrade; no applicable source dunning state. |
| R08 | PASS locally: two duplicate-settlement repetitions, authoritative processor/reconciler overlap, and payment webhook inbox/reconciler contention with one settlement and plan switch. |
| R09 | PASS, two different requests choose one replacement. |
| R10 | PASS, same-key replay and two-process lock contention. |
| F01-F03 | PASS for automatic full refund, stable key after uncertain create, and durable recovery after definitive failure. |
| F04-F05 | PASS for a new PHP process after reservation and a SIGKILL after the mock provider persisted success. |
| F06 | PASS locally: duplicate refund webhook, later reconciler, and simultaneous webhook/reconciler contention with one refund and one finalization. |
| F07-F08 | PASS, unsupported provider/fiscal mode creates durable recovery case. |
| F09 | PASS locally: automatic finalizer replay and two-operator external full-refund attestation are idempotent; hosted document verification remains an operational gate. |
| F10 | PASS locally: automatic or independently attested external full refund releases the change; an unreviewed proposal never does. |
| W01-W03 | PASS locally: plan-change webhook pending claim is retried after authoritative success; duplicate and stale notice leave one settlement and plan switch. Provider sandbox remains NOT_RUN. |
| W04 | PASS locally: authoritative payment webhook versus plan-change cancellation races on independent processes twice; pending attempt prevents cancellation and one settlement and entitlement switch result. Provider sandbox remains NOT_RUN. |
| W05-W06 | PARTIAL: generic checkout/reconciliation tests and processor overlap exist; complete plan-change webhook/restart variants remain. |
| W07-W08 | PASS locally for duplicate refund notification and simultaneous webhook/reconciler delivery; provider sandbox remains NOT_RUN. |
| W09-W10 | PASS in isolated mock fault tests: both charge and refund SIGKILL/restart converge with one provider object and one local effect. Provider sandbox restart remains NOT_RUN. |
| X01 | PASS: initial paid subscription reaches Tenant runtime. |
| X02 | PASS: failed first purchase leaves Tenant runtime rights empty; successful retry grants them. |
| X03-X05 | PASS: unpaid/failed upgrade retains old runtime rights, paid upgrade switches them. |
| X06-X10 | PARTIAL: X06 scheduled free downgrade retains paid Tenant rights before the period boundary; X10 cancellation retains them. X07-X09 and the boundary itself remain NOT_RUN in Tenant runtime. |
| X11 | PASS: expiry removes runtime rights. |
| X12 | NOT_RUN in Tenant runtime: paid renewal versus expiry at a real boundary. |
| X13-X14 | PASS locally: late paid upgrade remains unapplied with no target Tenant rights; verified full fake-provider refund releases the change without granting them. |
| X15 | PARTIAL: refund finalizer and Tenant entitlement pull replay are idempotent in one process; worker restart/outbox replay is NOT_RUN. |
| X16 | PASS: stale signed document cannot restore old rights. |
| X17 | PASS locally: second tenant credential pulls its own signed feature set; its document is rejected by the first Tenant installation, which keeps its own rights. |
| X18 | PARTIAL: repeated Tenant pulls after refund and expiry preserve the same sequence and rights; repeated activation/cancellation event delivery is NOT_RUN. |
| E01-E20 | NOT_RUN as the specified full API + DB + ledger + audit + inbox/outbox + Tenant matrix. Narrower component tests above do not satisfy it. |

Next concrete independent P0 step: complete the missing Tenant runtime cases
and the full provider sandbox matrix. External recovery now has an approved
two-operator rule and a local write path, but hosted evidence storage and
independent transfer review are still operational gates. After P0, run the
full E01-E20 matrix, worker replay on a
restored clone, metrics/alerts, concurrency load, and remote CI.

### 2026-10-14 continuation after commit `9e1750c`

- Added charge-side W09/W10 fault injection in `payment-restart-worker.php`.
  The first PHP process commits the checkout attempt and its stable provider
  request key, persists a successful mock provider payment, and dies from
  SIGKILL before binding the provider reference locally. A fresh PHP process
  uses the same checkout key and same provider request key; it sees the same
  external payment and commits one settlement and one plan switch. The test
  checks pending state before restart, one attempt, one paid invoice, one
  payment audit, one activation outbox effect, old/new entitlements, and no
  pending fixture receipt or active fixture provider afterward.
- Address test: 1/1, 30 assertions. Two consecutive full Control Plane runs
  after the addition: 353/353, 4203 assertions each. PHPStan: 0 errors.
  Tenant Plane results above still stand; no Tenant code changed.
- Cross-plane X02 now asserts a failed initial invoice payment leaves Tenant
  runtime rights empty and a later successful retry grants the old-plan rights.
  X06 schedules a free downgrade and checks the paid rights before the period
  boundary. X10 cancels that downgrade and checks the paid rights again.
  The focused cross-plane group passes 1/1, 51 assertions. This does not
  exercise the actual boundary transition or the remaining X cases.
- Payment R08 and refund F06/W07-W08 now run two independent PHP workers
  through the real webhook inbox and reconciler while the parent holds the
  affected PostgreSQL row. Both workers must visibly wait on DB locks before
  release. The tests assert one inbox event, one payment or refund effect,
  one plan switch or finalization, matching entitlements, and cleaned-up
  fixture provider/receipts. Focused address tests pass 1/1 with 27 and 33
  assertions respectively. Full suite and static analysis after these two
  additions remain to be rerun.
- Remaining P0: broader provider ordering variants, full
  X07-X09/X12-X15/X17-X18
  Tenant runtime proof, and any P0 defects those tests expose. P1/P2:
  E01-E20, restored worker replay, alerts, load, CI and production topology.
  Verdict remains `CORE_NOT_READY`.

### 2026-10-15: two-operator external compensation and real inbox races

- User-approved policy: two separate Platform financial operators must attest
  the same restricted document reference, document SHA-256 and external
  transaction reference. The new `financial_operators` registry is separate
  from customer account roles. HTTP writes require recent MFA. An immutable
  attestation snapshots the settled amount/currency. The second reviewer
  either approves or rejects; rejection leaves the financial block and its
  evidence in history. Approval checks that no local provider refund is
  pending or succeeded, records one reviewed attestation and sets the plan
  change to `refunded` atomically. It does not create a synthetic YooKassa
  refund or initiate a real transfer. `EXTERNAL_COMPENSATION.md` records the
  operator procedure and the hosted document storage gate.
- The migration was applied only to the isolated `platform_commercial_test`
  database. Focused policy tests check unprivileged/revoked/self review,
  mismatched evidence, rejection and correction, pending provider refund,
  idempotent replay, immutable evidence and old entitlements. A two-process
  approval test holds the source row until both DB sessions wait on locks;
  exactly one reviewer succeeds and one gets a conflict.
- Review found that the ordinary refund API could still issue a provider
  refund after an external attestation. `CreateRefund` now blocks new refund
  reservations while an external proposal or approval claims the payment,
  while preserving the result of an existing identical refund key. The policy
  test asserts denial both before and after approval. This closes the local
  double-compensation path; bank/provider reconciliation remains a hosted
  operational responsibility.
- The customer plan-change read model now exposes `compensation_origin` as
  `provider`, `external`, or null. It does not expose the restricted document.
  Provider and external cases are asserted through the same API reader and
  documented in OpenAPI.
- Payment webhook versus reconciler and refund webhook versus reconciler now
  run through the actual inbox using two independent PHP workers under
  observed PostgreSQL lock contention. The payment race has one settled
  payment and plan switch; the refund race has one successful refund and one
  plan-change finalization. Fixture providers are disabled and receipts
  completed after the races. Both address tests passed (27 and 33 assertions).
- Control Plane after the payment/refund race tests: 355/355, 4263 assertions;
  PHPStan 0. After external compensation implementation and before the final
  new race test: 357/357, 4317 assertions; OpenAPI parity and focused policy
  tests 5/5, 87 assertions; PHPStan 0. Final full suite after the last policy
  and concurrency changes: 358/358, 4344 assertions; PHPStan 0. Cross-plane
  group: 1/1, 51 assertions. Final rerun after the ordinary refund guard and
  OpenAPI contract assertions: 358/358, 4353 assertions; PHPStan 0;
  cross-plane 1/1, 51 assertions. Doctrine reports 39/39 migrations applied
  and zero pending in the isolated test DB. Final rerun after the
  `compensation_origin` API addition: 358/358, 4356 assertions; PHPStan 0;
  cross-plane 1/1, 51 assertions. The migration has only been applied to the
  isolated local commercial test DB; remote CI and hosted runtime are unrun.
- Remaining P0: hosted evidence authorization/retention review, provider
  sandbox matrix, missing Tenant runtime X07-X09/X12-X15/X18 scenarios,
  and unresolved P0 defects found by those checks. P1/P2 and production
  topology gates above remain. Verdict: `CORE_NOT_READY`.

### 2026-10-15: provider ordering and tenant isolation continuation

- W01-W03 now use the actual plan-change webhook inbox. A success notification
  while provider GET is still pending is recorded as retryable failure and
  does not bind or settle. Repeating the same notification after authoritative
  success binds, pays and switches once. A duplicate avoids another provider
  GET; a stale cancellation notification fetches current success and does not
  regress payment, subscription, audit, outbox or rights. Address test: 1/1,
  27 assertions.
- X17 now creates a second commercial account, credential and paid plan in
  the cross-plane test. Its M2M pull yields only that tenant's feature and a
  different installation ID. Applying those exact signed bytes to the first
  Tenant installation is rejected, and the first tenant keeps its rights.
  Cross-plane group before the late-payment extension: 1/1, 64 assertions. This uses one live Core test
  installation; two separate hosted tenant runtimes remain untested.
- X13-X14 now drive a late paid upgrade from the same account through a RUB
  invoice and YooKassa-shaped fake provider. `paid_unapplied` leaves the
  target subscription cancelled and Tenant on the old feature set. A pending
  fake refund does not change rights; authoritative full success plus the
  finalizer changes the plan-change history to `refunded` while Tenant still
  has no target feature. Replaying the finalizer and Tenant pulls has no new
  effect; replay after expiry keeps Tenant rights empty. The focused
  cross-plane group passes 1/1, 88 assertions. This is local fake-provider
  evidence, not a sandbox transaction or a worker process restart.
- Final full Control Plane rerun: 359/359, 4383 assertions; PHPStan 0;
  focused cross-plane 1/1, 88 assertions. A PHPStan warning about a redundant
  constant-status assertion in the webhook test was fixed before this final
  rerun. `git diff --check` passes.
- Remaining P0: actual downgrade boundary X07, paid downgrade X08-X09,
  renewal X12, worker restart/outbox replay X15, repeated event X18,
  full sandbox ordering and E01-E20. Operational gates above remain.
  Verdict: `CORE_NOT_READY`.

### 2026-10-15: webhook versus cancellation process race

- W04 now runs an authoritative YooKassa-shaped payment webhook against
  plan-change cancellation in two independently connected processes, with a
  barrier and two iterations. Cancellation is rejected while a provider attempt
  is pending; the webhook inbox is processed, and only one payment settlement,
  plan switch, and target activation event are recorded. The focused test
  passes 1/1, 50 assertions. This is local fake-provider evidence; sandbox
  delivery, process crash, and remote runtime are separate gates.
- The first post-change full suite failed after two SIGKILL tests left committed
  fixture provider accounts active in the reused isolated test database;
  later tests then saw an ambiguous YooKassa account. The guarded
  `control:test:reset` command was run against
  `control-postgres-test/platform_commercial_test`, then all 39 migrations
  applied. On the clean schema, the full Control Plane suite passes 360/360,
  4423 assertions, PHPStan reports zero errors, and no active fake YooKassa
  accounts remain. `git diff --check` passes. This is local test evidence.
