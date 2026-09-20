-- ============================================================================
-- 007 — Unique constraints and query indexes
-- Indexes are named explicitly so DBeaver and EXPLAIN output stay readable.
-- ============================================================================

-- persons
CREATE INDEX idx_persons_status ON core.persons(status);
CREATE INDEX idx_persons_name ON core.persons(family_name, given_name);
CREATE INDEX idx_persons_birth_date ON core.persons(birth_date);

-- person_contacts
CREATE UNIQUE INDEX uq_person_contacts_value
    ON core.person_contacts(person_id, contact_type, normalized_value);
CREATE INDEX idx_person_contacts_person_type
    ON core.person_contacts(person_id, contact_type);
CREATE INDEX idx_person_contacts_normalized
    ON core.person_contacts(normalized_value);
CREATE UNIQUE INDEX uq_person_contacts_primary_per_type
    ON core.person_contacts(person_id, contact_type)
    WHERE is_primary = true;

-- accounts
CREATE UNIQUE INDEX uq_accounts_person ON core.accounts(person_id);
CREATE INDEX idx_accounts_status ON core.accounts(status);
CREATE INDEX idx_accounts_type ON core.accounts(account_type);
CREATE INDEX idx_accounts_approved_by ON core.accounts(approved_by_account_id);

-- auth_identifiers
CREATE UNIQUE INDEX uq_auth_identifiers_type_value
    ON core.auth_identifiers(identifier_type, normalized_value);
CREATE INDEX idx_auth_identifiers_account ON core.auth_identifiers(account_id);
CREATE INDEX idx_auth_identifiers_account_type
    ON core.auth_identifiers(account_id, identifier_type);
CREATE UNIQUE INDEX uq_auth_identifiers_primary_per_type
    ON core.auth_identifiers(account_id, identifier_type)
    WHERE is_primary = true;

-- password / providers / external identities
CREATE UNIQUE INDEX uq_auth_password_credentials_account
    ON core.auth_password_credentials(account_id);
CREATE UNIQUE INDEX uq_auth_providers_code ON core.auth_providers(code);
CREATE INDEX idx_auth_providers_enabled ON core.auth_providers(enabled);
CREATE UNIQUE INDEX uq_external_identity_provider_subject
    ON core.auth_external_identities(provider_id, provider_subject);
CREATE UNIQUE INDEX uq_external_identity_account_provider
    ON core.auth_external_identities(account_id, provider_id);
CREATE INDEX idx_external_identities_account ON core.auth_external_identities(account_id);
CREATE INDEX idx_external_identities_provider ON core.auth_external_identities(provider_id);
CREATE INDEX idx_external_identities_email ON core.auth_external_identities(email);

-- MFA / sessions / challenges
CREATE UNIQUE INDEX uq_auth_passkeys_credential ON core.auth_passkeys(credential_id);
CREATE INDEX idx_auth_passkeys_account ON core.auth_passkeys(account_id);
CREATE INDEX idx_auth_passkeys_revoked ON core.auth_passkeys(revoked_at);
CREATE INDEX idx_auth_totp_account ON core.auth_totp_factors(account_id);
CREATE INDEX idx_auth_totp_revoked ON core.auth_totp_factors(revoked_at);
CREATE UNIQUE INDEX uq_auth_recovery_codes_hash ON core.auth_recovery_codes(code_hash);
CREATE INDEX idx_auth_recovery_codes_account ON core.auth_recovery_codes(account_id);
CREATE INDEX idx_auth_recovery_codes_used ON core.auth_recovery_codes(used_at);
CREATE UNIQUE INDEX uq_auth_sessions_token ON core.auth_sessions(session_token_hash);
CREATE UNIQUE INDEX uq_auth_sessions_refresh ON core.auth_sessions(refresh_token_hash);
CREATE INDEX idx_auth_sessions_account ON core.auth_sessions(account_id);
CREATE INDEX idx_auth_sessions_expires ON core.auth_sessions(expires_at);
CREATE INDEX idx_auth_sessions_account_expires ON core.auth_sessions(account_id, expires_at);
CREATE UNIQUE INDEX uq_auth_challenges_secret ON core.auth_challenges(secret_hash);
CREATE INDEX idx_auth_challenges_account ON core.auth_challenges(account_id);
CREATE INDEX idx_auth_challenges_type_expires ON core.auth_challenges(challenge_type, expires_at);

-- RBAC / modules
CREATE UNIQUE INDEX uq_roles_code ON core.roles(code);
CREATE INDEX idx_roles_system ON core.roles(is_system);
CREATE UNIQUE INDEX uq_modules_code ON core.modules(code);
CREATE INDEX idx_modules_enabled ON core.modules(enabled);
CREATE UNIQUE INDEX uq_permissions_code ON core.permissions(code);
CREATE INDEX idx_permissions_module ON core.permissions(module_id);
CREATE INDEX idx_role_permissions_permission ON core.role_permissions(permission_id);
CREATE INDEX idx_account_roles_role ON core.account_roles(role_id);
CREATE INDEX idx_account_roles_expires ON core.account_roles(expires_at);

-- settings
CREATE UNIQUE INDEX uq_module_settings_module_key
    ON core.module_settings(module_id, setting_key);
CREATE UNIQUE INDEX uq_feature_flags_code ON core.feature_flags(code);
CREATE INDEX idx_feature_flags_module ON core.feature_flags(module_id);
CREATE UNIQUE INDEX uq_system_settings_key ON core.system_settings(setting_key);

-- audit / integration events
CREATE UNIQUE INDEX uq_audit_events_event ON core.audit_events(event_id);
CREATE INDEX idx_audit_events_time ON core.audit_events(occurred_at);
CREATE INDEX idx_audit_events_actor ON core.audit_events(actor_account_id);
CREATE INDEX idx_audit_events_resource ON core.audit_events(resource_type, resource_id);
CREATE INDEX idx_audit_events_correlation ON core.audit_events(correlation_id);
CREATE INDEX idx_audit_events_action ON core.audit_events(action);

CREATE UNIQUE INDEX uq_outbox_events_event ON core.outbox_events(event_id);
CREATE INDEX idx_outbox_events_dispatch ON core.outbox_events(status, available_at);
CREATE INDEX idx_outbox_events_aggregate ON core.outbox_events(aggregate_type, aggregate_id);
CREATE INDEX idx_outbox_events_correlation ON core.outbox_events(correlation_id);

CREATE UNIQUE INDEX uq_inbox_events_event_consumer
    ON core.inbox_events(event_id, consumer);
CREATE INDEX idx_inbox_events_consumer_status ON core.inbox_events(consumer, status);
CREATE INDEX idx_inbox_events_status_received ON core.inbox_events(status, received_at);
