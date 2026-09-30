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

Identity domains не объединяются: Yii Core Account != Control User != Commercial
Account; service identity != human account. `account_type=service/system` не
означает разрешение использовать human password login для M2M. Следующий
security boundary — hashed service credentials, scopes, expiry, rotation,
revocation и `last_used_at` (Phase 4.2).

Authentication runtime включает password, Google OIDC, Telegram OIDC и WebAuthn Passkeys (Level 3) как first-factor adapters,
но ни один adapter не создаёт сессию самостоятельно. `AuthenticationFlowService` применяет account/MFA
policy, а единственный `SessionIssuer` выдаёт opaque access/refresh tokens только после всех обязательных
факторов. External login, explicit account linking и passkey ceremonies используют разные одноразовые challenge purpose.
`must_change_password` блокирует обычную сессию после first factor до
одноразовой password-change challenge. Authenticated self-change требует
текущий пароль и recent authentication, меняет Argon2id hash и security
stamp, отзывает другие сессии и создаёт Audit/security email.

Core Settings v2, Consent, Notification Policy и Presence описаны в соседних
architecture docs. Управление non-system ролями требует `core.rbac.manage`;
защищённые системные роли и last-superadmin invariant остаются под отдельной
защитой. Модули регистрируют собственные permission codes своими migrations.

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
- Операции с Passkeys строго сериализованы: потребление challenge использует атомарный CAS gate, обновление
  счетчика для аппаратных токенов защищено CAS условием `sign_count = :expected`, а отзыв passkey и отвязка identity
  используют `CredentialMutationGuard` с блокировкой строки аккаунта `SELECT ... FOR UPDATE NOWAIT`, гарантируя
  сохранение последнего метода аутентификации при любых параллельных запросах.

Универсальный репозиторий и собственный менеджер транзакций не вводились. Общий
код ограничен действительно сквозными задачами: пагинацией, проверкой UUID и
исключением конфликта.

## Очистка устаревших артефактов (Maintenance Cleanup)

Для удаления устаревших данных аутентификации предоставляется консольная команда:

```bash
php yii auth/cleanup
```

### Удаляемые сущности:
1. **Auth challenges**: истёкшие (`expires_at < now() - retention`), использованные (`consumed_at < now() - retention`) и отменённые (`cancelled_at < now() - retention`).
2. **Recovery codes**: использованные (`used_at < now() - retention`) и отозванные (`revoked_at < now() - retention`).
3. **Pending TOTP**: незавершённые enrollment-сессии TOTP после истечения их срока (`verified_at IS NULL AND setup_expires_at < now()`).

### Параметры конфигурации:
- `AUTH_CHALLENGE_RETENTION_DAYS` (по умолчанию: 30);
- `AUTH_RECOVERY_CODE_RETENTION_DAYS` (по умолчанию: 30);
- `AUTH_CLEANUP_BATCH_SIZE` (по умолчанию: 500).

### Параллельное выполнение и безопасность (Concurrency Safety):
Удаление выполняется порциями с использованием конструкций `WITH candidates AS (SELECT id FROM ... FOR UPDATE SKIP LOCKED LIMIT :batch_size) DELETE FROM ... USING candidates WHERE target.id = candidates.id AND target.<predicate>`. Условие удаления перепроверяется непосредственно в DELETE-выражении целевой таблицы, что исключает удаление строк, ставших активными/подтверждёнными между выборкой и удалением.

### Планирование запуска:
Phase 5.0 включает отдельный `backend-scheduler`: команда `php yii scheduler:run` запускает ограниченные фоновые задачи, включая `auth_cleanup`. Состояние scheduler и email worker проверяется через `/health/ready` и `php yii ops:status`.

## Политика резервного копирования (Backup Policy)

Резервные копии Core и Control Plane создаёт отдельный `backup-worker`; ручной интерфейс — `scripts/backup-db.ps1 -Target Core|Control|All`. Проверка и изолированное восстановление описаны в `docs/operations/BACKUP_RESTORE.md`.

### Формат и восстановление:
- Резервная копия создаётся утилитой `pg_dump` в **CUSTOM binary format** (`-Fc`) с расширением `.dump`.
- Формат `.dump` проверяется `pg_restore --list`, SHA-256 и manifest. Для проверки восстановления используется `scripts/restore-drill.ps1`. Live restore требует явных `-Target`, `-DumpPath`, `-Force`, `-AllowLiveRestore` и для production `-ConfirmProductionRestore`:
  ```powershell
  .\scripts\backup-db.ps1 -Target All
  .\scripts\verify-backup.ps1
  .\scripts\restore-drill.ps1
  ```

### Безопасность и защита данных:
- **Чувствительность артефакта**: файл `.dump` содержит полную копию базы данных, включая учетные записи, хэши паролей (Argon2id), зашифрованные секреты TOTP, хэши резервных кодов, хэши токенов сессий и журнал аудита безопасности. Несмотря на хэширование и шифрование секретов, dump считается критически чувствительным артефактом (`sensitive artifact`) и не подлежит публичной передаче.
- **Git & ZIP exclusion**: директория `backups/` и файлы `*.dump` зафиксированы в `.gitignore` и **никогда не включаются в архивы проекта** (`scripts/create-project-zip.ps1`), а также не коммитятся в систему контроля версий.
- **Production**: хранилище резервных копий должно обеспечивать утверждённое шифрование данных на носителе и контроль доступа. После live restore обязательна сверка privacy erasure до обслуживания пользователей.
