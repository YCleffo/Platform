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
