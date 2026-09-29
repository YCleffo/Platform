# Retention and erasure boundary

Pass B3 implements purpose-specific retention evaluation, scoped legal holds, and processing termination. Unknown policy, trigger, basis, hold state, or dependency blocks destructive eligibility. A shared physical resource is eligible only when every applicable purpose permits the action. A prior `eligible_delete` decision is evidence of evaluation, not permission for a later delete.

Retention policies declare an explicit `durationUnit`: `calendar_days`, `working_days`, `hours`, or `event_only`. A missing timed duration is an incomplete deployment policy. Core supplies no production retention duration. Contributors supply semantic trigger timestamps.

Pass B4 implements bounded erasure cases and items, handler registration, fresh retention/basis/hold/dependency checks immediately before each physical action, and immutable destruction evidence. Core handlers currently delete owned `person_contacts` and `account_settings` only. Person, auth data, consents, representations, DSAR and immutable evidence have no general destructive handler. Unsupported actions remain blocked. `delete`, `anonymize`, and `pseudonymize` are distinct actions; Core does not claim anonymization by changing a status flag.

The separate destruction evidence policy must be configured before execution. Evidence carries IDs, machine codes, an HMAC resource reference, action/result, execution time, executor, journal event ID, and its own retention date. It contains no deleted value or resource snapshot. Technical export is not a signed organizational act. Case execution is bounded, locked, retryable, and linked to DSAR execution evidence and deadline satisfaction. Partial results remain explicit.

Processing blocks restrict processing; legal holds independently prevent destruction. A subject may have both. Account disable, Person archive, and consent withdrawal do not erase data. Authentication housekeeping under `auth/cleanup` remains separate.

Backup expiry and restoration controls are a deployment responsibility: a primary-data deletion does not immediately remove immutable backup copies. A restore must suppress use of data erased after its backup was taken and reapply those erasures before serving restored data. See [ERASURE.md](ERASURE.md).

Status: retention evaluation **IMPLEMENTED**; Legal Hold **IMPLEMENTED**; erasure orchestration **IMPLEMENTED**; physical Core erasure **IMPLEMENTED where a handler exists**; destruction evidence **IMPLEMENTED**. Audit/Outbox/Inbox cleanup **NOT IMPLEMENTED**.
