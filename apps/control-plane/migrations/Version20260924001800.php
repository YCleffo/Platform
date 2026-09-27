<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.9 recurring invoice periods and immutable renewal contracts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.invoices ADD service_period_start timestamptz, ADD service_period_end timestamptz');
        $this->addSql('ALTER TABLE commercial.invoices DROP CONSTRAINT invoices_reason_check');
        $this->addSql("ALTER TABLE commercial.invoices ADD CONSTRAINT invoices_reason_check CHECK (billing_reason IN ('subscription_create','subscription_renewal'))");
        $this->addSql("ALTER TABLE commercial.invoices ADD CONSTRAINT invoices_service_period_check CHECK ((billing_reason = 'subscription_create' AND service_period_start IS NULL AND service_period_end IS NULL) OR (billing_reason = 'subscription_renewal' AND service_period_start IS NOT NULL AND service_period_end > service_period_start))");
        $this->addSql("CREATE UNIQUE INDEX invoices_one_current_renewal_period_uidx ON commercial.invoices (subscription_id, service_period_start, service_period_end) WHERE billing_reason = 'subscription_renewal' AND status IN ('issued','paid')");
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_invoice_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE subscription_account uuid; subscription_status text; profile_account uuid; line_count integer; line_sum numeric;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'invoices are historical and cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
 SELECT commercial_account_id, status INTO subscription_account, subscription_status FROM commercial.subscriptions WHERE id = NEW.subscription_id FOR UPDATE;
 SELECT commercial_account_id INTO profile_account FROM commercial.billing_profiles WHERE id = NEW.billing_profile_id FOR UPDATE;
 IF subscription_account IS DISTINCT FROM NEW.commercial_account_id OR profile_account IS DISTINCT FROM NEW.commercial_account_id THEN
   RAISE EXCEPTION 'invoice account contract mismatch' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'INSERT' AND NEW.billing_reason = 'subscription_renewal' AND subscription_status IS DISTINCT FROM 'active' THEN
   RAISE EXCEPTION 'renewal invoice requires an active subscription' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'UPDATE' THEN
   IF NEW.created_at IS DISTINCT FROM OLD.created_at THEN RAISE EXCEPTION 'invoice creation timestamp is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status <> 'draft' AND (
     NEW.commercial_account_id, NEW.subscription_id, NEW.billing_profile_id, NEW.billing_reason,
     NEW.service_period_start, NEW.service_period_end, NEW.currency, NEW.total_minor,
     NEW.bill_to_legal_name, NEW.bill_to_country_code, NEW.bill_to_contact_name,
     NEW.bill_to_contact_email, NEW.bill_to_tax_identifier, NEW.bill_to_address,
     NEW.created_at, NEW.issued_at
   ) IS DISTINCT FROM (
     OLD.commercial_account_id, OLD.subscription_id, OLD.billing_profile_id, OLD.billing_reason,
     OLD.service_period_start, OLD.service_period_end, OLD.currency, OLD.total_minor,
     OLD.bill_to_legal_name, OLD.bill_to_country_code, OLD.bill_to_contact_name,
     OLD.bill_to_contact_email, OLD.bill_to_tax_identifier, OLD.bill_to_address,
     OLD.created_at, OLD.issued_at
   ) THEN RAISE EXCEPTION 'issued invoice snapshot is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF NEW.status <> OLD.status AND NOT ((OLD.status = 'draft' AND NEW.status IN ('issued','void')) OR (OLD.status = 'issued' AND NEW.status IN ('paid','void'))) THEN
     RAISE EXCEPTION 'invalid invoice status transition' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.status IN ('paid','void') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'terminal invoice is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status = 'draft' AND NEW.status = 'issued' THEN
     SELECT count(*), COALESCE(sum(total_minor), 0) INTO line_count, line_sum FROM commercial.invoice_lines WHERE invoice_id = NEW.id;
     IF line_count < 1 OR line_sum <> NEW.total_minor THEN RAISE EXCEPTION 'issued invoice lines and total are inconsistent' USING ERRCODE = 'check_violation'; END IF;
   END IF;
   IF OLD.status = 'issued' AND NEW.status = 'paid' AND NOT EXISTS (
     SELECT 1 FROM commercial.payments p WHERE p.invoice_id = NEW.id AND p.status = 'succeeded'
       AND p.commercial_account_id = NEW.commercial_account_id AND p.amount_minor = NEW.total_minor AND p.currency = NEW.currency
   ) THEN RAISE EXCEPTION 'paid invoice requires a matching succeeded payment' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE commercial.invoices SET status = 'void', voided_at = COALESCE(voided_at, now()), paid_at = NULL, updated_at = now() WHERE billing_reason = 'subscription_renewal' AND status IN ('draft','issued')");
        $this->addSql("DELETE FROM commercial.invoices WHERE billing_reason = 'subscription_renewal'");
        $this->addSql('DROP INDEX commercial.invoices_one_current_renewal_period_uidx');
        $this->addSql('ALTER TABLE commercial.invoices DROP CONSTRAINT invoices_service_period_check, DROP CONSTRAINT invoices_reason_check');
        $this->addSql("ALTER TABLE commercial.invoices ADD CONSTRAINT invoices_reason_check CHECK (billing_reason = 'subscription_create')");
        $this->addSql('ALTER TABLE commercial.invoices DROP service_period_start, DROP service_period_end');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_invoice_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE subscription_account uuid; profile_account uuid; line_count integer; line_sum numeric;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'invoices are historical and cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
 SELECT commercial_account_id INTO subscription_account FROM commercial.subscriptions WHERE id = NEW.subscription_id FOR UPDATE;
 SELECT commercial_account_id INTO profile_account FROM commercial.billing_profiles WHERE id = NEW.billing_profile_id FOR UPDATE;
 IF subscription_account IS DISTINCT FROM NEW.commercial_account_id OR profile_account IS DISTINCT FROM NEW.commercial_account_id THEN RAISE EXCEPTION 'invoice account contract mismatch' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' THEN
   IF NEW.created_at IS DISTINCT FROM OLD.created_at THEN RAISE EXCEPTION 'invoice creation timestamp is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status <> 'draft' AND (NEW.commercial_account_id, NEW.subscription_id, NEW.billing_profile_id, NEW.billing_reason, NEW.currency, NEW.total_minor, NEW.bill_to_legal_name, NEW.bill_to_country_code, NEW.bill_to_contact_name, NEW.bill_to_contact_email, NEW.bill_to_tax_identifier, NEW.bill_to_address, NEW.created_at, NEW.issued_at)
      IS DISTINCT FROM (OLD.commercial_account_id, OLD.subscription_id, OLD.billing_profile_id, OLD.billing_reason, OLD.currency, OLD.total_minor, OLD.bill_to_legal_name, OLD.bill_to_country_code, OLD.bill_to_contact_name, OLD.bill_to_contact_email, OLD.bill_to_tax_identifier, OLD.bill_to_address, OLD.created_at, OLD.issued_at) THEN RAISE EXCEPTION 'issued invoice snapshot is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF NEW.status <> OLD.status AND NOT ((OLD.status = 'draft' AND NEW.status IN ('issued','void')) OR (OLD.status = 'issued' AND NEW.status IN ('paid','void'))) THEN RAISE EXCEPTION 'invalid invoice status transition' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status IN ('paid','void') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'terminal invoice is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status = 'draft' AND NEW.status = 'issued' THEN
     SELECT count(*), COALESCE(sum(total_minor), 0) INTO line_count, line_sum FROM commercial.invoice_lines WHERE invoice_id = NEW.id;
     IF line_count < 1 OR line_sum <> NEW.total_minor THEN RAISE EXCEPTION 'issued invoice lines and total are inconsistent' USING ERRCODE = 'check_violation'; END IF;
   END IF;
   IF OLD.status = 'issued' AND NEW.status = 'paid' AND NOT EXISTS (
     SELECT 1 FROM commercial.payments p WHERE p.invoice_id = NEW.id AND p.status = 'succeeded'
       AND p.commercial_account_id = NEW.commercial_account_id AND p.amount_minor = NEW.total_minor AND p.currency = NEW.currency
   ) THEN RAISE EXCEPTION 'paid invoice requires a matching succeeded payment' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
    }
}
