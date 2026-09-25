<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.6 provider-neutral payments, attempts and paid invoice activation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.payment_provider_accounts (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 provider_code varchar(120) NOT NULL,
 external_account_id varchar(200),
 secret_ref varchar(300) NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'active',
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT payment_provider_accounts_code_check CHECK (provider_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT payment_provider_accounts_external_id_check CHECK (external_account_id IS NULL OR (btrim(external_account_id) <> '' AND external_account_id !~ '[[:cntrl:]]')),
 CONSTRAINT payment_provider_accounts_secret_ref_check CHECK (btrim(secret_ref) <> '' AND secret_ref !~ '[[:cntrl:]]'),
 CONSTRAINT payment_provider_accounts_status_check CHECK (status IN ('active','disabled'))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_payment_provider_account() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
   RAISE EXCEPTION 'payment provider accounts are historical and cannot be deleted' USING ERRCODE = 'check_violation';
 END IF;
 IF (NEW.provider_code, NEW.external_account_id, NEW.created_at) IS DISTINCT FROM (OLD.provider_code, OLD.external_account_id, OLD.created_at) THEN
   RAISE EXCEPTION 'payment provider identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER payment_provider_account_guard BEFORE UPDATE OR DELETE ON commercial.payment_provider_accounts FOR EACH ROW EXECUTE FUNCTION commercial.protect_payment_provider_account()');

        $this->addSql(<<<'SQL'
CREATE TABLE commercial.payments (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 invoice_id uuid NOT NULL UNIQUE REFERENCES commercial.invoices(id) ON DELETE RESTRICT,
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 amount_minor bigint NOT NULL,
 currency char(3) NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'pending',
 created_at timestamptz NOT NULL DEFAULT now(),
 succeeded_at timestamptz,
 cancelled_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT payments_amount_check CHECK (amount_minor >= 0),
 CONSTRAINT payments_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
 CONSTRAINT payments_status_check CHECK (status IN ('pending','succeeded','cancelled')),
 CONSTRAINT payments_status_timestamps_check CHECK (
   (status = 'pending' AND succeeded_at IS NULL AND cancelled_at IS NULL) OR
   (status = 'succeeded' AND succeeded_at IS NOT NULL AND cancelled_at IS NULL) OR
   (status = 'cancelled' AND succeeded_at IS NULL AND cancelled_at IS NOT NULL)
 )
)
SQL);
        $this->addSql('CREATE INDEX payments_account_history_idx ON commercial.payments (commercial_account_id, created_at DESC, id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_payment_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invoice_account uuid; invoice_amount bigint; invoice_currency text;
BEGIN
 IF TG_OP = 'DELETE' THEN
   RAISE EXCEPTION 'payments are historical and cannot be deleted' USING ERRCODE = 'check_violation';
 END IF;
 SELECT commercial_account_id, total_minor, currency INTO invoice_account, invoice_amount, invoice_currency
 FROM commercial.invoices WHERE id = NEW.invoice_id FOR UPDATE;
 IF invoice_account IS DISTINCT FROM NEW.commercial_account_id
    OR invoice_amount IS DISTINCT FROM NEW.amount_minor
    OR invoice_currency IS DISTINCT FROM NEW.currency THEN
   RAISE EXCEPTION 'payment must match invoice account and money' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'UPDATE' THEN
   IF (NEW.invoice_id, NEW.commercial_account_id, NEW.amount_minor, NEW.currency, NEW.created_at)
      IS DISTINCT FROM (OLD.invoice_id, OLD.commercial_account_id, OLD.amount_minor, OLD.currency, OLD.created_at) THEN
     RAISE EXCEPTION 'payment financial identity is immutable' USING ERRCODE = 'check_violation';
   END IF;
   IF NEW.status <> OLD.status AND NOT (OLD.status = 'pending' AND NEW.status IN ('succeeded','cancelled')) THEN
     RAISE EXCEPTION 'invalid payment status transition' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.status IN ('succeeded','cancelled') AND NEW IS DISTINCT FROM OLD THEN
     RAISE EXCEPTION 'terminal payment is immutable' USING ERRCODE = 'check_violation';
   END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER payment_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.payments FOR EACH ROW EXECUTE FUNCTION commercial.enforce_payment_contract()');

        $this->addSql(<<<'SQL'
CREATE TABLE commercial.payment_attempts (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 payment_id uuid NOT NULL REFERENCES commercial.payments(id) ON DELETE RESTRICT,
 provider_account_id uuid NOT NULL REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT,
 method_type varchar(80) NOT NULL,
 provider_request_key varchar(200) NOT NULL,
 provider_payment_id varchar(200),
 status varchar(16) NOT NULL DEFAULT 'pending',
 failure_code varchar(120),
 created_at timestamptz NOT NULL DEFAULT now(),
 completed_at timestamptz,
 CONSTRAINT payment_attempts_method_check CHECK (method_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT payment_attempts_request_key_check CHECK (btrim(provider_request_key) <> '' AND provider_request_key !~ '[[:cntrl:]]'),
 CONSTRAINT payment_attempts_provider_id_check CHECK (provider_payment_id IS NULL OR (btrim(provider_payment_id) <> '' AND provider_payment_id !~ '[[:cntrl:]]')),
 CONSTRAINT payment_attempts_status_check CHECK (status IN ('pending','succeeded','failed','cancelled')),
 CONSTRAINT payment_attempts_timestamp_check CHECK ((status = 'pending' AND completed_at IS NULL) OR (status <> 'pending' AND completed_at IS NOT NULL)),
 CONSTRAINT payment_attempts_failure_check CHECK (failure_code IS NULL OR (status = 'failed' AND failure_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$')),
 UNIQUE (provider_account_id, provider_request_key)
)
SQL);
        $this->addSql('CREATE INDEX payment_attempts_payment_history_idx ON commercial.payment_attempts (payment_id, created_at, id)');
        $this->addSql("CREATE UNIQUE INDEX payment_attempts_one_pending_uidx ON commercial.payment_attempts (payment_id) WHERE status = 'pending'");
        $this->addSql('CREATE UNIQUE INDEX payment_attempts_provider_payment_uidx ON commercial.payment_attempts (provider_account_id, provider_payment_id) WHERE provider_payment_id IS NOT NULL');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_payment_attempt_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE payment_status text; provider_status text;
BEGIN
 IF TG_OP = 'DELETE' THEN
   RAISE EXCEPTION 'payment attempts are historical and cannot be deleted' USING ERRCODE = 'check_violation';
 END IF;
 SELECT status INTO payment_status FROM commercial.payments WHERE id = NEW.payment_id FOR UPDATE;
 IF TG_OP = 'INSERT' THEN
   SELECT status INTO provider_status FROM commercial.payment_provider_accounts WHERE id = NEW.provider_account_id FOR UPDATE;
   IF payment_status IS DISTINCT FROM 'pending' OR provider_status IS DISTINCT FROM 'active' THEN
     RAISE EXCEPTION 'new attempt requires pending payment and active provider account' USING ERRCODE = 'check_violation';
   END IF;
 ELSE
   IF (NEW.payment_id, NEW.provider_account_id, NEW.method_type, NEW.provider_request_key, NEW.created_at)
      IS DISTINCT FROM (OLD.payment_id, OLD.provider_account_id, OLD.method_type, OLD.provider_request_key, OLD.created_at) THEN
     RAISE EXCEPTION 'payment attempt identity is immutable' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.provider_payment_id IS NOT NULL AND NEW.provider_payment_id IS DISTINCT FROM OLD.provider_payment_id THEN
     RAISE EXCEPTION 'provider payment reference is immutable' USING ERRCODE = 'check_violation';
   END IF;
   IF NEW.status <> OLD.status AND NOT (OLD.status = 'pending' AND NEW.status IN ('succeeded','failed','cancelled')) THEN
     RAISE EXCEPTION 'invalid payment attempt transition' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.status IN ('succeeded','failed','cancelled') AND NEW IS DISTINCT FROM OLD THEN
     RAISE EXCEPTION 'terminal payment attempt is immutable' USING ERRCODE = 'check_violation';
   END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER payment_attempt_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.payment_attempts FOR EACH ROW EXECUTE FUNCTION commercial.enforce_payment_attempt_contract()');

        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_invoice_contract() RETURNS trigger LANGUAGE plpgsql AS $$
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
   IF NEW.created_at IS DISTINCT FROM OLD.created_at THEN RAISE EXCEPTION 'invoice creation timestamp is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status <> 'draft' AND (NEW.commercial_account_id, NEW.subscription_id, NEW.billing_profile_id, NEW.billing_reason, NEW.currency, NEW.total_minor, NEW.bill_to_legal_name, NEW.bill_to_country_code, NEW.bill_to_contact_name, NEW.bill_to_contact_email, NEW.bill_to_tax_identifier, NEW.bill_to_address, NEW.created_at, NEW.issued_at)
      IS DISTINCT FROM (OLD.commercial_account_id, OLD.subscription_id, OLD.billing_profile_id, OLD.billing_reason, OLD.currency, OLD.total_minor, OLD.bill_to_legal_name, OLD.bill_to_country_code, OLD.bill_to_contact_name, OLD.bill_to_contact_email, OLD.bill_to_tax_identifier, OLD.bill_to_address, OLD.created_at, OLD.issued_at) THEN
     RAISE EXCEPTION 'issued invoice snapshot is immutable' USING ERRCODE = 'check_violation';
   END IF;
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
   ) THEN
     RAISE EXCEPTION 'paid invoice requires a matching succeeded payment' USING ERRCODE = 'check_violation';
   END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
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
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('DROP TRIGGER payment_attempt_contract_guard ON commercial.payment_attempts');
        $this->addSql('DROP FUNCTION commercial.enforce_payment_attempt_contract()');
        $this->addSql('DROP TABLE commercial.payment_attempts');
        $this->addSql('DROP TRIGGER payment_contract_guard ON commercial.payments');
        $this->addSql('DROP FUNCTION commercial.enforce_payment_contract()');
        $this->addSql('DROP TABLE commercial.payments');
        $this->addSql('DROP TRIGGER payment_provider_account_guard ON commercial.payment_provider_accounts');
        $this->addSql('DROP FUNCTION commercial.protect_payment_provider_account()');
        $this->addSql('DROP TABLE commercial.payment_provider_accounts');
    }
}
