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

## MFA Recovery Assurance Levels

В Platform действуют строгие уровни подтверждения при сбросе MFA (MFA Recovery Assurance Levels):

### 1. Password account
```text
verified recovery email + current password -> MFA reset
```
Для учётной записи с установленным паролем сброс MFA требует подтверждения одноразового токена из письма на верифицированный email и ввода действующего пароля.

### 2. External-only account
```text
verified recovery email -> email recovery token -> linked provider reauthentication -> MFA reset
```
Для аккаунтов без пароля, использующих внешнюю аутентификацию (Google/Telegram OIDC): владение recovery email само по себе **не даёт** права отключить TOTP, если существует доступная привязанная внешняя identity.
После подтверждения recovery email система переходит в контролируемое состояние `external_reauth_required` и требует обязательной повторной аутентификации через одного из привязанных провайдеров (`POST /api/auth/mfa/recovery/external/{provider}/start` и callback).
При этом:
- Reauthentication строго проверяет принадлежность провайдерского `sub`/subject именно восстанавливаемому аккаунту (`auth_external_identities.account_id == recovery_account_id`).
- Попытка использовать чужой Google/Telegram аккаунт или автопривязка по email категорически запрещены и блокируются.
- Recovery reauthentication **не выпускает сессию** и не выдаёт access/refresh токены; это исключительно proof для завершения процедуры сброса MFA.

### 3. No remaining authentication method
```text
manual / additional recovery required
```
Если у аккаунта отсутствует пароль и нет доступных привязанных внешних провайдеров, одного лишь письма недостаточно для тихого сброса MFA. API возвращает контролируемый результат `additional_recovery_required` без утечки деталей аккаунта анонимному клиенту.

## Threat model и секреты

- **Mailbox compromise**: компрометация почтового ящика недостаточна для сброса MFA — требуется второй фактор (текущий пароль для password-аккаунтов либо владение привязанным Google/Telegram аккаунтом для external-only аккаунтов).
- **OAuth account mismatch / Provider identity substitution**: при recovery callback проверяется authoritative provider `sub`, сопоставленный с существующей записью `core.auth_external_identities`. Чужой аккаунт провайдера отклоняется, привязка нового провайдера во время recovery запрещена, перемещение identity невозможно.
- **Automatic email-based account linking**: совпадение email провайдера с локальным recovery email никогда не считается подтверждением identity.
- **MFA bypass via recovery**: сброс MFA немедленно отзывает все активные факторы, аннулирует резервные коды, отменяет все активные challenges, инвалидирует все существующие сессии через ротацию `security_stamp` и отправляет security notification на verified email.
- **Recovery callback replay**: одноразовые токены email challenge, state и authorization code single-use; повторный callback отклоняется.
- **Session issuance boundary**: recovery flow полностью отделён от login flow; provider callback с purpose `mfa_recovery_reauth` никогда не вызывает `SessionIssuer` и не создаёт сессий.
- **Stale auth artifact accumulation**: периодический cleanup удаляет устаревшие challenges, неактуальные recovery codes и просроченные unverified TOTP setup без удаления активных данных.
- **TLS и транспорт**: TLS защищает transport, но не скрывает payload от владельца браузера и DevTools; custom JSON encryption поверх HTTPS запрещено.
- **OAuth state, nonce, PKCE**: OAuth state защищает browser flow от CSRF, nonce — от replay ID token, PKCE — от перехвата code.
- **Provider URLs и issuer**: не поступают из request, что закрывает SSRF/discovery substitution.
- **Audit/log/API не получают secrets**: пароли, raw tokens, TOTP secrets, recovery-code hashes, ID tokens, access tokens и client secrets не логируются и не передаются в Audit metadata.
- Rate limiting: fail-closed лимиты разделены для external login, recovery start, callback, setup confirm, passkey ceremonies и IP.

## WebAuthn Passkeys Runtime (Level 3)

В Platform реализован полноценный runtime ключей доступа (Passkeys) по стандарту **W3C WebAuthn Level 3**:

### 1. Архитектура и библиотека
- Интеграция выполнена на базе библиотеки `web-auth/webauthn-lib:5.3.9` напрямую в Yii3 без использования Symfony-бандлов.
- Сущности хранятся в существующей таблице `core.auth_passkeys` (дополненной в миграции `014_passkey_runtime.sql` полями `transports`, `backup_eligible`, `backup_state`), общее число таблиц Core строго сохранено равным 23.

### 2. Discoverable Credentials и User Handle Privacy
- Аутентификация построена на discoverable credentials (`residentKey: required`), позволяющих пользователю входить без предварительного ввода логина или email.
- `userHandle` вычисляется детерминированно как `HMAC-SHA256(account.id, identifier_hmac_key)` (первые 32 байта). Это полностью исключает утечку реальных UUID аккаунтов, email или PII на сторону аутентификаторов или в клиентский контекст.

### 3. Relying Party и Origin Policy
- Relying Party ID (`AUTH_WEBAUTHN_RP_ID`) и список разрешённых origins (`AUTH_WEBAUTHN_ORIGINS`) строго валидируются на backend. Cross-origin запросы и несоответствия RP ID немедленно отклоняются.

### 4. Политика счетчика (Counter Policy): Synced vs Hardware Passkeys
- **Synced Passkeys** (синхронизируемые связки ключей: Apple iCloud Keychain, Google Password Manager, 1Password и др.): для ключей с флагом `backupEligible = true` значение счетчика подписи может оставаться равным 0 (согласно стандарту WebAuthn Level 3). Это штатное поведение синхронизируемых ключей доступа.
- **Hardware Security Keys** (аппаратные FIDO2-токены, например YubiKey, `backupEligible = false`): счетчик подписи строго проверяется на возрастание (`counter > last_sign_count`). Попытка повторного использования счетчика или его уменьшение фиксируется в аудите как `SecurityAction::PASSKEY_COUNTER_ANOMALY` и блокирует аутентификацию.

### 5. Требования присутствия и верификации (UP / UV)
- User Presence (UP) является обязательным требованием.
- User Verification (UV: биометрия / PIN) строго требуется (`userVerification: required`) как на этапе регистрации, так и при каждой аутентификации. Downgrade до `preferred` сервером категорически блокируется — сервер всегда валидирует ответ в строгом режиме `required`. Ответы без установленного флага UV отклоняются.

### 6. Граница аутентификации и MFA
- Passkey доказывает **первый фактор** аутентификации.
- Если для аккаунта настроен и подтверждён второй фактор (TOTP MFA), аутентификация по Passkey **не обходит MFA**. API возвращает промежуточный статус `status: mfa_required` и challenge token, требуя подтверждения 6-значного кода TOTP либо резервного кода. Сессия создаётся только после прохождения второго фактора.

### 7. Инвариант последнего метода входа (Last Method Invariant)
- Passkeys включены в централизованную политику `AccountAuthenticationMethodsPolicy` и сервис взаимной блокировки `CredentialMutationGuard` наравне с паролями и внешними identity.
- Удаление ключа доступа (`DELETE /api/auth/me/passkeys/{id}`) отклоняется с кодом `409 Conflict` (`auth.identity.last_method`), если после удаления у аккаунта не останется ни одного действующего способа входа (пароля, привязанного Google/Telegram или другого активного passkey).

### 8. Транзакционные уведомления (Transactional Security Notifications)
- При успешной регистрации ключа доступа и при его отзыве генерируются почтовые уведомления:
  - `auth.passkey.registered`
  - `auth.passkey.revoked`
- Шаблоны подготовлены на двух языках (`ru-RU` и `en-US`), содержат валидные HTML-метатеги (`viewport`, `charset`) и plain-text альтернативы, и отправляются через транзакционный `core.outbox_events` на верифицированный email аккаунта.

### 9. Аудит и защита от утечки секретов
- Все операции протоколируются в `core.security_audit_logs`:
  `PASSKEY_REGISTRATION_STARTED`, `PASSKEY_REGISTRATION_COMPLETED`, `PASSKEY_REGISTRATION_FAILED`,
  `PASSKEY_AUTHENTICATION_SUCCEEDED`, `PASSKEY_AUTHENTICATION_FAILED`,
  `PASSKEY_COUNTER_ANOMALY`, `PASSKEY_RENAMED`, `PASSKEY_REVOKED`.
- В аудит категорически **не попадают**: сырые учетные данные (raw credentials), открытые ключи, подписи, clientDataJSON, challenge tokens или приватные материалы.

### 10. Rate Limiting
- Все эндпоинты защищены fail-closed rate-лимитами в Redis:
  - `passkeyAuthStartLimit`: лимит инициализации аутентификации по IP;
  - `passkeyAuthCompleteLimit`: лимит завершения аутентификации по IP;
  - `passkeyRegisterCompleteLimit`: лимит завершения регистрации по IP и по аккаунту.

### 11. Concurrency Safety, CAS Gates и CredentialMutationGuard
- **Atomic Challenge CAS Gate**: потребление challenge выполняется первым шагом внутри транзакции через атомарный CAS (`UPDATE core.auth_challenges SET consumed_at = now() WHERE id = :id AND consumed_at IS NULL`). При параллельных запросах с одним challenge ровно один поток получает true (1 измененная строка); все остальные запросы немедленно прерываются с отказом.
- **Session & Client Binding**: challenge на регистрацию привязывается к сессии (`sessionId`) и к клиентскому контексту (`clientBinding: hash(clientInstanceId + clientType + userAgent)`). Попытка завершить регистрацию в чужой сессии или с другого клиента блокируется.
- **Counter Compare-and-Set**: для аппаратных токенов (non-synced) обновление счетчика выполняется через `WHERE sign_count = :expected_counter`. При гонке между assertion-запросами устаревший запрос не может перезаписать счетчик и отклоняется.
- **CredentialMutationGuard**: отзыв passkey (`DELETE /api/auth/me/passkeys/{id}`) и отключение внешних identity (`DELETE /api/auth/me/identities/{provider}`) защищены транзакционной блокировкой строки аккаунта (`SELECT id FROM core.accounts WHERE id = :id FOR UPDATE NOWAIT`) с повторным подсчетом оставшихся методов входа. Это исключает race condition (TOCTOU), когда параллельные запросы могли бы одновременно удалить последние методы входа и заблокировать аккаунт.
- **Payload Size Limits**: размер JSON-ответов WebAuthn строго ограничен 32 KiB на уровне API-экшенов и сервиса, предотвращая DoS через парсинг сверхбольших структур.
- **Transport Allowlist Normalization**: список транспортов нормализуется по строгому allowlist (`internal`, `usb`, `nfc`, `ble`, `hybrid`, `smart-card`), отсекая неподдерживаемые или вредоносные значения.

## API

- `POST /api/auth/login`, `POST /api/auth/mfa/verify`;
- `GET /api/auth/providers`, `POST /api/auth/external/{provider}/start`, callback;
- `GET /api/auth/me/identities`, link start/callback, unlink;
- `GET /api/auth/mfa`, TOTP setup/confirm/disable, recovery-code regenerate;
- `POST /api/auth/mfa/recovery/request`, `POST /api/auth/mfa/recovery/confirm`;
- `POST /api/auth/mfa/recovery/external/{provider}/start`, `GET /api/auth/mfa/recovery/external/{provider}/callback`;
- `POST /api/auth/passkeys/authentication/start`, `POST /api/auth/passkeys/authentication/complete`;
- `POST /api/auth/passkeys/registration/start`, `POST /api/auth/passkeys/registration/complete`;
- `GET /api/auth/me/passkeys`, `PATCH /api/auth/me/passkeys/{id}`, `DELETE /api/auth/me/passkeys/{id}`.

Все self-service endpoints берут Account только из `AuthenticatedIdentity` и не требуют admin RBAC.
