# Аутентификация, внешние identity и MFA

## Единая граница создания сессии

Password, Google и Telegram только доказывают первый фактор. Они не имеют права выпускать Platform
tokens напрямую. После первого фактора `AuthenticationFlowService` проверяет account policy и наличие
активного verified TOTP. Единственный `SessionIssuer` создаёт `auth_sessions` и выдаёт opaque token pair.

```text
без MFA: FIRST_FACTOR -> ACCOUNT_POLICY -> SESSION_CREATED
с MFA:   FIRST_FACTOR -> ACCOUNT_POLICY -> MFA_REQUIRED -> MFA_VERIFIED -> SESSION_CREATED
recovery: FIRST_FACTOR -> MFA_REQUIRED -> RECOVERY_CODE_VERIFIED -> SESSION_CREATED
```

При активном TOTP первый фактор возвращает `status=mfa_required` и одноразовый challenge, но никогда
не возвращает access/refresh token. Challenge связан с account, first-factor method, назначением,
клиентским контекстом и сроком действия. Сессия после второго фактора содержит `mfa_verified_at`, а
`authentication_method` использует контролируемые значения вроде `password+totp`.

## Google и Telegram OIDC

Публичный `GET /api/auth/providers` показывает только code, display name и доступность login.
Google и современный Telegram Login используют Authorization Code flow с PKCE S256. Provider endpoints
зафиксированы в server-side configuration и всегда используют HTTPS; URL от клиента не принимаются.
HTTP client запрещает redirects, задаёт connect/total timeouts и тем самым не создаёт SSRF surface.
Google аутентифицирует token request через `client_secret_post`, а Telegram — через требуемый им
`client_secret_basic`; client secret передаётся только server-to-server и не попадает в browser payload.

Для каждого flow backend генерирует криптографические `state`, `nonce` и PKCE verifier. В БД хранится
только hash state; nonce/verifier находятся в XChaCha20-Poly1305 sealed payload. Callback проверяет
single-use state, purpose, provider, account/session binding для link, client binding, signature ID token,
`iss`, `aud`/`azp`, `exp`, разумный `iat`, `nonce` и обязательный `sub`. JWKS загружается только с
фиксированного HTTPS endpoint и кешируется в Redis согласно `Cache-Control: max-age` в безопасных пределах.

Google `sub` и Telegram subject — единственные внешние identity keys. Email является только snapshot:
совпадение provider email с локальным email никогда не создаёт автоматическую привязку и не создаёт
новый Account. Регистрация через social login выключена текущей DB policy (`allow_registration=false`).

## Explicit link и unlink

`/api/auth/me/identities/*` работает только с текущим authenticated Account. Link имеет отдельный
challenge purpose `external_link`, связан с session/account и требует recent authentication. Уникальные
индексы БД и атомарный insert не позволяют одному subject принадлежать двум accounts или создать две
identity одного provider у Account. Сохраняется только allowlisted profile snapshot; authorization code,
access/refresh/id tokens, raw response и client secret не сохраняются.

Unlink требует recent authentication и отклоняется, если после удаления не останется password,
другой external identity или активный passkey. Link/unlink записываются в Audit и создают email security
notification без provider secrets.

## TOTP и recovery codes

TOTP соответствует RFC 6238: SHA-1, 6 цифр, период 30 секунд, окно ±1 time-step. Setup создаёт pending
factor, возвращает Base32 secret и `otpauth://` URI ровно на этапе enrollment. До успешного confirm factor
не считается активным. Secret хранится только как libsodium XChaCha20-Poly1305 ciphertext; 32-byte key
поступает из `AUTH_TOTP_ENCRYPTION_KEY`, версионируется и не хранится в БД.

Partial unique index разрешает только один active verified TOTP на Account. `last_used_counter` обновляется
атомарно только при большем counter, поэтому один код нельзя повторно принять в том же time-step.

При confirm создаются 10 криптографически случайных recovery codes. Plaintext показывается один раз;
PostgreSQL получает только SHA-256 hashes. Consume — один атомарный `UPDATE ... WHERE used_at IS NULL AND
revoked_at IS NULL`. Regeneration требует recent MFA + новый TOTP, аннулирует старые коды и показывает
новый набор один раз.

Обычное отключение TOTP требует recent MFA session, действующий TOTP и текущий password, если password
credential существует. Factor и recovery codes отзываются, security stamp меняется, остальные sessions
закрываются, а текущая явно reauthenticated session сохраняется с новым stamp.

## Email MFA recovery

Recovery request всегда отвечает одинаково. Каналом может быть только `auth_identifiers` типа email с
`verified_at IS NOT NULL` и `can_recover=true`; provider email snapshot не подходит. Одноразовый token
имеет короткий TTL и хранится только как hash. Если Account имеет password credential, confirm обязательно
требует текущий password.

Успешный reset атомарно отзывает все TOTP factors и recovery codes, меняет security stamp, закрывает все
sessions, отменяет pending challenges, пишет high-severity Audit и ставит notification в transactional
Outbox. Новый TOTP автоматически не создаётся. Password reset сам по себе MFA не отключает.

Все interactive authentication/MFA письма используют уже resolved locale текущего `ClientContext`.
Background security notification использует сохранённую `accounts.locale`, а при её отсутствии —
`APP_DEFAULT_LOCALE`. Каноническая locale фиксируется в Outbox до commit и не пересчитывается worker-ом.

## Threat model и секреты

- TLS защищает transport, но не скрывает payload от владельца браузера и DevTools; custom JSON encryption
  поверх HTTPS запрещено.
- OAuth state защищает browser flow от CSRF, nonce — от replay ID token, PKCE — от перехвата code.
- Provider URLs и issuer не поступают из request, что закрывает SSRF/discovery substitution.
- Auth API использует `Cache-Control: no-store`; permissive CORS не вводится.
- Audit/log/API не получают password, raw token/challenge, TOTP ciphertext/plaintext, recovery hashes,
  provider tokens или client secrets. Callback query string должен редактироваться/исключаться в access
  logs reverse proxy; приложение не пишет query/body в логи.
- Redis rate limits разделены для external start/callback, MFA challenge/IP, setup confirm, provider link,
  recovery-code попыток и email recovery. Защитные endpoints fail closed при недоступности limiter.

Production требует валидный Base64 32-byte encryption key. `db:check` проверяет конфигурацию Google и
Telegram, если соответствующий provider включён в БД. Для локальной разработки разрешён отдельный
детерминированный dev-only key; он запрещён в production.

## API

- `POST /api/auth/login`, `POST /api/auth/mfa/verify`;
- `GET /api/auth/providers`, `POST /api/auth/external/{provider}/start`, callback;
- `GET /api/auth/me/identities`, link start/callback, unlink;
- `GET /api/auth/mfa`, TOTP setup/confirm/disable, recovery-code regenerate;
- `POST /api/auth/mfa/recovery/request`, `POST /api/auth/mfa/recovery/confirm`.

Все self-service endpoints берут Account только из `AuthenticatedIdentity` и не требуют admin RBAC.
