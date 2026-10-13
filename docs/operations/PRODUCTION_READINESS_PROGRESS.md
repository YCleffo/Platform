# Production readiness: состояние работ

Срез: 2026-10-13. Это рабочий журнал выполнения приложенного ТЗ, а не акт
приёмки production. Источники истины: миграции, код, OpenAPI и выполненные
проверки. Непроверенный пункт не отмечается как завершённый.

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
