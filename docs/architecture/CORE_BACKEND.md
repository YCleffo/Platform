# Архитектура backend-приложения Core

## Направление зависимостей

Вертикальные срезы Core следуют одному направлению зависимостей:

```text
HTTP action -> application command/use case -> repository interface
                                             -> PostgreSQL adapter -> core.*
```

Action разбирают HTTP-ввод и формируют ответы, но не содержат SQL. Классы приложения
не зависят ни от Yii DB, ни от синтаксиса PostgreSQL. Приведения типов PostgreSQL,
enum, JSONB, `SKIP LOCKED`, значения UUID по умолчанию и сопоставление строк находятся
в `Infrastructure`.

## Намеренно ограниченная граница API

HTTP-граница включает ресурсы Person, PersonContact, Account, Role и Permission,
authentication/session runtime, а также защищённый configuration control plane для реестра модулей,
системных и модульных настроек и feature flags. Configuration routes требуют явных permissions;
публичным без аутентификации является только фильтрованный `GET /api/public/config`.

Authentication runtime включает password, Google OIDC и Telegram OIDC как first-factor adapters,
но ни один adapter не создаёт сессию самостоятельно. `AuthenticationFlowService` применяет account/MFA
policy, а единственный `SessionIssuer` выдаёт opaque access/refresh tokens только после всех обязательных
факторов. External login и explicit account linking используют разные одноразовые challenge purpose.

Публичные представления Account не включают `security_stamp`, сведения о блокировке/входе,
данные о создателе/подтверждающем и любые учётные данные. Auth API никогда не отдаёт hashes токенов,
паролей или challenge secrets. Настройки не являются secret storage: deny policy отклоняет
secret-like ключи, включая вложенные JSON-ключи. Публичная конфигурация включает только значения
`system_settings.is_public = true` и не публикует DB metadata, module settings или feature flag config.

`core.modules` остаётся контролируемым реестром metadata и runtime enable state. Он не загружает PHP-код
из БД и не является plugin framework. Core знает конфигурацию доменных модулей, но не их бизнес-логику.
`FeatureFlagService` принимает выбранный вызывающим кодом стабильный subject; для authenticated rollout
используется account UUID. Сервис не извлекает identity из HTTP и не использует IP, User-Agent,
email, phone, session token или fingerprint как rollout identity.

## Валидация и пагинация

Валидация запросов Person/Account соответствует ограничениям БД для enum, длины,
пустых значений, дат, UUID и nullable-полей. Для некорректного тела возвращается 422
через существующий `ResponseFactory`; некорректный UUID в пути отклоняется до выполнения SQL.
Списковые endpoint используют ограниченную пагинацию по смещению (`limit` по умолчанию 50,
максимум 100; `offset` по умолчанию 0) и возвращают `items`, `limit`, `offset` и `total`.

## Транзакции и параллельный доступ

- Основные контакты Person заменяются в транзакции БД, чтобы частичный
  unique index remains the final invariant.
- Для операций с несколькими таблицами можно использовать транзакцию внедрённого Yii `ConnectionInterface`; репозитории участвуют в текущей транзакции.
- Upsert настроек и feature flags выполняется атомарно через PostgreSQL `INSERT ... ON CONFLICT DO UPDATE`; mutation и actor-aware Audit входят в одну транзакцию.
- Переключение module lifecycle сериализуется блокировкой строки, идемпотентно и не изменяет `installed_at`.
- Для получения событий Outbox используется короткая аренда и `FOR UPDATE SKIP LOCKED`.
- Уникальность Inbox по `(event_id, consumer)` обеспечивает идемпотентность.
- Записи Audit добавляются без возможности изменения; сервисов обновления/удаления нет.
- Завершение MFA, привязка/отвязка внешней identity, включение/отключение TOTP, замена recovery codes
  и email-восстановление выполняются транзакционно вместе с Audit и Outbox notification.

Универсальный репозиторий и собственный менеджер транзакций не вводились. Общий
код ограничен действительно сквозными задачами: пагинацией, проверкой UUID и
исключением конфликта.
