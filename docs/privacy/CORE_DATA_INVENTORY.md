# Core personal data inventory (Phase 4.1 review)

This inventory started from the 25-table baseline through migration 018 and includes migrations 019-027. It is a technical classification, not a deployment's legal basis or retention schedule. Deployment policy must assign the actual purposes, lawful bases, retention periods, export rules, and processors before production use. `Policy` below means no duration is currently established in Core.

## Core production lifecycle coverage

| Resource | Export | Retention | Lawful basis | Restriction | Erasure | Production contributor |
| --- | --- | --- | --- | --- | --- | --- |
| Person | Yes | Configured anonymize policy and trigger | Consent runtime; other basis unknown | Archived identity cannot be edited or receive contacts | Irreversible neutral tombstone after contacts, memberships and human Account | Core root adapter and handler |
| PersonContacts | Yes | Configured trigger | Consent runtime; other basis unknown | Contact flows | Delete, policy gated | Core resource adapter |
| Human Account | Yes | Configured anonymize policy and trigger | Consent runtime; other basis unknown | Archived account cannot authenticate or reactivate | Auth cleanup, RBAC revocation and irreversible tombstone after settings | Core root adapter and handler |
| AccountSettings | Yes | Configured trigger | Consent runtime; other basis unknown | Settings service | Delete, policy gated | Core resource adapter |
| OrganizationMembership | Yes | Configured trigger; active relation blocks delete | Consent runtime; other basis unknown | Organization flows | Delete after relation ends | Core resource adapter |
| Auth/security metadata | Restricted export, no secrets | Existing Auth cleanup | Auth lifecycle | Auth boundary | No general privacy handler | Existing Auth cleanup |
| Consent | Yes | Deployment policy | Consent record | Consent runtime | Unsupported | Core export |
| LegalRepresentation | Yes | Deployment policy | Authority record | Authority boundary | Unsupported | Core export |
| DSAR/privacy execution | Yes | Existing cleanup policy | Request evidence | Request state | Unsupported | Core export/cleanup |

| Table and significant fields | Data category and PII | Processing purpose | Retention owner | Audit / logs / export | Special handling |
| --- | --- | --- | --- | --- | --- |
| `persons`: name, display name, birth date | Direct identity; birth date can identify a minor | Person identity and lifecycle | Person/privacy policy | Audit: IDs and status only; logs: no values; export: subject access policy | Birth date and minor status require restricted access; archive does not erase data |
| `person_contacts`: value, normalized value, label, verified time | Direct contact identifiers | Contact and verification | Person/privacy policy | Audit: type/status only; logs: no values; export: verified subject data | Restrict reads; normalize and avoid duplicate copies |
| `accounts`: person link, label, status, locale, timezone, timestamps | Identity link, preferences, indirect identifiers | Account operation and security | Account/privacy policy | Audit: account ID and status; logs: IDs only; export: subject data where applicable | Human account is distinct from Person; service accounts may have no subject |
| `auth_identifiers`: value, normalized value | Direct login/contact identifiers | Authentication and recovery | Auth lifecycle policy | Audit: identifier type only; logs: no values; export: restricted | Recovery/login identifiers must not enter audit payloads |
| `auth_password_credentials`: hash, algorithm, change times | Authentication secret-derived data | Password authentication | Auth lifecycle policy | Audit: action/status only; logs: never; export: never | Hash is sensitive even though it is not a plaintext password |
| `auth_providers`: provider code and display name | Usually no subject PII | Provider configuration | Auth configuration | Audit: code/status; logs: code; export: generally no | No provider credentials in DB |
| `auth_external_identities`: provider subject, email, display name, avatar URL, claims | Direct and indirect identifiers | Federated authentication | Auth/privacy policy | Audit: provider and event only; logs: no profile; export: subject data by policy | Claims require a strict allowlist and bounded values; do not retain raw ID tokens |
| `auth_passkeys`: credential ID, public key, AAGUID, label, use times | Authentication and device-linked identifiers | Passkey authentication | Auth lifecycle policy | Audit: action and credential ID; logs: no key/material; export: restricted | Public key and credential are not a server-side biometric template |
| `auth_totp_factors`: encrypted secret, label, use times | Authentication secret and security history | MFA | Auth lifecycle policy | Audit: action only; logs/export: never | Secret encryption key must remain outside DB |
| `auth_recovery_codes`: code hash and use times | Authentication secret-derived data | MFA recovery | Auth lifecycle policy | Audit: count/action only; logs/export: never | Single use; existing cleanup applies to used/revoked codes |
| `auth_sessions`: token hashes, network/device/geo fields, timestamps | Security, device, network and approximate location PII | Session management and abuse detection | Auth/privacy policy | Audit: session ID and bounded security signals; logs: no tokens; export: restricted | Existing IP and geo fields need a policy-defined retention period |
| `auth_challenges`: secret hash, normalized target, payload, expiry | Authentication, contact and potentially sensitive payload | One-time authentication and recovery | Existing auth cleanup policy | Audit: action only; logs/export: never | Existing cleanup deletes expired, consumed and cancelled challenges |
| `roles`, `permissions`, `role_permissions` | Generally no subject PII; grantor account IDs may identify staff | Authorization | RBAC policy | Audit: role/permission codes; logs: IDs only; export: subject assignment context | Avoid embedding personal descriptions |
| `account_roles`: account IDs, assigner, validity | Subject/staff relationship and security evidence | Authorization history | RBAC/privacy policy | Audit: IDs and role code; logs: IDs only; export: subject context by policy | Changes must remain attributable |
| `modules`: names, descriptions, version | Usually no subject PII | Module configuration | Module owner | Audit/logs: codes; export: generally no | Module data itself is outside Core |
| `module_settings`, `system_settings`, `feature_flags`: JSON values and descriptions | May contain indirect identifiers if misused | Platform configuration | Settings owner | Audit: allowlisted safe values only; logs: no secrets; export: only if subject data | Existing settings policy forbids secrets; no legal consent in AccountSettings |
| `account_settings`: JSON preferences, including presence visibility | Preferences and possible indirect identifiers | User interface and notification choices | AccountSettings/privacy policy | Audit: changed safe paths; logs: no values; export: subject data | This is not a legal evidence store |
| `privacy_consents`: subject Person, actor Person/Account, authority, purpose, document hash/version, locale, timestamps, request ID | Legal evidence and subject/actor identifiers | Purpose-bound consent history | Consent/privacy policy | Audit: event/IDs only; logs: no document or contact values; export: restricted evidence | Migration 018 rows are preserved as unclassified legacy evidence; current grants require a human actor and purpose |
| `legal_representations`: subject and representative IDs, authority state, evidence reference/hash, verifier, validity | Legal authority evidence | Verified action on behalf of another Person | Representation/privacy policy | Audit: event/ID only; logs: no evidence reference; export: restricted evidence | No raw scans in Core; revoked authority cannot be reactivated |
| `legal_representation_scopes`: authority ID and action code | Indirect subject/representative authority evidence | Restrict actions performed for another Person | Representation/privacy policy | Audit: authority ID and event only; logs: no evidence content; export: restricted authority evidence | Scope set is frozen after verification; unscoped legacy records authorize no action |
| `audit_events`: actor ID, resource ID, IP, user agent, typed JSON payloads | Legal/security evidence and network/device PII | Accountability and security | Audit/privacy policy | Audit: itself; logs: IDs only; export: restricted review | Unknown action payload is denied; operational retention remains for Pass B5 |
| `data_subject_requests`: subject, submitting Person/Account, authority ID, request type and state | Subject and actor identifiers; privacy case history | Data subject request intake and workflow | DSAR/privacy policy | Audit: IDs and machine codes; logs: no request body; export: restricted case evidence | No free-text request content; unsupported operations cannot be marked complete |
| `data_subject_request_deadlines`: trigger, original due time, extension, satisfaction | Indirect subject case timing | Statutory deadline tracking | DSAR/privacy policy | Audit: deadline/reason codes; logs: no subject details; export: restricted case evidence | Original due time is immutable; calendar coverage must be verified |
| `data_subject_request_events`: status changes, actor, reason codes and time | Indirect subject and staff activity evidence | Workflow accountability | DSAR/privacy policy | Audit: machine codes; logs: IDs only; export: restricted case evidence | Ordinary update/delete is blocked; no comments or documents |
| `data_subject_request_executions`: request, actor, capability/result codes, digest, size, changed paths and time | Indirect subject execution evidence | Prove response issuance or correction | DSAR/privacy policy | Audit: IDs and codes; logs: IDs/codes only; export: no raw execution table dump | Terminal evidence immutable; never stores export body or correction before/after values |
| `privacy_processing_blocks`: subject, scope, reason, source request and release | Restriction and case identifiers | Scoped processing restriction | DSAR/privacy policy | Audit: scope/reason codes; logs: IDs only; export: restricted case evidence | Active state considers release and expiry; not an Account disable or legal hold |
| `privacy_legal_holds`: subject, scope, reason/source codes and references, actor, expiry and release | Scoped legal hold evidence | Prevent destructive eligibility while active | Deployment legal hold policy | Audit: IDs/codes only; logs: IDs only; export: restricted case evidence | Source reference is bounded and machine-readable; no document body or PII snapshot |
| `privacy_retention_decisions`: subject/resource IDs, purpose/policy/trigger/decision codes and times | Indirect subject retention evidence | Record non-destructive evaluation | Deployment retention policy | Audit: IDs/codes only; logs: counts only; export: restricted case evidence | Append-only eligibility evidence; no resource body, physical deletion, or PII snapshot |
| `outbox_events`: aggregate ID, payload, headers, error | Potential subject PII in event content | Transactional delivery | Messaging/privacy policy | Audit: event ID/status; logs: no payload; export: case-specific | Payload minimization and lifecycle review required |
| `inbox_events`: event ID, consumer, error, times | Pseudonymous operational identifiers; error may leak PII | Idempotent delivery | Messaging/privacy policy | Audit: event ID/status; logs: sanitized; export: usually no | Bound and sanitize error text |

The classification also covers network and device values generated in `ClientContext` and ephemeral Redis presence. Presence uses a TTL and has no durable SQL heartbeat history. External email delivery and backup copies require separate deployment processor and retention records. No current Core table stores biometric templates or special-category health data; future modules adding either require a separate design review.

Until deployment policy assigns a duration, retention is **policy-required / deployment-defined**. This document does not authorize deletion or publication of any category.

## B5 records (migrations 026-027)

`privacy_incidents`, category/scope rows and incident events store safe incident codes, affected subject references and 24/72-hour workflow evidence. `privacy_distribution_consents` stores dedicated public-distribution permissions, field/category codes and document digest. `privacy_cleanup_runs` stores category, policy period and deletion count only. These records require their own reviewed retention and authorized access. Outbox email payloads are cleared on successful delivery; terminal audit/outbox/inbox and DSAR history are eligible for policy-gated, hold-aware bounded cleanup. The separate Commercial inventory is in [CONTROL_PLANE_PRIVACY.md](CONTROL_PLANE_PRIVACY.md).

## B4 erasure records (migration 025)

| Table | Personal data and purpose | Retention and handling |
| --- | --- | --- |
| `privacy_erasure_cases` | Subject and source DSAR IDs; erasure workflow state | Case policy is deployment-defined; no resource value is stored. |
| `privacy_erasure_items` | Resource type and UUID, policy/action/status codes | Indirect identifiers remain after physical deletion; restricted case evidence. |
| `privacy_erasure_item_purposes` | Purpose, trigger, policy and contributor codes | Explains multi-purpose decisions without a resource snapshot. |
| `privacy_destruction_evidence` | HMAC reference, executor ID, journal event ID and action/result | Separate evidence policy and `evidence_retain_until`; immutable ordinary path; no old value. |

Core B4 deletes only owned PersonContact and AccountSettings records when all purpose evaluations allow it. Person, auth, consent, representation, DSAR, audit, outbox and inbox erasure remain unsupported. A technical evidence export is not a signed organizational act. Backups need expiry and restore-time re-erasure controls.

## B2 export allowlists

The structured export includes subject Person names, birth date, status and times; own contacts' type, value, label, primary and verification state; human Account status/type/times; stored settings; consent codes, document version, locale, source, times and actor relation; subject representation status, scopes and times; request status/deadlines; and processing block scope, reason and times. Auth includes identifier type/value/verification and limited external identity, MFA, passkey and session metadata.

Normalized contact identifiers, password hashes, TOTP secrets, recovery hashes, passkey credential/public key, session token hashes, external claims, audit rows and outbox payloads are deliberately excluded. The JSON response is never stored in execution evidence. Migration 023 brings the Core schema to 32 tables.
