<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 account onboarding, billing contact data and owner invariants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE commercial.commercial_accounts
 ADD COLUMN legal_name varchar(240),
 ADD COLUMN onboarding_state varchar(40) NOT NULL DEFAULT 'profile_required',
 ADD CONSTRAINT commercial_accounts_legal_name_check CHECK (legal_name IS NULL OR (btrim(legal_name) <> '' AND legal_name !~ '[[:cntrl:]]')),
 ADD CONSTRAINT commercial_accounts_onboarding_check CHECK (onboarding_state IN ('profile_required','catalog_selection_pending','ready_for_subscription'))
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_onboarding_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.onboarding_state <> OLD.onboarding_state AND NOT (
   (OLD.onboarding_state = 'profile_required' AND NEW.onboarding_state = 'catalog_selection_pending') OR
   (OLD.onboarding_state = 'catalog_selection_pending' AND NEW.onboarding_state = 'ready_for_subscription')
 ) THEN RAISE EXCEPTION 'invalid onboarding state transition' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER commercial_account_onboarding_transition BEFORE UPDATE ON commercial.commercial_accounts FOR EACH ROW EXECUTE FUNCTION commercial.enforce_onboarding_transition()');
        $this->addSql(<<<'SQL'
ALTER TABLE commercial.billing_profiles
 ADD COLUMN contact_name varchar(200),
 ADD COLUMN contact_email varchar(320),
 ADD COLUMN tax_identifier varchar(80),
 ADD COLUMN address jsonb NOT NULL DEFAULT '{}'::jsonb,
 ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now(),
 ADD CONSTRAINT billing_profile_contact_name_check CHECK (contact_name IS NULL OR (btrim(contact_name) <> '' AND contact_name !~ '[[:cntrl:]]')),
 ADD CONSTRAINT billing_profile_contact_email_check CHECK (contact_email IS NULL OR (contact_email = lower(btrim(contact_email)) AND length(contact_email) BETWEEN 3 AND 320 AND contact_email !~ '[[:cntrl:]]' AND position('@' in contact_email) > 1)),
 ADD CONSTRAINT billing_profile_tax_check CHECK (tax_identifier IS NULL OR (btrim(tax_identifier) <> '' AND length(tax_identifier) <= 80 AND tax_identifier !~ '[[:cntrl:]]')),
 ADD CONSTRAINT billing_profile_address_check CHECK (jsonb_typeof(address) = 'object')
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.ensure_new_account_has_owner() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM 1 FROM commercial.commercial_accounts WHERE id = NEW.id FOR UPDATE;
 IF NOT EXISTS (
   SELECT 1 FROM commercial.commercial_account_memberships
   WHERE commercial_account_id = NEW.id AND role = 'owner' AND revoked_at IS NULL
 ) THEN
   RAISE EXCEPTION 'commercial account requires at least one active owner' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.ensure_membership_keeps_owner() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE account_id uuid;
BEGIN
 account_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.commercial_account_id ELSE NEW.commercial_account_id END;
 PERFORM 1 FROM commercial.commercial_accounts WHERE id = account_id FOR UPDATE;
 IF EXISTS (SELECT 1 FROM commercial.commercial_accounts WHERE id = account_id)
    AND NOT EXISTS (
      SELECT 1 FROM commercial.commercial_account_memberships
      WHERE commercial_account_id = account_id AND role = 'owner' AND revoked_at IS NULL
    ) THEN
   RAISE EXCEPTION 'commercial account requires at least one active owner' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE CONSTRAINT TRIGGER commercial_account_owner_required AFTER INSERT ON commercial.commercial_accounts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.ensure_new_account_has_owner()');
        $this->addSql('CREATE CONSTRAINT TRIGGER commercial_membership_owner_required AFTER INSERT OR UPDATE OR DELETE ON commercial.commercial_account_memberships DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.ensure_membership_keeps_owner()');
        $this->addSql(<<<'SQL'
DO $$
BEGIN
 IF EXISTS (
   SELECT 1 FROM commercial.commercial_accounts a
   WHERE NOT EXISTS (
     SELECT 1 FROM commercial.commercial_account_memberships m
     WHERE m.commercial_account_id = a.id AND m.role = 'owner' AND m.revoked_at IS NULL
   )
 ) THEN
   RAISE EXCEPTION 'Phase 2 upgrade blocked: existing commercial account has no active owner';
 END IF;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER commercial_membership_owner_required ON commercial.commercial_account_memberships');
        $this->addSql('DROP TRIGGER commercial_account_owner_required ON commercial.commercial_accounts');
        $this->addSql('DROP FUNCTION commercial.ensure_membership_keeps_owner()');
        $this->addSql('DROP FUNCTION commercial.ensure_new_account_has_owner()');
        $this->addSql('ALTER TABLE commercial.billing_profiles DROP CONSTRAINT billing_profile_contact_name_check, DROP CONSTRAINT billing_profile_contact_email_check, DROP CONSTRAINT billing_profile_tax_check, DROP CONSTRAINT billing_profile_address_check, DROP COLUMN contact_name, DROP COLUMN contact_email, DROP COLUMN tax_identifier, DROP COLUMN address, DROP COLUMN updated_at');
        $this->addSql('DROP TRIGGER commercial_account_onboarding_transition ON commercial.commercial_accounts');
        $this->addSql('DROP FUNCTION commercial.enforce_onboarding_transition()');
        $this->addSql('ALTER TABLE commercial.commercial_accounts DROP CONSTRAINT commercial_accounts_legal_name_check, DROP CONSTRAINT commercial_accounts_onboarding_check, DROP COLUMN legal_name, DROP COLUMN onboarding_state');
    }
}
