-- ============================================================================
-- 008 — Database comments visible directly in DBeaver
-- These comments are intentionally verbose for the first development phase.
-- ============================================================================

COMMENT ON TABLE core.persons IS 'Physical people. Person is not an Account and may exist without an Account.';
COMMENT ON COLUMN core.persons.id IS 'UUIDv7 primary key of the physical person.';
COMMENT ON COLUMN core.persons.status IS 'Person lifecycle status: active, inactive, archived.';
COMMENT ON COLUMN core.persons.given_name IS 'First/given name.';
COMMENT ON COLUMN core.persons.family_name IS 'Family/last name.';
COMMENT ON COLUMN core.persons.middle_name IS 'Optional middle/patronymic name.';
COMMENT ON COLUMN core.persons.display_name IS 'Optional preformatted display name for UI.';
COMMENT ON COLUMN core.persons.birth_date IS 'Birth date without time.';
COMMENT ON COLUMN core.persons.created_at IS 'Creation timestamp.';
COMMENT ON COLUMN core.persons.updated_at IS 'Last update timestamp; application-managed for now.';
COMMENT ON COLUMN core.persons.archived_at IS 'Archive timestamp; NULL means not archived.';

COMMENT ON TABLE core.person_contacts IS 'Contacts of a physical Person. These are not Account login identifiers.';
COMMENT ON COLUMN core.person_contacts.person_id IS 'Child -> parent: person_contacts.person_id -> persons.id.';
COMMENT ON COLUMN core.person_contacts.contact_type IS 'Contact type: email or phone.';
COMMENT ON COLUMN core.person_contacts.value IS 'Original/display value.';
COMMENT ON COLUMN core.person_contacts.normalized_value IS 'Normalized value used for comparison/search.';
COMMENT ON COLUMN core.person_contacts.is_primary IS 'Marks the primary contact of a given type for the Person.';
COMMENT ON COLUMN core.person_contacts.verified_at IS 'Verification time; NULL means unverified.';

COMMENT ON TABLE core.accounts IS 'System identities. Human Accounts link to Person; service/system Accounts may not.';
COMMENT ON COLUMN core.accounts.person_id IS 'Account -> Person. Unique, therefore one Person has at most one Account in Core v1.';
COMMENT ON COLUMN core.accounts.account_type IS 'human, service or system.';
COMMENT ON COLUMN core.accounts.status IS 'Account lifecycle/status.';
COMMENT ON COLUMN core.accounts.security_stamp IS 'Random security version. Rotate to invalidate old sessions.';
COMMENT ON COLUMN core.accounts.created_by_account_id IS 'Self-reference: Account that created this Account.';
COMMENT ON COLUMN core.accounts.approved_by_account_id IS 'Self-reference: Account that approved this Account.';

COMMENT ON TABLE core.auth_identifiers IS 'Login/recovery identifiers of Account: username, email or phone.';
COMMENT ON COLUMN core.auth_identifiers.account_id IS 'auth_identifiers -> accounts.';
COMMENT ON COLUMN core.auth_identifiers.normalized_value IS 'Canonical normalized form used in unique lookup.';
COMMENT ON COLUMN core.auth_identifiers.can_login IS 'Whether this identifier can be used for login.';
COMMENT ON COLUMN core.auth_identifiers.can_recover IS 'Whether recovery policy may use this identifier.';

COMMENT ON TABLE core.auth_password_credentials IS 'Optional password credential of an Account. Zero or one row per Account.';
COMMENT ON COLUMN core.auth_password_credentials.password_hash IS 'Password hash only. Never store raw passwords.';
COMMENT ON COLUMN core.auth_password_credentials.password_algorithm IS 'Informational algorithm identifier, e.g. argon2id.';
COMMENT ON COLUMN core.auth_password_credentials.must_change_password IS 'Force password change on next suitable authentication flow.';

COMMENT ON TABLE core.auth_providers IS 'Registry of external authentication providers such as OIDC/OAuth/custom providers.';
COMMENT ON COLUMN core.auth_providers.code IS 'Stable provider code, e.g. google, telegram, microsoft.';
COMMENT ON COLUMN core.auth_providers.protocol IS 'Extensible protocol name; intentionally VARCHAR rather than ENUM.';
COMMENT ON COLUMN core.auth_providers.enabled IS 'Provider availability switch.';

COMMENT ON TABLE core.auth_external_identities IS 'Links local Accounts to stable external provider subjects.';
COMMENT ON COLUMN core.auth_external_identities.provider_subject IS 'Stable user identifier supplied by the provider, e.g. OIDC sub.';
COMMENT ON COLUMN core.auth_external_identities.claims IS 'Additional non-secret provider claims.';
COMMENT ON COLUMN core.auth_external_identities.email IS 'Provider-reported email; never auto-merge Accounts solely by this value.';

COMMENT ON TABLE core.auth_passkeys IS 'WebAuthn/passkey credentials. One Account may have multiple passkeys.';
COMMENT ON COLUMN core.auth_passkeys.credential_id IS 'Binary WebAuthn credential ID.';
COMMENT ON COLUMN core.auth_passkeys.public_key IS 'Public key; private key never reaches the server.';
COMMENT ON COLUMN core.auth_passkeys.transports IS 'Known authenticator transports such as internal, usb, nfc, ble.';

COMMENT ON TABLE core.auth_totp_factors IS 'TOTP MFA factors. Secret is encrypted; encryption key lives outside the database.';
COMMENT ON COLUMN core.auth_totp_factors.secret_encrypted IS 'Encrypted TOTP secret.';
COMMENT ON COLUMN core.auth_totp_factors.verified_at IS 'NULL until setup is confirmed by a valid TOTP code.';

COMMENT ON TABLE core.auth_recovery_codes IS 'Single-use MFA recovery codes stored only as cryptographic hashes.';
COMMENT ON COLUMN core.auth_recovery_codes.code_hash IS 'Hash of a recovery code; raw code is never stored.';
COMMENT ON COLUMN core.auth_recovery_codes.used_at IS 'NULL until consumed.';

COMMENT ON TABLE core.auth_sessions IS 'Active/historical sessions. Opaque session tokens are stored only as hashes.';
COMMENT ON COLUMN core.auth_sessions.session_token_hash IS 'Hash of the opaque session token.';
COMMENT ON COLUMN core.auth_sessions.refresh_token_hash IS 'Optional refresh-token hash for clients that need refresh semantics.';
COMMENT ON COLUMN core.auth_sessions.security_stamp IS 'Snapshot of accounts.security_stamp when the session was created.';
COMMENT ON COLUMN core.auth_sessions.authentication_method IS 'Extensible authentication method: password, passkey, provider code, etc.';
COMMENT ON COLUMN core.auth_sessions.client_type IS 'Extensible client type: web, mobile, agent, etc.';

COMMENT ON TABLE core.auth_challenges IS 'Short-lived one-time auth/security challenges: verification, reset, invitation, magic login, etc.';
COMMENT ON COLUMN core.auth_challenges.challenge_type IS 'Extensible challenge scenario identifier.';
COMMENT ON COLUMN core.auth_challenges.secret_hash IS 'Hash of one-time secret/token.';
COMMENT ON COLUMN core.auth_challenges.payload IS 'Safe scenario-specific JSON payload; never place secrets here.';

COMMENT ON TABLE core.roles IS 'RBAC roles. A role is a bundle of permissions, not a separate Admin entity.';
COMMENT ON COLUMN core.roles.code IS 'Stable namespaced role code, e.g. core.superadmin.';
COMMENT ON COLUMN core.roles.is_system IS 'Role is system-provided.';
COMMENT ON COLUMN core.roles.is_protected IS 'Ordinary management flows must not remove/alter protected roles freely.';

COMMENT ON TABLE core.modules IS 'Registry of installed/known platform modules. Core knows metadata only, never module internals.';
COMMENT ON COLUMN core.modules.code IS 'Stable module code, e.g. education, games, chat.';
COMMENT ON COLUMN core.modules.settings_schema_version IS 'Version of module settings schema.';

COMMENT ON TABLE core.permissions IS 'Global catalog of RBAC permission codes.';
COMMENT ON COLUMN core.permissions.module_id IS 'Permission -> Module. NULL means permission belongs to Core itself.';
COMMENT ON COLUMN core.permissions.code IS 'Globally unique namespaced permission code.';

COMMENT ON TABLE core.role_permissions IS 'Many-to-many relation Role <-> Permission.';
COMMENT ON COLUMN core.role_permissions.granted_by_account_id IS 'Account that granted the permission; NULL for seed/system operation.';

COMMENT ON TABLE core.account_roles IS 'Current role assignments of Accounts.';
COMMENT ON COLUMN core.account_roles.assigned_by_account_id IS 'Account that assigned the role; NULL for seed/system operation.';
COMMENT ON COLUMN core.account_roles.expires_at IS 'Optional assignment expiration; NULL means no expiration.';

COMMENT ON TABLE core.module_settings IS 'Non-secret settings of a specific module.';
COMMENT ON COLUMN core.module_settings.setting_key IS 'Setting key unique inside a module.';
COMMENT ON COLUMN core.module_settings.value IS 'JSONB setting value. Do not put secrets here.';

COMMENT ON TABLE core.feature_flags IS 'Global or module-owned feature flags with percentage rollout.';
COMMENT ON COLUMN core.feature_flags.module_id IS 'Owner module; NULL means installation-wide flag.';
COMMENT ON COLUMN core.feature_flags.rollout_percent IS 'Integer rollout percentage from 0 to 100.';

COMMENT ON TABLE core.system_settings IS 'Installation-wide non-secret settings: generic platform/branding metadata and similar configuration.';
COMMENT ON COLUMN core.system_settings.setting_key IS 'Globally unique generic setting key.';
COMMENT ON COLUMN core.system_settings.is_public IS 'Whether this non-secret setting may be exposed to a public/client configuration endpoint.';

COMMENT ON TABLE core.audit_events IS 'Append-only business/security audit: who changed what and when. NOT technical telemetry.';
COMMENT ON COLUMN core.audit_events.actor_account_id IS 'Account actor when applicable; SET NULL preserves audit after account deletion.';
COMMENT ON COLUMN core.audit_events.action IS 'Stable namespaced action code.';
COMMENT ON COLUMN core.audit_events.resource_type IS 'Polymorphic resource type, e.g. core.account or education.lesson.';
COMMENT ON COLUMN core.audit_events.resource_id IS 'Resource ID serialized as text for polymorphic audit reference.';
COMMENT ON COLUMN core.audit_events.before_data IS 'Relevant safe before-state/diff only; never secrets.';
COMMENT ON COLUMN core.audit_events.after_data IS 'Relevant safe after-state/diff only; never secrets.';
COMMENT ON COLUMN core.audit_events.metadata IS 'Additional safe metadata. Never passwords, tokens, TOTP secrets, private keys.';

COMMENT ON TABLE core.outbox_events IS 'Transactional Outbox. Business state and outbox event must be committed in one PostgreSQL transaction.';
COMMENT ON COLUMN core.outbox_events.producer_module IS 'Module that produced the event.';
COMMENT ON COLUMN core.outbox_events.event_type IS 'Stable namespaced event type.';
COMMENT ON COLUMN core.outbox_events.available_at IS 'Earliest time the publisher worker may attempt delivery.';
COMMENT ON COLUMN core.outbox_events.locked_until IS 'Lease expiration for a worker lock.';

COMMENT ON TABLE core.inbox_events IS 'Consumer idempotency ledger. event_id + consumer is unique.';
COMMENT ON COLUMN core.inbox_events.consumer IS 'Stable unique code of the consuming handler.';
COMMENT ON COLUMN core.inbox_events.processed_at IS 'Successful processing time.';
COMMENT ON COLUMN core.inbox_events.locked_until IS 'Lease expiration for a consumer worker lock.';
