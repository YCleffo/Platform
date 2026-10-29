# События между процессами

`contracts/events/envelope.v1.schema.json` задаёт ID события, тип и версию, время, производителя, aggregate, correlation/causation и payload. Версионированные схемы и валидные fixtures существуют для `control_user.email_verification_requested`, `deployment.provision_requested` и `entitlement.issued`.

Изменение обязательной структуры требует новой версии и совместимого потребителя. Fixtures проверяются в unit suite Core. Внутреннее событие аудита или outbox не становится интеграционным контрактом автоматически.

## Доставка

Commercial outbox хранит `schema_version`, lease, количество попыток и terminal/dead состояние. Почтовый worker обрабатывает зарегистрированные email events. Доставка допускает повторы; потребитель отвечает за дедупликацию по идентичности события. Core имеет inbox с уникальностью `(event_id, consumer)` для поддерживаемых входящих операций.

Универсальный транспорт публикации всех событий между Commercial и Core не реализован. Наличие схемы JSON и таблицы outbox не означает наличие такого транспорта.

## Документы прав

Выдача новой подписанной версии записывает `entitlement.issued` в той же транзакции. Payload содержит `entitlement_document_id`, `installation_id`, `document_sha256`. Байты документа, подпись и приватные ключи в outbox не копируются. Неизменившийся источник не создаёт новую версию и событие.

Core получает документ через аутентифицированный M2M pull и проверяет подпись, доверенный ключ, установку, срок и монотонную sequence. Событие outbox не заменяет получение и проверку документа.

`subscription.activated`, `subscription.cancelled`, `subscription.auto_renew_disabled` — внутренние события Commercial. У них нет Tenant consumer. Права Tenant сходятся через получение последнего подписанного документа, включая повторную или задержанную синхронизацию.

[Подписанная доставка](M2M_SERVICE_AUTHENTICATION.md) · [Фоновые процессы](BACKGROUND_JOBS.md)
