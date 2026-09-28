# Core Consent

`core.account_consents` (migration 018) stores authenticated, durable evidence
for a Yii Core Account. It is not a cookie-tracking ledger and has no FK into
the separate Commercial database. A partial unique index permits one active
grant per `(account_id, consent_code)`; withdrawal preserves the original row,
and a later re-grant inserts a new row. PostgreSQL rejects evidence mutation
and deletion.

`ConsentDefinitionRegistry` is the source of truth for document version,
SHA-256 and withdrawability. The client cannot supply those fields. The
authenticated self API is `GET /api/auth/me/consents`,
`POST /api/auth/me/consents/{code}` and `DELETE /api/auth/me/consents/{code}`.
Only definitions explicitly configured through `consentDefinitions` in DI are
available; this repository does not invent a production legal document.
