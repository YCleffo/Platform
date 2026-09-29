DELETE FROM core.role_permissions WHERE permission_id IN (
    SELECT id FROM core.permissions WHERE code IN ('core.service_credentials.read', 'core.service_credentials.manage')
);
DELETE FROM core.permissions WHERE code IN ('core.service_credentials.read', 'core.service_credentials.manage');
DROP TRIGGER service_credential_account_type_guard ON core.accounts;
DROP FUNCTION core.prevent_human_service_credentials();
DROP TABLE core.service_credentials;
DROP FUNCTION core.enforce_service_credential_account();
