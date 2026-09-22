# Communication в Core

## Назначение и границы

`Core/Communication` — основа доставки транзакционных сообщений. Она отвечает за
формирование сообщений, правила доставки, адаптеры транспорта и обработку событий
Communication из транзакционного Outbox. Она не владеет сценариями аккаунтов,
аутентификации, персон и других доменов.

The dependency direction is:

```text
Domain/application module
  -> Outbox event
  -> Communication application service
  -> transport port
  -> SMTP adapter
  -> SMTP provider
```

Доменные модули публикуют событие в одной транзакции PostgreSQL со своим бизнес-изменением.
Они не вызывают SMTP и не зависят от Symfony Mailer.

## Обязанности

Слой Communication:

- validates the versioned email event payload;
- maps a supported event type to a stable template code;
- renders locale-aware file templates;
- constructs a transport-neutral `EmailMessage`;
- sends through `EmailSenderInterface`;
- updates the existing Outbox lifecycle and applies retry policy;
- exposes an application service for queueing generic email requests.

Он не хранит вторую копию письма, не предоставляет публичный HTTP API и не реализует сценарии аутентификации.

## Контракт событий

Supported event names are:

| Тип события | Код шаблона | Источник |
| --- | --- | --- |
| `communication.email.requested` | `payload.templateCode` | any internal module |
| `auth.password.reset.requested` | `auth.password.reset` | Auth |
| `auth.email.verification.requested` | `auth.email.verification` | Auth |
| `auth.account.created` | `auth.account.created` | Auth |

Payload version 1:

```json
{
  "schemaVersion": 1,
  "recipient": {
    "address": "user@example.test",
    "name": "User"
  },
  "locale": "ru-RU",
  "templateCode": "auth.account.created",
  "variables": {
    "displayName": "User",
    "actionUrl": "https://example.test/action"
  }
}
```

`templateCode` is required only for `communication.email.requested`; named Auth
events use the mapping above. Canonical locales are `ru-RU` and `en-US`; legacy
`ru` and `en` values are normalized for compatibility. Producers may put
a short-lived one-time action URL in `variables`, but must never publish a
password, password hash, TOTP secret, private key, or SMTP credential. Payloads
and rendered bodies must not be logged.

## Жизненный цикл письма

1. A producer inserts the business change and Outbox event in one transaction.
2. `EmailOutboxProcessor` atomically claims only supported email event types
   with a worker lease.
3. The processor validates the payload and renders subject, HTML, and text from
   `resources/email/<locale>/`.
4. The SMTP adapter sends the message with a deterministic Message-ID derived
   from the Outbox `event_id`.
5. Success changes the Outbox row to `published`. A transport failure changes it
   to `failed` and schedules a later `available_at`. Invalid permanent input is
   moved directly to `dead`.

Published and dead rows are never claimed. A lease prevents concurrent workers
from sending the same row. An expired `processing` lease is reclaimable so a
crashed worker cannot lose an email.

## Семантика доставки и идемпотентности

The processor provides at-least-once delivery. PostgreSQL claim/update
operations prevent duplicates during normal execution, and a deterministic
Message-ID gives downstream systems a stable deduplication key. SMTP has no
transactional acknowledgement shared with PostgreSQL, so a process crash after
the SMTP server accepts a message but before `published` is committed can cause
redelivery. Exactly-once delivery is impossible over generic SMTP without an
idempotent provider API.

This trade-off prefers a possible duplicate in the narrow crash window over a
silently lost security email. Domain handlers should not enqueue multiple
events for the same business transition.

## Политика повторных попыток

- transport failures use exponential backoff from a configurable base delay;
- the attempt count is maintained by `core.outbox_events`;
- after the configured maximum attempts, the event becomes `dead`;
- payload, template, or address validation errors are permanent and become
  `dead` immediately;
- worker leases are configurable and expired leases may be reclaimed.

Retry values come from environment-backed application parameters. Error text is
bounded before it is stored and must not include message bodies or credentials.

## Шаблоны и локализация

Templates are files, not PHP strings, and use the application-wide canonical
locale contract:

```text
resources/email/<locale>/<template-code>.subject.txt
resources/email/<locale>/<template-code>.html
resources/email/<locale>/<template-code>.txt
```

Locale resolution normalizes legacy tags, attempts the canonical locale and
language-compatible supported locale, then uses the system default. Placeholders
use `{{ variableName }}` syntax. The renderer fails when a required
variable is absent or a value is not scalar. HTML values are escaped; subject
and text values are inserted as plain text. Templates are selected explicitly
by locale and fall back to the configured default locale only when the requested
locale file set does not exist.

## Решение по хранению данных

Communication requires no dedicated migration and no `notification_deliveries`
table. Migration `010` belongs to international preferences and session
security, not message delivery. The existing `core.outbox_events` row contains the event
identity, state, attempts, availability, lease, publication time, and last
error. A separate delivery table would duplicate that lifecycle without making
SMTP exactly-once. Such a table should be introduced only for a concrete
product requirement such as user-visible delivery history, per-channel fan-out,
or provider webhook reconciliation.

## Конфигурация

SMTP host, port, username, password, encryption, sender address, and sender name
come from environment variables through application params and DI. Source code
contains no production credentials. Local Compose routes backend SMTP traffic
to Mailpit and exposes the Mailpit UI on `http://localhost:8025`.
