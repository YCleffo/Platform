DROP TRIGGER IF EXISTS trg_account_consents_protect ON core.account_consents;
DROP FUNCTION IF EXISTS core.protect_account_consent();
DROP TABLE IF EXISTS core.account_consents;
