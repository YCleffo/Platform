# Безопасность Commercial Control Plane

Commercial использует собственные `control_users`, сессии и членство в коммерческих аккаунтах. Учетная запись Core или право `core.superadmin` не предоставляет доступ к Commercial.

## Credentials и сессии

Пароли хешируются Symfony PasswordHasher. Нормализованный email уникален, представление для пользователя хранится отдельно. Статусы пользователя: `pending_verification`, `active`, `suspended`, `disabled`.

Одноразовые verification/reset artifacts имеют срок действия и сохраняются как хеши. Opaque access tokens короткоживущие; refresh меняется при каждом использовании. Повтор ранее использованного refresh отзывает семейство сессии. Logout идемпотентен. Очистка истёкших артефактов выполняется контролируемой CLI-командой.

Проверка активности пользователя, отзыва и обоих сроков сессии выполняется для каждого
access token request. `last_used_at` — приблизительный индикатор активности: первая
аутентификация записывает его, дальнейшие access-запросы обновляют не чаще раза в минуту.
Refresh по-прежнему пишет время сразу. Это ограничивает конкурирующие записи одной
строки сессии; результат авторизации и MFA evidence не кешируется.

TOTP секрет шифруется отдельным 32-байтовым ключом. Enrollment переходит из pending в active только после проверки кода; повтор TOTP и использованного recovery code отклоняется. Password reset отзывает связанные сессии и security artifacts согласно lifecycle.

Успешный fresh TOTP или recovery code повышает текущую password-сессию до `password_totp` или `password_recovery` и атомарно записывает `mfa_authenticated_at`. Refresh сохраняет это свидетельство, но не создаёт новую проверку MFA.

## Авторизация и чувствительные действия

`AccountAuthorization` проверяет активное членство и permission для каждого account resource. UUID из URL сам по себе не даёт доступ. Owner, admin, billing и technical — роли Commercial. Удаление или понижение владельца блокирует строку аккаунта; deferred constraints запрещают аккаунт без активного владельца.

Чувствительные операции биллинга, возвратов, подписок и сохранённых способов оплаты требуют recent primary authentication и recent MFA. Внешняя компенсация дополнительно требует двух различных, отдельно уполномоченных финансовых операторов.

## HTTP и аудит

Регистрация, вход, resend, refresh и создание аккаунта ограничены expiring rate-limit counters с HMAC-ключами. Тело API ограничено 64 КиБ. Ошибки не возвращают SQL, stack trace, пути файлов или секреты.

API использует bearer credentials, не cookie authentication. CORS закрыт по умолчанию. Чувствительные ответы имеют `no-store, private`; защитные заголовки запрещают framing и sniffing, ограничивают referrer. Аудит содержит correlation ID, identifiers и разрешённые типизированные поля; пароли, raw tokens, Authorization и произвольные снимки сущностей исключены.

Webhook YooKassa не доверяет JSON или forwarded IP как финансовому доказательству. Окончательное состояние подтверждается серверным запросом провайдеру с проверкой локального контракта. Публичный endpoint ограничен per-IP rate limit; в production рекомендуется задать `CONTROL_YOOKASSA_WEBHOOK_IPS` (CIDR-список) и корректные trusted proxies, чтобы отсекать чужие источники до записи inbox-событий и вызовов провайдера.

## Конфигурация и runtime

Production boot требует `CONTROL_APP_SECRET`, `CONTROL_TOKEN_SECRET` и корректный `CONTROL_TOTP_ENCRYPTION_KEY`; checkout также требует действительный HTTPS return URL и merchant configuration. Эти значения не подменяются демонстрационными credentials.

Production image работает как `www-data`, устанавливает зависимости без dev-пакетов и не содержит `.env`, Git metadata или тестов. Запись разрешена в runtime-каталоги. Приложения имеют раздельные DB credentials и сети; HTTP-процесс Commercial не получает Docker socket. Core не получает приватный ключ подписи Commercial.

`/health` — ограниченная проверка доступности приложения. `/health/ready` дополнительно проверяет зависимости и обязательные heartbeat. Успешный HTTP health не подтверждает минимальные привилегии DB role или промышленную отказоустойчивость.

[Секреты и ротация](../operations/ENVIRONMENT.md) · [Инварианты](CORE_INVARIANTS.md) · [Контракт API](COMMERCIAL_CONTROL_PLANE.md)

## Приглашения

- Токен: 256-битный доменно-разделённый HMAC (`i1.<id>.<version>.<mac>`), в БД только SHA-256 hash; версия ротируется при resend, старые ссылки недействительны; срок по умолчанию 7 дней (`CONTROL_INVITATION_TTL_SECONDS`).
- Ссылка: `/invitation#token=…` (fragment не уходит на сервер при обычном запросе). Frontend хранит токен только в памяти, очищает fragment через `history.replaceState`, не пишет в storage/query/analytics/логи; `Referrer-Policy: no-referrer`.
- Preview — без авторизации и без изменения состояния. Accept/decline — auth + подтверждённый email + совпадение нормализованного адреса. Idempotency key на создание; rate limit на create/resend; `requireSensitiveMfa` на create/resend/revoke.
- Egress: SMTP purpose `control_account_invitation` с категориями `contact_email, invitation_link, organization_name`. Без утверждённого решения оператора отправка отклоняется; readiness checker требует это решение.
- P0 RBAC bypass в `MembershipService::add()` закрыт: проверка внутри транзакции после блокировки аккаунта, учёт текущей роли, 409 на смену роли через add.
