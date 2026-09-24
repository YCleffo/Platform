<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.5 immutable subscription invoice snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.invoices (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 subscription_id uuid NOT NULL REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT,
 billing_profile_id uuid NOT NULL REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT,
 billing_reason varchar(40) NOT NULL,
 currency char(3) NOT NULL,
 total_minor bigint NOT NULL DEFAULT 0,
 status varchar(16) NOT NULL DEFAULT 'draft',
 bill_to_legal_name varchar(240) NOT NULL,
 bill_to_country_code char(2) NOT NULL,
 bill_to_contact_name varchar(200),
 bill_to_contact_email varchar(320),
 bill_to_tax_identifier varchar(80),
 bill_to_address jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 issued_at timestamptz,
 paid_at timestamptz,
 voided_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT invoices_reason_check CHECK (billing_reason = 'subscription_create'),
 CONSTRAINT invoices_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
 CONSTRAINT invoices_total_check CHECK (total_minor >= 0),
 CONSTRAINT invoices_status_check CHECK (status IN ('draft','issued','paid','void')),
 CONSTRAINT invoices_status_timestamps_check CHECK (
   (status = 'draft' AND issued_at IS NULL AND paid_at IS NULL AND voided_at IS NULL) OR
   (status = 'issued' AND issued_at IS NOT NULL AND paid_at IS NULL AND voided_at IS NULL) OR
   (status = 'paid' AND issued_at IS NOT NULL AND paid_at IS NOT NULL AND voided_at IS NULL) OR
   (status = 'void' AND paid_at IS NULL AND voided_at IS NOT NULL)
 ),
 CONSTRAINT invoices_legal_name_check CHECK (btrim(bill_to_legal_name) <> '' AND bill_to_legal_name !~ '[[:cntrl:]]'),
 CONSTRAINT invoices_country_check CHECK (bill_to_country_code ~ '^[A-Z]{2}$'),
 CONSTRAINT invoices_contact_name_check CHECK (bill_to_contact_name IS NULL OR (btrim(bill_to_contact_name) <> '' AND bill_to_contact_name !~ '[[:cntrl:]]')),
 CONSTRAINT invoices_contact_email_check CHECK (bill_to_contact_email IS NULL OR (bill_to_contact_email = lower(btrim(bill_to_contact_email)) AND length(bill_to_contact_email) BETWEEN 3 AND 320 AND bill_to_contact_email !~ '[[:cntrl:]]' AND position('@' in bill_to_contact_email) > 1)),
 CONSTRAINT invoices_tax_check CHECK (bill_to_tax_identifier IS NULL OR (btrim(bill_to_tax_identifier) <> '' AND bill_to_tax_identifier !~ '[[:cntrl:]]')),
 CONSTRAINT invoices_address_check CHECK (jsonb_typeof(bill_to_address) = 'object')
)
SQL);
        $this->addSql('CREATE INDEX invoices_account_history_idx ON commercial.invoices (commercial_account_id, created_at DESC, id)');
        $this->addSql('CREATE INDEX invoices_subscription_history_idx ON commercial.invoices (subscription_id, created_at DESC, id)');
        $this->addSql("CREATE UNIQUE INDEX invoices_one_current_initial_uidx ON commercial.invoices (subscription_id) WHERE billing_reason = 'subscription_create' AND status IN ('issued','paid')");
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.invoice_lines (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 invoice_id uuid NOT NULL REFERENCES commercial.invoices(id) ON DELETE RESTRICT,
 subscription_item_id uuid NOT NULL REFERENCES commercial.subscription_items(id) ON DELETE RESTRICT,
 plan_price_id uuid NOT NULL REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT,
 plan_code varchar(120) NOT NULL,
 plan_version integer NOT NULL,
 plan_name varchar(200) NOT NULL,
 billing_period varchar(24) NOT NULL,
 description varchar(400) NOT NULL,
 quantity integer NOT NULL,
 unit_amount_minor bigint NOT NULL,
 total_minor bigint NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT invoice_lines_plan_code_check CHECK (plan_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT invoice_lines_plan_version_check CHECK (plan_version > 0),
 CONSTRAINT invoice_lines_plan_name_check CHECK (btrim(plan_name) <> '' AND plan_name !~ '[[:cntrl:]]'),
 CONSTRAINT invoice_lines_period_check CHECK (billing_period IN ('month','year')),
 CONSTRAINT invoice_lines_description_check CHECK (btrim(description) <> '' AND description !~ '[[:cntrl:]]'),
 CONSTRAINT invoice_lines_quantity_check CHECK (quantity > 0),
 CONSTRAINT invoice_lines_unit_amount_check CHECK (unit_amount_minor >= 0),
 CONSTRAINT invoice_lines_total_check CHECK (total_minor >= 0)
)
SQL);
        $this->addSql('CREATE INDEX invoice_lines_invoice_order_idx ON commercial.invoice_lines (invoice_id, created_at, id)');
        $this->addSql('CREATE INDEX invoice_lines_subscription_item_idx ON commercial.invoice_lines (subscription_item_id)');
        $this->addSql('CREATE INDEX invoice_lines_plan_price_idx ON commercial.invoice_lines (plan_price_id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_invoice_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE subscription_account uuid; profile_account uuid; line_count integer; line_sum numeric;
BEGIN
 IF TG_OP = 'DELETE' THEN
   RAISE EXCEPTION 'invoices are historical and cannot be deleted' USING ERRCODE = 'check_violation';
 END IF;
 SELECT commercial_account_id INTO subscription_account FROM commercial.subscriptions WHERE id = NEW.subscription_id FOR UPDATE;
 SELECT commercial_account_id INTO profile_account FROM commercial.billing_profiles WHERE id = NEW.billing_profile_id FOR UPDATE;
 IF subscription_account IS DISTINCT FROM NEW.commercial_account_id OR profile_account IS DISTINCT FROM NEW.commercial_account_id THEN
   RAISE EXCEPTION 'invoice account contract mismatch' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'UPDATE' THEN
   IF NEW.created_at IS DISTINCT FROM OLD.created_at THEN
     RAISE EXCEPTION 'invoice creation timestamp is immutable' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.status <> 'draft' AND (
     NEW.commercial_account_id, NEW.subscription_id, NEW.billing_profile_id, NEW.billing_reason,
     NEW.currency, NEW.total_minor, NEW.bill_to_legal_name, NEW.bill_to_country_code,
     NEW.bill_to_contact_name, NEW.bill_to_contact_email, NEW.bill_to_tax_identifier,
     NEW.bill_to_address, NEW.created_at, NEW.issued_at
   ) IS DISTINCT FROM (
     OLD.commercial_account_id, OLD.subscription_id, OLD.billing_profile_id, OLD.billing_reason,
     OLD.currency, OLD.total_minor, OLD.bill_to_legal_name, OLD.bill_to_country_code,
     OLD.bill_to_contact_name, OLD.bill_to_contact_email, OLD.bill_to_tax_identifier,
     OLD.bill_to_address, OLD.created_at, OLD.issued_at
   ) THEN
     RAISE EXCEPTION 'issued invoice snapshot is immutable' USING ERRCODE = 'check_violation';
   END IF;
   IF NEW.status <> OLD.status AND NOT (
     (OLD.status = 'draft' AND NEW.status IN ('issued','void')) OR
     (OLD.status = 'issued' AND NEW.status IN ('paid','void'))
   ) THEN
     RAISE EXCEPTION 'invalid invoice status transition' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.status IN ('paid','void') AND NEW IS DISTINCT FROM OLD THEN
     RAISE EXCEPTION 'terminal invoice is immutable' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.status = 'draft' AND NEW.status = 'issued' THEN
     SELECT count(*), COALESCE(sum(total_minor), 0) INTO line_count, line_sum FROM commercial.invoice_lines WHERE invoice_id = NEW.id;
     IF line_count < 1 OR line_sum <> NEW.total_minor THEN
       RAISE EXCEPTION 'issued invoice lines and total are inconsistent' USING ERRCODE = 'check_violation';
     END IF;
   END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER invoice_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.invoices FOR EACH ROW EXECUTE FUNCTION commercial.enforce_invoice_contract()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_invoice_line_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invoice_status text; invoice_subscription uuid; old_invoice_status text;
BEGIN
 SELECT status, subscription_id INTO invoice_status, invoice_subscription
 FROM commercial.invoices WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.invoice_id ELSE NEW.invoice_id END FOR UPDATE;
 IF invoice_status IS DISTINCT FROM 'draft' THEN
   RAISE EXCEPTION 'issued invoice lines are immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'UPDATE' AND NEW.invoice_id <> OLD.invoice_id THEN
   SELECT status INTO old_invoice_status FROM commercial.invoices WHERE id = OLD.invoice_id FOR UPDATE;
   IF old_invoice_status IS DISTINCT FROM 'draft' THEN
     RAISE EXCEPTION 'issued invoice lines are immutable' USING ERRCODE = 'check_violation';
   END IF;
 END IF;
 IF TG_OP <> 'DELETE' AND NOT EXISTS (
   SELECT 1 FROM commercial.subscription_items si
   WHERE si.id = NEW.subscription_item_id AND si.subscription_id = invoice_subscription AND si.plan_price_id = NEW.plan_price_id
 ) THEN
   RAISE EXCEPTION 'invoice line subscription contract mismatch' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER invoice_line_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.invoice_lines FOR EACH ROW EXECUTE FUNCTION commercial.enforce_invoice_line_contract()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER invoice_line_contract_guard ON commercial.invoice_lines');
        $this->addSql('DROP FUNCTION commercial.enforce_invoice_line_contract()');
        $this->addSql('DROP TRIGGER invoice_contract_guard ON commercial.invoices');
        $this->addSql('DROP FUNCTION commercial.enforce_invoice_contract()');
        $this->addSql('DROP TABLE commercial.invoice_lines');
        $this->addSql('DROP TABLE commercial.invoices');
    }
}
