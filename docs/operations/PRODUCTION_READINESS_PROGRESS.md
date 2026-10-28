# Production readiness: состояние работ

## Authoritative project audit — 2026-10-28

**Verdict: `CORE_NOT_READY`.** [Полный аудит](../audit/2026-10-28/REPORT.md),
[checks](../audit/2026-10-28/evidence/checks.json),
[source manifest](../audit/2026-10-28/evidence/source-manifest.json).
Base `e242a43258069b310d1f8abb2cc47f9dd22e147b` plus the uncommitted working-tree diff;
source manifest SHA-256 `989db17a36cef8e3c59696bae5fab00806449991f81c8967afc5a6e5e9ac4ba2`. Remote CI does not attest to this diff.

Final local functional evidence: Core 508/14050, Commercial 377/4581,
strict cross-plane 8/319; Psalm/PHPStan 0 errors, container wiring PASS.
The real HTTP/SMTP/Mailpit customer smoke passed 75 checks. MFA session step-up,
empty billing address handling and new-worker Docker health probes were fixed;
regression and contract checks passed. Fresh cross-plane image rebuild passed on
retry after one GitHub codeload TLS EOF failure.

The final local 10,000 VU read workload reached all 10,000 VUs and FAILED:
82.75% HTTP errors, p95 10.52s. A separate 100 VU
run passed with zero errors; post-overload 10 VU recovery also passed. Shared host,
4-CPU/12-GiB generator and short synthetic workload do not establish production capacity.

Full paid public HTTP/provider/Tenant E acceptance, real provider/fiscal sandbox,
exact-revision remote CI, production restore/PITR, HA/failover, alerts and deployment
rehearsal remain open. Existing development CLI containers still require managed
recreation for the corrected Docker probe; actual CLI health passes. Backup artifact
tests passed, but the existing backup worker has stale success health. Older
checkpoints below are historical and do not override this verdict.


## Historical Core closure status — 2026-10-26

**Verdict: `CORE_NOT_READY`.** Source base:
`32bd483664b3762f2fc3014ef737750fd3a3e413`, plus the uncommitted
working-tree diff. Source manifest SHA-256 (the sorted `path` plus Git blob ID
for all 18 changed code, contract and architecture files, excluding this
readiness log):
`75f6bfa59263b0908f252f07f3427445ce786d73521f6c37c24f38043786b3bd`.
Remote CI cannot attest to that diff. The E16 test assertion
covers CORE-13 and ENT-01/02/03. The new E12 behavior and its migration,
OpenAPI and tests cover SUB-01/06/07, BILL-01/02/04, PAY-03/04/05/06 and
CORE-13/ENT-01/02/03. Earlier checkpoints below are historical and do not
override this verdict.

Current local quality evidence on the working tree:

| Gate | Result |
| --- | --- |
| Backend Psalm, locked dependencies | PASS: 0 errors (`--no-cache --no-progress --output-format=json`) on 2026-10-26. Historical 259 errors were fixed before this revision; this task started at 0 and added no suppressions. |
| Backend Codeception, seed `2120741368` | PASS: 508 tests, 14,050 assertions on 2026-10-26 in a no-bind container from the locked cross-plane image, with the same Backend source, isolated Core DB, test environment and copied contract files. The official `backend-test` compose container cannot start because Docker Desktop rejects host bind mounts. An earlier full run on 2026-10-23 passed in the official container with the same Backend source. |
| Control Plane PHPUnit | PASS: 366 tests, 4,514 assertions on the final code, after a fresh isolated Commercial reset. The no-bind image run was necessary after Docker Desktop refused host bind mounts. |
| Control Plane PHPStan | PASS: 0 errors on the E12 code and final tests. |
| Control Plane service wiring | PASS: `php bin/console lint:container` with the isolated test environment. |
| Cross-plane PHPUnit | PASS: 8 tests, 319 assertions on the final image, including actual HTTP M2M/Yii CLI and the E12 real boundary. It used both isolated test networks and copied the OpenAPI contract into a no-bind container. CI discovers all eight through `phpunit.cross-plane.xml` and `.github/workflows/ci.yml`. |
| HTTP/M2M runtime, X12, X15, X18 | PASS in the cross-plane group: actual HTTP M2M pull and Yii CLI sync, real renewal boundary, scheduler SIGKILL/restart, signed document replay. X18 tests duplicate signed pull; internal subscription outbox rows have no Tenant event consumer. |
| E20 Docker restart | PASS: `core-app-restart-test.sh` restarted `backend-test` while `postgres-test` stayed up, retained a durable row and ran `scheduler:run` successfully. |
| OpenAPI/route parity | PASS in the final Control Plane suite and focused 10-test run; the new route and response schema are covered. |
| Migrations | PASS: Core fresh test schema applied 32/32 migrations and required runtime objects; Commercial guard reset then applied 40/40 migrations on `platform_commercial_test`. Docker Desktop bind failure required copying the unchanged Core SQL runner into the isolated PostgreSQL test container. |
| Test DB isolation | PASS: `control:test:reset` refused `APP_ENV=dev`; the successful reset required its isolated test host/database guard. Core reset touched only the named test volumes. |
| Compose and diff | PASS: `docker compose config --quiet`; `git diff --check`. |

E01–E20 acceptance is evaluated against the scenario names supplied for this
closure. `PARTIAL` means a passing component/integration or cross-plane test
exists, but the listed complete scenario boundary has not been executed. A
passing narrower test is not promoted to full acceptance. Provider behavior in
these tests uses local fakes, not YooKassa sandbox charges.

| ID | Status | Existing evidence and exact remaining acceptance boundary |
| --- | --- | --- |
| E01 Initial purchase | PARTIAL | `PaymentSettlementTest`, `CrossPlaneBootstrapTest`: paid DB, audit/outbox and signed Tenant rights; public purchase HTTP response is not joined to that flow. |
| E02 Failed payment | PARTIAL | `YooKassaCheckoutTest`, `CrossPlaneBootstrapTest`: failed attempt keeps Tenant rights empty; no full failed public checkout HTTP to Tenant trace. |
| E03 Repeated payment request | PARTIAL | `PaymentCreationTest`, `YooKassaCheckoutTest`: one payment/attempt and stable keys; no repeated public HTTP request with ledger and Tenant readback in one trace. |
| E04 Duplicate webhook | PARTIAL | `YooKassaWebhookTest`: deduplicated inbox and one settlement; no repeated webhook HTTP through signed Tenant readback. |
| E05 Out-of-order webhook | PARTIAL | `YooKassaWebhookTest`: authoritative success resists stale cancellation; no reordered webhook HTTP through signed Tenant readback. |
| E06 Paid upgrade | PARTIAL | `SubscriptionPlanChangeTest`, `CrossPlaneBootstrapTest`: one paid switch and signed Tenant change; public plan-change/payment HTTP response is not joined to that trace. |
| E07 Failed upgrade | PARTIAL | Same tests: failed attempt keeps old Tenant feature; no failed public upgrade checkout HTTP trace. |
| E08 Scheduled downgrade | PARTIAL | `CrossPlaneDowngradeBoundaryTest`: paid/free real boundary and signed Tenant state; public scheduling HTTP response is not joined to the boundary trace. |
| E09 Cancelled downgrade | PARTIAL | `SubscriptionPlanChangeTest`, `CrossPlaneBootstrapTest`: cancellation retains old rights; public cancellation HTTP response is not joined to Tenant readback. |
| E10 Renewal before expiry | PASS | `CrossPlaneDowngradeBoundaryTest` `renewal-restart`: settlement before the real boundary, one advance, immutable money/history, one audit/outbox effect and stable signed Tenant rights. |
| E11 Renewal/expiry race | PARTIAL | `RenewalConcurrencyTest` uses independent processes and checks financial outcomes; no signed Tenant state in the same race. |
| E12 Auto-renew cancellation | PARTIAL | The customer rule is now explicit: keep access to the end of the paid period and stop future charges. `SubscriptionAutoRenewCancellationTest` covers idempotency, issued unpaid invoice voiding, pending provider attempt rejection/reconciliation, paid renewal and expiry; `CrossPlaneDowngradeBoundaryTest` checks signed Tenant rights across a real unpaid boundary. The public cancellation HTTP response has not been joined to that signed Tenant trace, and an already-paid next period has not crossed its later boundary in real time. |
| E13 Refund | PARTIAL | `RefundLifecycleTest`, `YooKassaRefundWebhookTest`, `CrossPlaneBootstrapTest`: immutable refund effects and late-upgrade Tenant rights; no complete public refund HTTP to Tenant trace for the named generic case. |
| E14 Duplicate asynchronous event | NOT_APPLICABLE_BY_ARCHITECTURE | `subscription.activated`/`cancelled` outbox rows are internal Commercial records with no Tenant consumer. Tenant retrieves authenticated signed documents; X18 repeats the actual transport and rejects stale sequence. |
| E15 Worker restart | PASS | `CrossPlaneDowngradeBoundaryTest` `renewal-restart`: scheduler killed before commit, fresh process advances once, signed Tenant replay remains stable. |
| E16 Partial cross-plane failure | PASS | `CrossPlaneHttpRuntimeTest`: Commercial commit, HTTP M2M document, failed Tenant CLI sync against unavailable Tenant DB, unchanged Commercial effects and no new Tenant right, restored sync to latest sequence, duplicate sync unchanged. |
| E17 Concurrent plan changes | PARTIAL | `SubscriptionPlanChangeTest`: independent process races produce one change/invoice and preserve old rights; no signed Tenant readback in the same race. |
| E18 Tenant isolation | PARTIAL | `CrossPlaneBootstrapTest` proves distinct account documents and rejects the second account's signed bytes for the first installation; two simultaneously active Tenant runtimes have not been checked. |
| E19 Repeated provisioning | PARTIAL | `DeploymentProvisioningTest` rejects repeated start and replays ready without duplicate audit; repeated real runtime provisioning and Tenant state are not covered. |
| E20 Docker/runtime restart | PASS on 2026-10-23; final rerun blocked by environment | `core-app-restart-test.sh` restarted the application container with live durable PostgreSQL and a post-restart job on the unchanged Backend source. Docker Desktop host bind failure prevents a 2026-10-26 repeat. |

**Local Core blockers:** complete the `PARTIAL` acceptance boundaries with the
smallest existing-harness extensions, including E12 public HTTP and final paid
boundary. These prevent `CORE_CODE_READY_LOCAL`. Docker Desktop currently
rejects host bind mounts with `mkdir /run/desktop/mnt/host/c: file exists`, so
the official compose Backend and Control test containers and E20 restart cannot
be rerun. The no-bind image runs above verify the source-level tests but do not
replace those exact compose/runtime gates. No confirmed P0/P1 code or security
defect was found in the finite review of authorization, financial replay/locking,
signed entitlement rejection, worker recovery, secret handling and database
ownership.

**External release prerequisites:** YooKassa sandbox credentials/testing and
remote CI for the exact committed revision. The clean cross-plane test image
was rebuilt locally with locked dependencies in this run.

**Production operational backlog:** hosting, DNS/TLS, production secrets,
backup/PITR and restore, failover, alerts, deployment rehearsal and load tests.
These are separate from local Core code acceptance.

## Historical execution log

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
  A later native Windows PHP 8.3.30 run of `ProvisionerBoundaryTest` passed
  11/11 tests, 142 assertions, including the fail-closed secret-store branch.
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
| W05-W06 | PASS locally: plan-change checkout survives lost CREATE response plus SIGKILL/restart with one provider object and one settlement; repeated plan-change reconciliation selects once and leaves one settlement, audit, outbox event, and target feature set. Provider sandbox remains NOT_RUN. |
| W07-W08 | PASS locally for duplicate refund notification and simultaneous webhook/reconciler delivery; provider sandbox remains NOT_RUN. |
| W09-W10 | PASS in isolated mock fault tests: both charge and refund SIGKILL/restart converge with one provider object and one local effect. Provider sandbox restart remains NOT_RUN. |
| X01 | PASS: initial paid subscription reaches Tenant runtime. |
| X02 | PASS: failed first purchase leaves Tenant runtime rights empty; successful retry grants them. |
| X03-X05 | PASS: unpaid/failed upgrade retains old runtime rights, paid upgrade switches them. |
| X06-X10 | PASS locally: X06 and X10 retain rights before the boundary or after cancellation. X07-X09 now cross a real short subscription boundary, apply signed documents in Tenant Plane, and verify free rights after materialization, no paid target rights before settlement, and paid target rights after settlement. The new boundary cases call the issuer and Tenant applier directly; HTTP M2M delivery and live scheduler operation are separate gates. |
| X11 | PASS: expiry removes runtime rights. |
| X12 | NOT_RUN in Tenant runtime: paid renewal versus expiry at a real boundary. |
| X13-X14 | PASS locally: late paid upgrade remains unapplied with no target Tenant rights; verified full fake-provider refund releases the change without granting them. |
| X15 | PARTIAL: refund finalizer and Tenant entitlement pull replay are idempotent in one process; worker restart/outbox replay is NOT_RUN. |
| X16 | PASS: stale signed document cannot restore old rights. |
| X17 | PASS locally: second tenant credential pulls its own signed feature set; its document is rejected by the first Tenant installation, which keeps its own rights. |
| X18 | PARTIAL: repeated Tenant pulls after refund and expiry preserve the same sequence and rights; repeated activation/cancellation event delivery is NOT_RUN. |
| E01-E20 | NOT_RUN as the specified full API + DB + ledger + audit + inbox/outbox + Tenant matrix. Narrower component tests above do not satisfy it. |

Next concrete independent P0 step: complete the remaining Tenant runtime cases
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

### 2026-10-15: repeated plan-change payment reconciliation

- W05 is covered by the existing `testCheckoutConvergesAfterProviderChargeAndHardProcessKill`:
  provider CREATE succeeds before SIGKILL, the response is lost, and a new
  process reuses the provider request key. W06 now also runs a plan-change
  reconciliation twice. The first pass settles the payment; the second selects
  no pending attempt. Invoice, change, payment, audit, outbox, and effective
  features agree. Focused W06 test: 1/1, 21 assertions. Both use local fake
  providers; sandbox proof remains open. Final post-change full Control Plane
  suite: 361/361, 4444 assertions; PHPStan: zero errors; `git diff --check`:
  clean.

### 2026-10-15: logical restore with migration 39

- Created a PostgreSQL custom-format dump from the isolated
  `control-postgres-test/platform_commercial_test` database, verified its
  `pg_restore --list`, and restored it with `--exit-on-error` into a newly
  created `platform_restore_rehearsal_39_test` database. Source and clone
  counts match: 39 migrations, 116 accounts, 50 plan changes, 172 invoices,
  162 payments, 4 refunds, 2 external attestations, 280 idempotency rows,
  835 audit events, and 740 outbox events. Doctrine on the clone reports
  39 executed migrations and zero new migrations. The read-only
  `control:ops:status` command booted against the clone. Its scheduler and
  outbox worker heartbeats are `unknown`, and fixture backlogs remain; this
  does not demonstrate worker recovery or operational health. The dump and
  clone remain inside the isolated test container for inspection.

### 2026-10-15: crash-test fixture isolation

- The two payment/refund SIGKILL tests now disable their committed fake
  YooKassa account in `finally` even if a fault assertion fails. This keeps
  a failed fault-injection check visible without contaminating subsequent
  provider-location tests. Focused crash tests: 2/2, 62 assertions. Final full
  Control Plane rerun: 361/361, 4444 assertions; PHPStan: zero errors;
  `git diff --check`: clean. This does not resolve the remaining runtime
  fault/worker evidence gaps above.

### 2026-10-15: native Windows provisioner boundary

- Ran `php vendor/bin/phpunit --filter ProvisionerBoundaryTest --do-not-cache-result`
  with native Windows PHP 8.3.30. All 11 tests and 142 assertions passed. The
  local Linux Docker provider rejects Windows before `mkdir` or POSIX `chgrp`;
  the test still checks that no secret directory is created. Linux behavior
  remains covered by the Docker suite. This validates the documented platform
  split, not a Windows production provisioner.

### 2026-10-15: migration rollback preserves financial history

- On the restored clone only, `doctrine:migrations:migrate prev --no-interaction`
  attempted to reverse migration 39. It failed as designed with PostgreSQL
  `23514 external compensation history must be retained`. A read-back still
  shows 39 migration records, 2 immutable compensation attestations, 6
  financial operator authorizations, and the attestation table present.
  No source database or live deployment was rolled back.

### 2026-10-16: real-boundary Tenant downgrade coverage

- Added `CrossPlaneDowngradeBoundaryTest` for X07-X09. Two isolated scenarios
  create a source period with about five seconds remaining, cross its actual
  boundary, and open a new Commercial transaction. A paid downgrade issues an
  invoice and leaves Tenant rights empty before settlement; settlement then
  applies the lower paid feature. A free downgrade applies its feature at the
  boundary. Both verify signed document application in the separate Tenant
  database and the source subscription state. This uses local fake payment
  completion, not a real provider or M2M HTTP transport.
- Focused cases: 2/2, 59 assertions. Full cross-plane group: 3/3, 147
  assertions. PHPStan: zero errors. Full non-cross-plane Control Plane suite:
  361/361, 4444 assertions. `git diff --check`: clean. An initial failed
  iteration committed one temporary Tenant installation; after verifying its
  `release_id=boundary-test` and that it was the only installation/document in
  the isolated test database, those two rows were removed. The final tests
  roll back Tenant fixtures. Commercial fixtures crossed a commit and remain
  only in the isolated test database; random identifiers make repeated runs
  independent.
- Remaining local P0 includes X12 paid renewal and X15/X18 worker or event
  replay in Tenant runtime, plus the complete provider sandbox and E01-E20
  matrices. Scheduler, remote CI, and production evidence remain unverified.
  Verdict: `CORE_NOT_READY`.

## Historical Core closure snapshot: 2026-10-16

This section supersedes earlier continuation checklists as the current status.
Historical results above retain their original scope and dates. The affected
invariants are SUB-01/06/07, PAY-01/03/04/05/06, CORE-12/13, and ENT-01/02/03.
No production application code, migration, or wire contract changed in this
closure; the changes are test discovery, test coverage, and the gate script.

| Gate | Status | Current evidence or exact remaining boundary |
| --- | --- | --- |
| Cross-plane discovery | PASS | `phpunit.cross-plane.xml` scans `tests` and includes the `cross-plane` group. Both original classes and `CrossPlaneHttpRuntimeTest` appear in `--list-tests`; ordinary PHPUnit excludes the group. GitHub Actions calls `scripts/cross-plane-test-run.sh`. |
| X12 paid renewal versus expiry | PASS | A genuine period boundary is crossed in `CrossPlaneDowngradeBoundaryTest`. Settled renewal advances once, keeps paid Tenant rights, and leaves one audit/outbox effect; unsettled renewal respects grace then expires and removes rights. |
| X15 real process death | PASS | `control:scheduler:run` is SIGKILLed while PostgreSQL pauses the renewal update before commit. A fresh scheduler advances durable paid state once; signed Tenant entitlement applies once and replay stays stable. This is scheduler recovery, not a claim that every outbox consumer was fault injected. |
| X18 signed entitlement replay | PASS | `subscription.activated`/`subscription.cancelled` are internal Commercial outbox rows, not Tenant delivery contracts. The authoritative Tenant path is authenticated signed-document pull. Repeated pull, exact replay and monotonic sequence are tested; duplicate delivery of those internal events is `NOT_APPLICABLE_BY_ARCHITECTURE`. |
| Real HTTP/runtime boundary | PASS | `CrossPlaneHttpRuntimeTest` starts a separate Symfony HTTP process, checks invalid and valid M2M credentials, calls the actual Yii `runtime:entitlements:sync` command for sequences 1 and 2, and rejects stale and wrong-installation signed documents. The renewal crash test runs the actual scheduler command. |
| E16 focused recovery path | PASS | After Commercial commit, a Tenant CLI sync with unreachable DB fails. Financial/audit/outbox state is unchanged, Tenant stays at sequence 1, and a later successful sync reaches sequence 2 without duplicate effects. Full E16 acceptance still needs its API surface. |
| E20 stateless container restart | PASS | `core-app-restart-test.sh` restarts only `backend-test` with `postgres-test` continuously running, checks a durable runtime row, then confirms a real `scheduler:run` auth cleanup job succeeds after restart. CI runs the same script. |
| YooKassa sandbox | BLOCKED_EXTERNAL | No sandbox credential or shop ID is available to this environment. No real charge was made. |
| Clean official cross-plane image rebuild | BLOCKED_EXTERNAL | After the final test-only DB guard edit, Docker Composer downloads failed with curl 56/OpenSSL bad record MAC from `codeload.github.com`. The current code passed with the rebuilt `control-plane-test` image and mounted locked backend dependencies. |
| Remote CI for current working tree | NOT_RUN | The changed working tree is not a pushed revision, so no GitHub Actions run can attest to these bytes. |
| Core code verdict | FAIL | `CORE_CODE_READY_LOCAL` is withheld while the complete E01-E20 acceptance matrix is unrun and backend Psalm fails on the current locked dependencies. |
| Release candidate | FAIL | Core code readiness has not been met; provider sandbox and green remote CI for the exact revision are also missing. |
| Production readiness | NOT_RUN | Requires separate deployment and operational evidence, including secrets, recovery, monitoring, load, and rehearsal. |

Local closure regression observed on 2026-10-16:

| Check | Status | Fresh result |
| --- | --- | --- |
| Cross-plane `--list-tests` | PASS | Three grouped classes, seven selected cases with the final source configuration. |
| Cross-plane PHPUnit | PASS | 7 tests, 289 assertions after the E16 fault assertion was added. Earlier fresh-DB and repeated runs passed 7/281; the current source runs in rebuilt `control-plane-test` with mounted backend dependencies. The clean official `cross-plane-test` image rebuild is separately blocked above. |
| Ordinary Control Plane PHPUnit | PASS | 361 tests, 4444 assertions after isolated Commercial reset/migration. |
| Backend Codeception full seeded suite | PASS | 508 tests, 14050 assertions with seed `2120741368`. |
| Backend Psalm | FAIL | `vendor/bin/psalm --no-cache` reports 259 errors in untouched backend sources; JSON rerun reports the same count. Dominant types: 98 `MixedArgument`, 20 `MixedPropertyFetch`, 19 `PossiblyNullArgument`. No suppression or backend source edit was made in this closure. |
| Control Plane PHPStan | PASS | No errors in `cross-plane-test` image. |
| OpenAPI/route parity | PASS | 3 tests, 47 assertions; included in ordinary PHPUnit. |
| Commercial migrations | PASS | Fresh 39-migration apply, then latest 38→39→38→39 cycle in isolated DB. |
| Core migrations | PASS | Fresh `platform_test` DB through migration 032; required Core and runtime objects reported OK. |
| Runtime HTTP and restart gates | PASS | Included in the seven cross-plane cases on fresh DBs. |
| E20 Docker restart gate | PASS | `bash ./scripts/core-app-restart-test.sh` passed locally twice; PostgreSQL process stayed up and the post-restart job succeeded. |
| Fixture hygiene | PASS | After the repeat run, zero Core installations/documents and zero X15 pause triggers remain. |
| `git diff --check` | PASS | No whitespace errors. |

### E01-E20 complete acceptance status

The current task context names these scenarios and requires API, Commercial DB,
financial history, audit, inbox/outbox, Tenant state, and replay checks as
applicable. Existing narrower component or cross-plane tests do not satisfy
that combined acceptance contract. `NOT_RUN` means the complete scenario has
not been executed; it does not discard the narrower passing evidence above.

| Case | Supplied scenario | Status |
| --- | --- | --- |
| E01 | First successful purchase | NOT_RUN |
| E02 | Failed payment | NOT_RUN |
| E03 | Duplicate payment request | NOT_RUN |
| E04 | Duplicate webhook | NOT_RUN |
| E05 | Reordered webhook | NOT_RUN |
| E06 | Paid upgrade | NOT_RUN |
| E07 | Failed upgrade | NOT_RUN |
| E08 | Scheduled downgrade | NOT_RUN |
| E09 | Cancelled downgrade | NOT_RUN |
| E10 | Renewal before expiry | NOT_RUN |
| E11 | Renewal versus expiry race | NOT_RUN |
| E12 | Cancel auto-renew | NOT_RUN |
| E13 | Refund | NOT_RUN |
| E14 | Duplicate internal subscription event delivery to Tenant | NOT_APPLICABLE_BY_ARCHITECTURE |
| E15 | Worker crash and restart | NOT_RUN |
| E16 | Commercial commit, Tenant unavailable, recovery sync | NOT_RUN |
| E17 | Two simultaneous plan changes | NOT_RUN |
| E18 | Tenant isolation | NOT_RUN |
| E19 | Repeated provisioning | NOT_RUN |
| E20 | Stateless Core app container restart with live PostgreSQL | PASS |

E14's duplicate delivery case does not apply because the named internal
subscription events have no Tenant consumer. E16 now has an explicit fault
point and convergence criterion; the focused runtime fault check passes, but
its full API-level acceptance remains `NOT_RUN`. E20 covers only the stateless
Core application container with PostgreSQL alive. PostgreSQL restart and
restore/PITR remain production reliability work.

### Finite readiness boundary

- `CORE_CODE_READY_LOCAL`: all locally controllable R/F/W/X and defined E
  cases, real HTTP and restart gates, full regressions, static analysis,
  contracts, and fresh isolated DB reproduction pass, with no open P0 defect.
- `CORE_RELEASE_CANDIDATE`: `CORE_CODE_READY_LOCAL` plus required YooKassa
  sandbox, exact-revision remote CI, and release/security artifacts.
- `PRODUCTION_READY`: separately requires operational deployment, secrets,
  failover/PITR restore, alerts, capacity/load, and rehearsal evidence.

Core code blockers are the unrun complete E matrix and the current backend
Psalm failure. External release
blockers are YooKassa sandbox prerequisites and exact-revision remote CI.
The clean official image rebuild is also pending external Composer availability.
Production operational work is separate from Core code closure. Do not reopen
Core for new features after all locally controllable gates pass.
