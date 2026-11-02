# Email previews and demo catalog

Both commands are for the local Docker stack only. They write test messages to Mailpit at
[http://127.0.0.1:8025](http://127.0.0.1:8025).

## Send every template

```bash
bash scripts/dev/send-email-previews.sh
```

The script clears Mailpit, then sends:

- 14 Core templates in `ru-RU` and `en-US` (28 messages), using the same renderer and SMTP
  sender as the outbox worker;
- 8 Commercial templates (verification, invitation, password reset, password changed,
  MFA enabled/disabled, recovery codes regenerated, security notice).

All HTML messages use the shared branded layout: Platform header, blue accent, card,
readable body typography and localized footer. Text alternatives stay plain.

Override the recipient with `PREVIEW_TO=qa@example.test bash scripts/dev/send-email-previews.sh`.

## Seed demo tariffs

```bash
bash scripts/dev/seed-demo-catalog.sh
```

The seed is idempotent and creates three published RUB plans: Старт, Бизнес and
Корпоративный, with monthly/yearly prices and boolean/integer/counter/gauge features.
The CI browser job uses the same SQL through
`scripts/ci/control-test-seed-catalog.sh` against the disposable
`platform_commercial_test` database only.
