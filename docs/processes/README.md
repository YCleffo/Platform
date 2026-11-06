# Бизнес-процессы Platform

Исходники диаграмм находятся в `.mmd`, готовые изображения — в `svg/`.
Физическая структура описана в [справочнике БД](../database/README.md).

| Процесс | Изображение | Код и проверки |
| --- | --- | --- |
| Границы Core / Commercial / клиентской установки | [Архитектура](svg/01-boundaries.svg) | `CORE_INVARIANTS.md`, межсистемные тесты |
| Регистрация клиента и подтверждение почты | [Регистрация](svg/02-registration.svg) | `AuthService`, `ControlAuthTest`, `OutboxEmailTest` |
| Вход Core, MFA и отзыв сессии | [Аутентификация](svg/03-core-auth.svg) | `AuthenticationRuntimeCest`, `MfaAuthenticationCest`, `SessionLifecycleCest` |
| Права пользователя и сервисные учётные данные | [Авторизация](svg/04-authorization.svg) | RBAC, область организации, `ServiceCredentialsCest` |
| Создание и публикация тарифа | [Каталог](svg/05-catalog.svg) | `CatalogManagement`, `CatalogOperator`, `CatalogLifecycleTest`, `OperatorWorkspaceTest`, ограничения PostgreSQL |
| Первая покупка | [Покупка](svg/06-purchase.svg) | `CreateSubscription`, `IssueInvoice`, `CreatePayment`, `PaymentSettlementTest` |
| Повышение и понижение тарифа | [Смена тарифа](svg/07-plan-change.svg) | `RequestSubscriptionPlanChange`, `SubscriptionPlanChangeTest` |
| Продление и отмена автопродления | [Продление](svg/08-renewal.svg) | задачи продления и просрочек, `SubscriptionAutoRenewCancellationTest` |
| Возврат и ручная компенсация | [Возврат](svg/09-refund.svg) | сервисы возврата и внешняя компенсация, `RefundLifecycleTest` |
| Развёртывание и доставка прав | [Установка клиента](svg/10-provisioning.svg) | исполнитель подготовки, подписанные документы, реальные тесты HTTP и консоли |
| Письма и повторная доставка | [Почта](svg/11-email.svg) | Core Communication, исходящая очередь Control, короткая проверка Mailpit |
| Персональные данные | [Персональные данные](svg/12-privacy.svg) | согласия, представительство, запросы, запреты уничтожения и уничтожение |
| Фоновые задачи и восстановление | [Обработчики](svg/13-workers.svg) | `BACKGROUND_JOBS.md`, тесты перезапуска и конкурентности |

В Control Plane есть публичная регистрация по почте/паролю. В Core создание
человека и Account, приглашение и активация — отдельные операции; общего
публичного `/register` для Core нет. Person, Core Account, Control User,
Commercial Account и Organization нельзя объединять по одинаковой почте.

Добавление тарифа проверяется через модель и SQL-ограничения. Публичный HTTP-каталог
читает опубликованные планы; операторский API `/api/control/operator/catalog` создаёт
черновики и версии и публикует их после отдельного назначения в `commercial.catalog_operators`.
Опубликованные условия неизменяемы; интерфейс редактора — `/operator/catalog`.
Интерфейсы кабинета, расчётов, использования, аналитики, аудита, безопасности и остальных операторских сценариев уже есть.
Отдельный явно включаемый Education работает на учётных записях Core и собственной схеме:
[серверная часть Education](../architecture/EDUCATION_BACKEND.md).
Эти 13 диаграмм описывают общеплатформенные процессы и не являются полной схемой Education.

Следующие продуктовые задачи: [план развития](../roadmap/PLATFORM_ROADMAP.md).
Рост нагрузки, реплики и тиражирование: [план масштабирования](../roadmap/SCALING_PLAN.md).

Повторная доставка разрешена только с сохранением идентичности операции:
ключ финансовой идемпотентности, ключ запроса провайдеру, ID входящего события, ID операции установки и последовательность подписанного документа решают разные задачи.
Одна общая галочка «идемпотентно» не заменяет эти проверки.
