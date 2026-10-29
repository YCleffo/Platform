# Бизнес-процессы Platform

Исходники диаграмм находятся в `.mmd`, готовые изображения — в `svg/`.
Физическая структура описана в [справочнике БД](../database/README.md).

| Процесс | Изображение | Код и проверки |
| --- | --- | --- |
| Границы Core / Commercial / Tenant | [Архитектура](svg/01-boundaries.svg) | `CORE_INVARIANTS.md`, cross-plane tests |
| Регистрация клиента и подтверждение email | [Регистрация](svg/02-registration.svg) | `AuthService`, `ControlAuthTest`, `OutboxEmailTest` |
| Вход Core, MFA и отзыв сессии | [Аутентификация](svg/03-core-auth.svg) | `AuthenticationRuntimeCest`, `MfaAuthenticationCest`, `SessionLifecycleCest` |
| Права пользователя и сервисные учётные данные | [Авторизация](svg/04-authorization.svg) | RBAC, organization scope, `ServiceCredentialsCest` |
| Создание и публикация тарифа | [Каталог](svg/05-catalog.svg) | `PlanCatalog`, `CatalogLifecycleTest`, PostgreSQL guards |
| Первая покупка | [Покупка](svg/06-purchase.svg) | `CreateSubscription`, `IssueInvoice`, `CreatePayment`, `PaymentSettlementTest` |
| Повышение и понижение тарифа | [Смена тарифа](svg/07-plan-change.svg) | `RequestSubscriptionPlanChange`, `SubscriptionPlanChangeTest` |
| Продление и отмена автопродления | [Продление](svg/08-renewal.svg) | renewal/dunning jobs, `SubscriptionAutoRenewCancellationTest` |
| Возврат и ручная компенсация | [Возврат](svg/09-refund.svg) | refund services, external compensation, `RefundLifecycleTest` |
| Развёртывание и доставка прав | [Tenant](svg/10-provisioning.svg) | provisioning executor, signed documents, actual HTTP/CLI tests |
| Письма и повторная доставка | [Почта](svg/11-email.svg) | Core Communication, Control outbox, Mailpit smoke |
| Персональные данные | [Privacy](svg/12-privacy.svg) | consent, representation, requests, legal holds, erasure |
| Фоновые задачи и восстановление | [Workers](svg/13-workers.svg) | `BACKGROUND_JOBS.md`, restart/concurrency tests |

В Control Plane есть публичная регистрация email/password. В Core создание
человека и Account, приглашение и активация — отдельные операции; общего
публичного `/register` для Core нет. Person, Core Account, Control User,
Commercial Account и Organization нельзя объединять по одинаковому email.

Добавление тарифа сейчас проверяется через модель и SQL-ограничения. Публичный
HTTP-каталог умеет читать опубликованные планы. Редактор тарифов и frontend
кабинета в этом репозитории не реализованы.

Повторная доставка разрешена только с сохранением идентичности операции:
financial idempotency key, provider request key, inbox event identity,
deployment operation identity и signed document sequence решают разные задачи.
Одна общая галочка «идемпотентно» не заменяет эти проверки.
