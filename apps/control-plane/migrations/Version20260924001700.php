<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.8 fiscal receipt obligations and immutable invoice snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE commercial.payment_provider_accounts ADD receipt_mode varchar(24) NOT NULL DEFAULT 'disabled', ADD receipt_vat_code smallint, ADD receipt_payment_subject varchar(40), ADD receipt_payment_mode varchar(40)");
        $this->addSql("ALTER TABLE commercial.payment_provider_accounts ADD CONSTRAINT payment_provider_accounts_receipt_check CHECK ((receipt_mode = 'disabled' AND receipt_vat_code IS NULL AND receipt_payment_subject IS NULL AND receipt_payment_mode IS NULL) OR (receipt_mode = 'yookassa' AND receipt_vat_code IS NOT NULL AND receipt_vat_code BETWEEN 1 AND 12 AND receipt_payment_subject ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND receipt_payment_mode ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'))");
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.receipts (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 payment_id uuid NOT NULL REFERENCES commercial.payments(id) ON DELETE RESTRICT,
 invoice_id uuid NOT NULL REFERENCES commercial.invoices(id) ON DELETE RESTRICT,
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 payment_attempt_id uuid NOT NULL REFERENCES commercial.payment_attempts(id) ON DELETE RESTRICT,
 provider_account_id uuid NOT NULL REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT,
 receipt_type varchar(24) NOT NULL DEFAULT 'payment',
 status varchar(16) NOT NULL DEFAULT 'pending',
 provider_request_key varchar(200) NOT NULL,
 external_receipt_id varchar(200),
 amount_minor bigint NOT NULL,
 currency char(3) NOT NULL,
 customer_email varchar(320) NOT NULL,
 customer_name varchar(200),
 vat_code smallint NOT NULL,
 payment_subject varchar(40) NOT NULL,
 payment_mode varchar(40) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 last_attempted_at timestamptz,
 completed_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now(),
 last_error_code varchar(120),
 CONSTRAINT receipts_type_check CHECK (receipt_type = 'payment'),
 CONSTRAINT receipts_status_check CHECK (status IN ('pending','succeeded','canceled')),
 CONSTRAINT receipts_amount_check CHECK (amount_minor > 0),
 CONSTRAINT receipts_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
 CONSTRAINT receipts_email_check CHECK (customer_email = btrim(customer_email) AND customer_email ~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$' AND customer_email !~ '[[:cntrl:]]'),
 CONSTRAINT receipts_name_check CHECK (customer_name IS NULL OR (btrim(customer_name) <> '' AND customer_name !~ '[[:cntrl:]]')),
 CONSTRAINT receipts_fiscal_check CHECK (vat_code BETWEEN 1 AND 12 AND payment_subject ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND payment_mode ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT receipts_provider_id_check CHECK (external_receipt_id IS NULL OR (btrim(external_receipt_id) <> '' AND external_receipt_id !~ '[[:cntrl:]]')),
 CONSTRAINT receipts_timestamps_check CHECK ((status = 'pending' AND completed_at IS NULL) OR (status IN ('succeeded','canceled') AND completed_at IS NOT NULL)),
 CONSTRAINT receipts_error_check CHECK (last_error_code IS NULL OR last_error_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 UNIQUE (payment_id, receipt_type),
 UNIQUE (provider_account_id, provider_request_key)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX receipts_external_receipt_uidx ON commercial.receipts (provider_account_id, external_receipt_id) WHERE external_receipt_id IS NOT NULL');
        $this->addSql("CREATE INDEX receipts_reconciliation_idx ON commercial.receipts (created_at, id) WHERE status = 'pending'");
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.receipt_items (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 receipt_id uuid NOT NULL REFERENCES commercial.receipts(id) ON DELETE RESTRICT,
 invoice_line_id uuid NOT NULL REFERENCES commercial.invoice_lines(id) ON DELETE RESTRICT,
 description varchar(400) NOT NULL,
 quantity integer NOT NULL,
 unit_amount_minor bigint NOT NULL,
 total_minor bigint NOT NULL,
 currency char(3) NOT NULL,
 vat_code smallint NOT NULL,
 payment_subject varchar(40) NOT NULL,
 payment_mode varchar(40) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT receipt_items_description_check CHECK (btrim(description) <> '' AND description !~ '[[:cntrl:]]'),
 CONSTRAINT receipt_items_quantity_check CHECK (quantity > 0),
 CONSTRAINT receipt_items_amount_check CHECK (unit_amount_minor >= 0 AND total_minor >= 0 AND unit_amount_minor::numeric * quantity = total_minor),
 CONSTRAINT receipt_items_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
 CONSTRAINT receipt_items_fiscal_check CHECK (vat_code BETWEEN 1 AND 12 AND payment_subject ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND payment_mode ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 UNIQUE (receipt_id, invoice_line_id)
)
SQL);
        $this->addSql('CREATE INDEX receipt_items_receipt_order_idx ON commercial.receipt_items (receipt_id, created_at, id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_receipt_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p record; a record; pa record;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'receipts are historical and cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' THEN
   IF (NEW.payment_id, NEW.invoice_id, NEW.commercial_account_id, NEW.payment_attempt_id, NEW.provider_account_id, NEW.receipt_type, NEW.provider_request_key, NEW.amount_minor, NEW.currency, NEW.customer_email, NEW.customer_name, NEW.vat_code, NEW.payment_subject, NEW.payment_mode, NEW.created_at)
      IS DISTINCT FROM (OLD.payment_id, OLD.invoice_id, OLD.commercial_account_id, OLD.payment_attempt_id, OLD.provider_account_id, OLD.receipt_type, OLD.provider_request_key, OLD.amount_minor, OLD.currency, OLD.customer_email, OLD.customer_name, OLD.vat_code, OLD.payment_subject, OLD.payment_mode, OLD.created_at) THEN
     RAISE EXCEPTION 'receipt financial identity is immutable' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.external_receipt_id IS NOT NULL AND NEW.external_receipt_id IS DISTINCT FROM OLD.external_receipt_id THEN RAISE EXCEPTION 'provider receipt reference is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF NEW.status <> OLD.status AND NOT (OLD.status = 'pending' AND NEW.status IN ('succeeded','canceled')) THEN RAISE EXCEPTION 'invalid receipt status transition' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status IN ('succeeded','canceled') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'terminal receipt is immutable' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 SELECT * INTO p FROM commercial.payments WHERE id = NEW.payment_id;
 SELECT * INTO a FROM commercial.payment_attempts WHERE id = NEW.payment_attempt_id;
 SELECT * INTO pa FROM commercial.payment_provider_accounts WHERE id = NEW.provider_account_id;
 IF p.status IS DISTINCT FROM 'succeeded' OR p.invoice_id IS DISTINCT FROM NEW.invoice_id OR p.commercial_account_id IS DISTINCT FROM NEW.commercial_account_id OR p.amount_minor IS DISTINCT FROM NEW.amount_minor OR p.currency IS DISTINCT FROM NEW.currency THEN RAISE EXCEPTION 'receipt must match succeeded payment' USING ERRCODE = 'check_violation'; END IF;
 IF a.status IS DISTINCT FROM 'succeeded' OR a.payment_id IS DISTINCT FROM NEW.payment_id OR a.provider_account_id IS DISTINCT FROM NEW.provider_account_id OR a.provider_payment_id IS NULL THEN RAISE EXCEPTION 'receipt requires succeeded provider attempt' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'INSERT' AND (pa.receipt_mode IS DISTINCT FROM 'yookassa' OR pa.receipt_vat_code IS DISTINCT FROM NEW.vat_code OR pa.receipt_payment_subject IS DISTINCT FROM NEW.payment_subject OR pa.receipt_payment_mode IS DISTINCT FROM NEW.payment_mode) THEN RAISE EXCEPTION 'receipt fiscal configuration mismatch' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER receipt_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.receipts FOR EACH ROW EXECUTE FUNCTION commercial.enforce_receipt_contract()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_receipt_item_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; l record;
BEGIN
 IF TG_OP IN ('UPDATE','DELETE') THEN RAISE EXCEPTION 'receipt items are immutable' USING ERRCODE = 'check_violation'; END IF;
 SELECT * INTO r FROM commercial.receipts WHERE id = NEW.receipt_id;
 SELECT * INTO l FROM commercial.invoice_lines WHERE id = NEW.invoice_line_id;
 IF r.status IS DISTINCT FROM 'pending' OR l.invoice_id IS DISTINCT FROM r.invoice_id OR l.description IS DISTINCT FROM NEW.description OR l.quantity IS DISTINCT FROM NEW.quantity OR l.unit_amount_minor IS DISTINCT FROM NEW.unit_amount_minor OR l.total_minor IS DISTINCT FROM NEW.total_minor OR r.currency IS DISTINCT FROM NEW.currency OR r.vat_code IS DISTINCT FROM NEW.vat_code OR r.payment_subject IS DISTINCT FROM NEW.payment_subject OR r.payment_mode IS DISTINCT FROM NEW.payment_mode THEN RAISE EXCEPTION 'receipt item must match invoice snapshot' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER receipt_item_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.receipt_items FOR EACH ROW EXECUTE FUNCTION commercial.enforce_receipt_item_contract()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.validate_receipt_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_receipt_id uuid; expected_count bigint; actual_count bigint; actual_total numeric;
BEGIN
 IF TG_TABLE_NAME = 'receipts' THEN
   target_receipt_id := NEW.id;
 ELSE
   target_receipt_id := NEW.receipt_id;
 END IF;
 SELECT count(*) INTO expected_count FROM commercial.invoice_lines il JOIN commercial.receipts r ON r.invoice_id = il.invoice_id WHERE r.id = target_receipt_id;
 SELECT count(*), COALESCE(sum(total_minor), 0) INTO actual_count, actual_total FROM commercial.receipt_items WHERE receipt_items.receipt_id = target_receipt_id;
 IF expected_count <> actual_count OR NOT EXISTS (SELECT 1 FROM commercial.receipts r WHERE r.id = target_receipt_id AND r.amount_minor = actual_total) THEN RAISE EXCEPTION 'receipt item snapshot is incomplete' USING ERRCODE = 'check_violation'; END IF;
 RETURN NULL;
END $$
SQL);
        $this->addSql('CREATE CONSTRAINT TRIGGER receipt_snapshot_check AFTER INSERT OR UPDATE ON commercial.receipts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.validate_receipt_snapshot()');
        $this->addSql('CREATE CONSTRAINT TRIGGER receipt_item_snapshot_check AFTER INSERT ON commercial.receipt_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.validate_receipt_snapshot()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER receipt_item_snapshot_check ON commercial.receipt_items');
        $this->addSql('DROP TRIGGER receipt_snapshot_check ON commercial.receipts');
        $this->addSql('DROP FUNCTION commercial.validate_receipt_snapshot()');
        $this->addSql('DROP TRIGGER receipt_item_contract_guard ON commercial.receipt_items');
        $this->addSql('DROP FUNCTION commercial.enforce_receipt_item_contract()');
        $this->addSql('DROP TRIGGER receipt_contract_guard ON commercial.receipts');
        $this->addSql('DROP FUNCTION commercial.enforce_receipt_contract()');
        $this->addSql('DROP TABLE commercial.receipt_items');
        $this->addSql('DROP TABLE commercial.receipts');
        $this->addSql('ALTER TABLE commercial.payment_provider_accounts DROP CONSTRAINT payment_provider_accounts_receipt_check, DROP receipt_payment_mode, DROP receipt_payment_subject, DROP receipt_vat_code, DROP receipt_mode');
    }
}
