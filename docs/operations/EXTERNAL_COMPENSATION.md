# External plan-change compensation

This path is only for a full refund that was completed outside YooKassa after
an upgrade payment became `paid_unapplied` and automatic compensation entered
`recovery_required`. It never initiates a transfer. Until two independent
attestations complete, the case remains blocked.

## Operator prerequisites

Provision two distinct active Control Plane users as Platform financial
operators under separate reviewed authorization tickets in
`commercial.financial_operators`. Client account owner/admin membership does
not confer this role. Enroll and revoke through a controlled database change;
there is intentionally no self-service grant endpoint. Set `revoked_at` to
revoke a grant; retain its row and authorization reference for audit.

Each operator must have enrolled MFA and a recent primary and MFA-authenticated
session. Both must independently inspect the restricted document and the
external transfer in the bank/provider system. The document must establish
the exact transfer reference, settled payment amount and currency, payee and
completion. Store it in access-controlled, retained storage and record an
immutable `urn:` reference plus SHA-256 digest of the document bytes. The
application validates the two matching attestations and local financial state;
it cannot independently verify the external bank transfer.

## Procedure

1. Inspect `control:plan-changes:recovery-cases` and the invoice/payment/refund
   history. Confirm the transfer has completed and no provider refund is
   pending or succeeded. Do not use this path for a partial refund.
2. The first operator sends `POST /api/control/finance/plan-change-compensations/{changeId}`
   with `externalTransactionReference`, `documentReference`, and
   `documentSha256`. An identical retry returns the same proposal.
3. The second operator reads `GET` at the same path, fetches the document from
   restricted storage, independently checks the actual transfer, then sends
   `POST /api/control/finance/plan-change-compensations/attestations/{id}/approve`
   with the same three fields. A mismatch, self-review, inactive authorization,
   pending provider refund, or altered payment blocks approval. If evidence is
   inadequate, the second operator uses `/reject` with the same fields and a
   `rejectionReason`. The first operator may file a corrected proposal.
4. After approval, verify one approved attestation, two distinct actor IDs,
   the immutable payment amount/currency, `plan_change=refunded`, old access
   unchanged, and no pending or succeeded provider refund. Retain the
   document, authorization tickets and audit events together.

An approved external attestation is its own financial record. It does not
create a synthetic YooKassa refund row or claim that YooKassa returned money.
If provider state later contradicts the external transfer, stop automated
resolution and investigate possible duplicate compensation. Production use
requires reviewed document storage, access controls, retention and alert
delivery; none of these have been exercised in a hosted environment yet.
