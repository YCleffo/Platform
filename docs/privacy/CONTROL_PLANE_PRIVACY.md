# Control Plane personal data boundary

`ControlPlane/Privacy` uses the Commercial database directly and has no Yii Core database dependency. A commercial account is an organization/accounting scope, not a data subject. The subject can be a Control User, billing contact person, or another person named in an immutable financial snapshot. Self export is authorized by the Control User ID; it is not an export of every person on that commercial account.

| Store | Personal data | Export / retention boundary |
| --- | --- | --- |
| `control_users` | Email, status and identity times; password hash | Self export includes only safe identity fields. Hash never exported. Identity pseudonymization requires approved policy and no financial linkage. |
| `control_sessions`, refresh history | Token hashes, IP hash, user agent and security times | Export includes only safe session status/times. Bounded cleanup removes expired sessions and cascading refresh history after reviewed period and hold check. |
| Email verifications | Token hash and expiry | Never export hash. Cleanup after expiry plus reviewed period. |
| `commercial_account_memberships` | User/account relationship and role | Export subject's own memberships. Sole active owner blocks erasure until ownership is transferred. |
| `billing_profiles` | Contact name/email, legal entity and tax/address fields | Export contact fields only when the subject email matches; erasure clears matching mutable contact fields, preserving business profile. |
| `invoices`, `receipts` | Immutable billing/fiscal contact snapshots | Export only subject-matching contact reference fields. Financial documents stay intact; no retention duration is invented. |
| Payment/fiscal records | Provider references, amounts, payment and fiscal evidence | Business/financial retention policy required. User erasure never deletes these records. |
| `audit_events` | Actor/resource IDs and bounded allowlisted metadata | Append-only ordinary path; reviewed retention, hold-aware bounded lifecycle cleanup. |
| `outbox_events` | Verification IDs and delivery metadata | New verification events hold IDs only; the recipient is read from the Control User just before send. Migration 021 removes legacy queued recipients and clears published email payloads. Published/dead cleanup is bounded and policy gated. |
| Idempotency/rate limits | Request hash or network-derived hashes | Never self-export hashes. Reviewed periods and bounded cleanup. |

`ControlPersonalDataExporter` combines typed identity, security and commercial contributors and records an export event without storing the export body. `ControlUserErasure` is disabled by deployment configuration until the policy is approved. When enabled, it blocks on active hold or sole ownership, deletes security data, revokes memberships, clears matching mutable billing contacts and disables credentials. If financial records exist for a member account, it preserves the identity link and records `partially_completed`; without them it replaces the email with a stable pseudonym. Pseudonymization is not anonymity: case, audit and other references may remain.

`control:security:cleanup` defaults to dry-run, accepts a category and bounded limit, and prints counts only. `control_privacy_retention_days` must contain reviewed positive periods; unknown categories fail closed. The lifecycle database setting does not by itself provide role isolation. Production must grant the cleanup command to a restricted operator/database role and protect backups and restore-time erasure.

Authenticated self export is available at `GET /api/control/privacy/me/export` with a `no-store` response. Approved erasure can be invoked through `control:privacy:erase` by a privileged operator. It remains blocked until the policy flag and explicit identity/contact retention periods are set. Zero days means an approved event-triggered erasure; positive days require a prior `archived_at` date past the period.

Deployment must separately configure `control_privacy_processing_roles` for its operator or processor-by-instruction purposes, and retain external instruction/contract references. Provider routes, especially YooKassa and SMTP, require an approved recipient/transfer inventory. The concrete SMTP, payment, refund and fiscal receipt gateways check provider purpose, categories, country, evidence reference and foreign notification status before outbound requests. `control:privacy:check` is a read-only fail-closed configuration check for retention, roles, provider decisions, residency and security references. This document is a technical inventory and does not establish a legal retention period or certify compliance.

## Приглашения

`commercial.commercial_account_invitations` содержит email получателя (normalized), роль, отправителя, статус и срок. Токен в открытом виде не хранится. DSAR-экспорт включает приглашения, где пользователь — отправитель, принявший или приглашённый; erasure отзывает pending-приглашения на email пользователя и псевдонимизирует адрес. Retention-категория `invitation` настраивается через `CONTROL_PRIVACY_RETENTION_DAYS`; без значения cleanup и readiness не проходят. SMTP-egress для приглашений требует отдельного утверждённого purpose и категорий; автоматического approval нет.
