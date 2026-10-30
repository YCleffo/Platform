# Критерии выпуска

Текущий статус промышленного выпуска: `CORE_NOT_READY`. Рабочая локальная установка и успешные проверки схемы не подтверждают HA, восстановление после аварии или допустимую нагрузку.

| Область | Условие выпуска |
| --- | --- |
| Схема и совместимость | Миграции с пустой и предыдущей схемы, повтор без изменений, актуальные DBML, работоспособность приложения после обновления |
| Идентичность | Регистрация и подтверждение Commercial; активация Core; пароль, MFA, reset, отзыв сессий и разграничение организаций |
| Деньги | Идемпотентность, перестановка webhook, независимые соединения при гонках, неизменяемые снимки и ограничение возврата |
| Межсервисные операции | Подпись прав, монотонность sequence, отказ и восстановление между Commercial и Tenant, дедупликация |
| Фоновые процессы | Heartbeat, восстановление после остановки, lease/retry и отсутствие повторных финансовых эффектов |
| Инфраструктура | Минимальные runtime-привилегии, внешние секреты, TLS/DNS, проверенное восстановление и резервирование |
| Производительность | Воспроизводимый профиль нагрузки, согласованный SLO, задержка и ошибки на целевой инфраструктуре |
| Privacy | Заполненная политика обработки, retention, residency, одобренные основания и проверенное удаление с учётом holds |

Предыдущая нагрузка на 10 000 виртуальных пользователей не прошла: около 82,75% HTTP-ошибок и p95 10,52 с. Это результат локального испытания, а не доказанная вместимость промышленной установки. Удаление временных стендов не меняет этот вывод.

Основные release-проверки находятся в `.github/workflows/ci.yml`: Core QA, Commercial QA, cross-plane и инфраструктурные контракты. Запуск в GitHub требуется для подтверждения конкретного коммита. Локальные проверки без изолированных PostgreSQL не заменяют интеграционные задания.

[Инварианты](../architecture/CORE_INVARIANTS.md) · [Бизнес-процессы](../processes/README.md) · [Схема БД](../database/README.md) · [Обновление](SAFE_DATABASE_UPGRADE.md)

---

# Состояние готовности по плоскостям

Зелёный CI не означает «готово к production». Ниже разделены функциональная готовность компонентов и промышленная готовность.

## Функциональная готовность Core (Yii)

- Регистрация/подтверждение/вход/MFA/сброс пароля/отзыв сессий покрыты unit, console, functional и API-сьютами в CI (`backend-qa`), включая полный детерминированный прогон с seed `2120741368`.
- Схема `core` и `platform_runtime` проверяется миграционными и runtime-объектными проверками в CI.
- Производственный образ `prod` теперь имеет автоматическую проверку сборки и содержимого (см. ниже). Статус: `PASS` (автоматизированный gate присутствует; сборка и инспекция проверены локально и будут исполняться в CI).

## Функциональная готовность Commercial (Symfony Control Plane)

- Регистрация, подтверждение email, повторная отправка, вход, refresh, logout, MFA и каталог покрыты unit/integration-тестами (`control-plane-qa`).
- Добавлены недостающие read-эндпоинты: `GET /api/control/me` (текущий Commercial-пользователь) и `GET /api/control/accounts` (список доступных аккаунтов, только действующие членства, пагинация). Контракт `contracts/openapi/control-plane.yaml` обновлён; добавлены авторизационные и негативные тесты. Паритет «маршруты ↔ OpenAPI» проверяется `OpenApiRouteParityTest`.

## Готовность frontend

- Vue 3 + TypeScript (strict) приложение в `apps/frontend` собирается: `npm run typecheck`, `npm run lint`, `npm run build` проходят (проверено локально на Node 24).
- Реализованы экраны: регистрация, подтверждение email, повторная отправка, вход, MFA challenge, выход, каталог тарифов, личный кабинет и создание/просмотр Commercial Accounts. Все вызовы — реальный HTTP API; моков и зашитых пользователей нет.
- Токены хранятся только в памяти; persistent storage не используется.
- Локальная интеграция с живым backend: `NOT_VERIFIED` (в рамках этапа не поднимаются локальные PostgreSQL/Redis/backend-контейнеры).

## Готовность локальной интеграции

- `compose.yaml` поднимает backend (Core) и control-plane; frontend запускается отдельно через Vite с проксированием `/api/control` → control-plane и `/api` → Core.
- Полный end-to-end прогон в локальном окружении на этом этапе не выполнялся: `NOT_VERIFIED`.

## Production readiness

- `NOT_READY`. HA, PITR-восстановление, TLS/DNS, проверенное резервирование, SLO и профиль нагрузки не подтверждены. Наличие зелёного CI и рабочей локальной сборки этого не меняет.

---

# Матрица требований E01–E20

Нумерация E01–E20 относится к внешней производственно-технической спецификации, которая не хранится в репозитории, поэтому точную матрицу без искажений восстановить нельзя. Вместо вымышленных формулировок ниже приведены подтверждённые идентификаторы требований, зафиксированные в [CORE_INVARIANTS.md](../architecture/CORE_INVARIANTS.md), с привязкой к проверкам.

| Требование | Суть | Где проверяется | Статус |
| --- | --- | --- | --- |
| CORE-01/02 | Person ≠ Account; Core Account ≠ Control User | integration-тесты Core и Commercial | PASS (в CI) |
| COMM-01/02 | Control User и Commercial Account; явная связь через memberships | `ListCommercialAccountsTest`, `AccountAuthorization` | PASS |
| CAT-01/02 | Неизменяемость версий каталога и цен | `CatalogLifecycleTest`, guard-тесты | PASS |
| SUB-01 | Подписка по опубликованной цене | `SubscriptionCreationTest`, `SubscriptionConstraintsTest` | PASS |
| BILL-01/02/04 | Снимки счёта, minor units, блокировки | `InvoiceLifecycleTest`, `InvoiceConstraintsTest` | PASS |
| PAY-01/02/07/08 | Settlement, webhook-дедупликация, попытки | `PaymentSettlementTest`, `YooKassaWebhookTest`, `PaymentConcurrencyTest` | PASS |
| REF-02/04 | Ограничение возврата, конкурентность | `RefundConstraintsTest`, `RefundConcurrencyTest` | PASS |
| FISC-01/02 | Фискализация не отменяет оплату | `FiscalReceiptLifecycleTest` | PASS |
| CORE-12/PAY-03..06 | Идемпотентность финансовых операций | `CreateCommercialAccountTest`, `PaymentAttemptTest`, `ConcurrencyTest` | PASS |
| ENT-01/02/03 | Подписанные права, монотонность sequence | `DeploymentEntitlementIssuerTest`, `EffectiveEntitlementsTest`, cross-plane | PASS |
| CORE-13 | Права выводятся из подписки, не отдельная таблица | `EffectiveEntitlementsTest` | PASS |
| PROV-03 | Provision-операции возобновляются после сбоя | `DeploymentProvisioningTest`, `UpdateDeploymentExecutorTest` | PASS |
| HTTP body limit | Отклонение oversized body без полной буферизации | `SecurityBaselineSubscriberTest` | PASS (unit) |
| Core prod image | Сборка `prod`, без dev-зависимостей и тестов, непривилегированный runtime | `ci.yml` → `Build and inspect production Core image` | PASS (автоматизированный gate) |

Элементы, не имеющие автоматизированного подтверждения в репозитории (производственная нагрузка, HA, DR, TLS/DNS, реальная интеграция с YooKassa и провайдером), остаются `NOT_VERIFIED` и не объявляются `PASS`.
