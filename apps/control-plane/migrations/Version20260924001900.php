<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.9 provider-neutral refunds, immutable allocations and refund receipts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.refunds (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 payment_id uuid NOT NULL REFERENCES commercial.payments(id) ON DELETE RESTRICT,
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 provider_account_id uuid NOT NULL REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT,
 amount_minor bigint NOT NULL,
 currency char(3) NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'pending',
 client_request_key varchar(200) NOT NULL,
 request_sha256 bytea NOT NULL,
 provider_request_key varchar(200) NOT NULL,
 provider_refund_id varchar(200),
 created_at timestamptz NOT NULL DEFAULT now(),
 last_attempted_at timestamptz,
 completed_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now(),
 last_error_code varchar(120),
 CONSTRAINT refunds_amount_check CHECK (amount_minor > 0),
 CONSTRAINT refunds_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
 CONSTRAINT refunds_status_check CHECK (status IN ('pending','succeeded','canceled')),
 CONSTRAINT refunds_client_key_check CHECK (btrim(client_request_key) <> '' AND client_request_key !~ '[[:cntrl:]]'),
 CONSTRAINT refunds_request_hash_check CHECK (octet_length(request_sha256) = 32),
 CONSTRAINT refunds_provider_key_check CHECK (btrim(provider_request_key) <> '' AND provider_request_key !~ '[[:cntrl:]]'),
 CONSTRAINT refunds_provider_id_check CHECK (provider_refund_id IS NULL OR (btrim(provider_refund_id) <> '' AND provider_refund_id !~ '[[:cntrl:]]')),
 CONSTRAINT refunds_timestamps_check CHECK ((status = 'pending' AND completed_at IS NULL) OR (status IN ('succeeded','canceled') AND completed_at IS NOT NULL)),
 CONSTRAINT refunds_error_check CHECK (last_error_code IS NULL OR last_error_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 UNIQUE (payment_id, client_request_key),
 UNIQUE (provider_account_id, provider_request_key)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX refunds_provider_refund_uidx ON commercial.refunds (provider_account_id, provider_refund_id) WHERE provider_refund_id IS NOT NULL');
        $this->addSql('CREATE INDEX refunds_payment_history_idx ON commercial.refunds (payment_id, created_at, id)');
        $this->addSql("CREATE INDEX refunds_reconciliation_idx ON commercial.refunds (created_at, id) WHERE status = 'pending'");
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.refund_items (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 refund_id uuid NOT NULL REFERENCES commercial.refunds(id) ON DELETE RESTRICT,
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
 CONSTRAINT refund_items_description_check CHECK (btrim(description) <> '' AND description !~ '[[:cntrl:]]'),
 CONSTRAINT refund_items_quantity_check CHECK (quantity > 0),
 CONSTRAINT refund_items_amount_check CHECK (unit_amount_minor >= 0 AND total_minor > 0 AND unit_amount_minor::numeric * quantity = total_minor),
 CONSTRAINT refund_items_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
 CONSTRAINT refund_items_fiscal_check CHECK (vat_code BETWEEN 1 AND 12 AND payment_subject ~ '^[a-z0-9]+([._-][a-z0-9]+)*$' AND payment_mode ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 UNIQUE (refund_id, invoice_line_id)
)
SQL);
        $this->addSql('CREATE INDEX refund_items_refund_order_idx ON commercial.refund_items (refund_id, created_at, id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_refund_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p record; refunded numeric;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'refunds are historical and cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
 SELECT * INTO p FROM commercial.payments WHERE id = NEW.payment_id FOR UPDATE;
 IF p.status IS DISTINCT FROM 'succeeded' OR p.commercial_account_id IS DISTINCT FROM NEW.commercial_account_id OR p.currency IS DISTINCT FROM NEW.currency THEN
   RAISE EXCEPTION 'refund must match a succeeded payment' USING ERRCODE = 'check_violation';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM commercial.payment_attempts a WHERE a.payment_id = NEW.payment_id AND a.provider_account_id = NEW.provider_account_id AND a.status = 'succeeded') THEN
   RAISE EXCEPTION 'refund requires the succeeded payment provider account' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'UPDATE' THEN
   IF (NEW.payment_id, NEW.commercial_account_id, NEW.provider_account_id, NEW.amount_minor, NEW.currency,
       NEW.client_request_key, NEW.request_sha256, NEW.provider_request_key, NEW.created_at)
      IS DISTINCT FROM
      (OLD.payment_id, OLD.commercial_account_id, OLD.provider_account_id, OLD.amount_minor, OLD.currency,
       OLD.client_request_key, OLD.request_sha256, OLD.provider_request_key, OLD.created_at) THEN
     RAISE EXCEPTION 'refund financial identity is immutable' USING ERRCODE = 'check_violation';
   END IF;
   IF OLD.provider_refund_id IS NOT NULL AND NEW.provider_refund_id IS DISTINCT FROM OLD.provider_refund_id THEN RAISE EXCEPTION 'provider refund reference is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF NEW.status <> OLD.status AND NOT (OLD.status = 'pending' AND NEW.status IN ('succeeded','canceled')) THEN RAISE EXCEPTION 'invalid refund status transition' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status IN ('succeeded','canceled') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'terminal refund is immutable' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 IF NEW.status IN ('pending','succeeded') THEN
   SELECT COALESCE(sum(amount_minor), 0) INTO refunded FROM commercial.refunds
   WHERE payment_id = NEW.payment_id AND status IN ('pending','succeeded') AND id <> NEW.id;
   IF refunded + NEW.amount_minor > p.amount_minor THEN RAISE EXCEPTION 'refund amount exceeds refundable payment balance' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER refund_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.refunds FOR EACH ROW EXECUTE FUNCTION commercial.enforce_refund_contract()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_refund_item_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; l record;
BEGIN
 IF TG_OP IN ('UPDATE','DELETE') THEN RAISE EXCEPTION 'refund items are immutable' USING ERRCODE = 'check_violation'; END IF;
 SELECT * INTO r FROM commercial.refunds WHERE id = NEW.refund_id;
 SELECT * INTO l FROM commercial.invoice_lines WHERE id = NEW.invoice_line_id;
 IF r.status IS DISTINCT FROM 'pending' OR l.invoice_id IS DISTINCT FROM (SELECT invoice_id FROM commercial.payments WHERE id = r.payment_id)
    OR l.description IS DISTINCT FROM NEW.description OR NEW.unit_amount_minor > l.unit_amount_minor
    OR r.currency IS DISTINCT FROM NEW.currency OR NEW.quantity > l.quantity THEN
   RAISE EXCEPTION 'refund item must match invoice snapshot' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER refund_item_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.refund_items FOR EACH ROW EXECUTE FUNCTION commercial.enforce_refund_item_contract()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.validate_refund_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_refund_id uuid; target_payment_id uuid; actual_total numeric; overallocated boolean;
BEGIN
 IF TG_TABLE_NAME = 'refunds' THEN target_refund_id := (to_jsonb(NEW)->>'id')::uuid;
 ELSE target_refund_id := (to_jsonb(NEW)->>'refund_id')::uuid; END IF;
 SELECT payment_id INTO target_payment_id FROM commercial.refunds WHERE id = target_refund_id;
 SELECT COALESCE(sum(total_minor), 0) INTO actual_total FROM commercial.refund_items WHERE refund_id = target_refund_id;
 IF NOT EXISTS (SELECT 1 FROM commercial.refunds WHERE id = target_refund_id AND amount_minor = actual_total)
    OR NOT EXISTS (SELECT 1 FROM commercial.refund_items WHERE refund_id = target_refund_id) THEN
   RAISE EXCEPTION 'refund item allocation is incomplete' USING ERRCODE = 'check_violation';
 END IF;
 SELECT EXISTS (
   SELECT 1 FROM commercial.refund_items ri
   JOIN commercial.refunds r ON r.id = ri.refund_id
   JOIN commercial.invoice_lines il ON il.id = ri.invoice_line_id
   WHERE r.payment_id = target_payment_id AND r.status IN ('pending','succeeded')
   GROUP BY ri.invoice_line_id, il.total_minor HAVING sum(ri.total_minor) > il.total_minor
 ) INTO overallocated;
 IF overallocated THEN RAISE EXCEPTION 'refund line allocation exceeds invoice line total' USING ERRCODE = 'check_violation'; END IF;
 RETURN NULL;
END $$
SQL);
        $this->addSql('CREATE CONSTRAINT TRIGGER refund_snapshot_check AFTER INSERT OR UPDATE ON commercial.refunds DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.validate_refund_snapshot()');
        $this->addSql('CREATE CONSTRAINT TRIGGER refund_item_snapshot_check AFTER INSERT ON commercial.refund_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.validate_refund_snapshot()');

        $this->addSql('DROP TRIGGER receipt_item_snapshot_check ON commercial.receipt_items');
        $this->addSql('DROP TRIGGER receipt_snapshot_check ON commercial.receipts');
        $this->addSql('DROP TRIGGER receipt_item_contract_guard ON commercial.receipt_items');
        $this->addSql('DROP TRIGGER receipt_contract_guard ON commercial.receipts');
        $this->addSql('ALTER TABLE commercial.receipts DROP CONSTRAINT receipts_payment_id_receipt_type_key, DROP CONSTRAINT receipts_type_check');
        $this->addSql('ALTER TABLE commercial.receipts ALTER payment_attempt_id DROP NOT NULL, ADD refund_id uuid REFERENCES commercial.refunds(id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE commercial.receipts ADD CONSTRAINT receipts_type_check CHECK (receipt_type IN ('payment','refund')), ADD CONSTRAINT receipts_source_check CHECK ((receipt_type = 'payment' AND payment_attempt_id IS NOT NULL AND refund_id IS NULL) OR (receipt_type = 'refund' AND refund_id IS NOT NULL AND payment_attempt_id IS NULL))");
        $this->addSql("CREATE UNIQUE INDEX receipts_one_payment_uidx ON commercial.receipts (payment_id) WHERE receipt_type = 'payment'");
        $this->addSql("CREATE UNIQUE INDEX receipts_one_refund_uidx ON commercial.receipts (refund_id) WHERE receipt_type = 'refund'");
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_receipt_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p record; a record; pa record; rf record;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'receipts are historical and cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' THEN
   IF (NEW.payment_id, NEW.invoice_id, NEW.commercial_account_id, NEW.payment_attempt_id, NEW.refund_id, NEW.provider_account_id, NEW.receipt_type, NEW.provider_request_key, NEW.amount_minor, NEW.currency, NEW.customer_email, NEW.customer_name, NEW.vat_code, NEW.payment_subject, NEW.payment_mode, NEW.created_at)
      IS DISTINCT FROM (OLD.payment_id, OLD.invoice_id, OLD.commercial_account_id, OLD.payment_attempt_id, OLD.refund_id, OLD.provider_account_id, OLD.receipt_type, OLD.provider_request_key, OLD.amount_minor, OLD.currency, OLD.customer_email, OLD.customer_name, OLD.vat_code, OLD.payment_subject, OLD.payment_mode, OLD.created_at) THEN RAISE EXCEPTION 'receipt financial identity is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.external_receipt_id IS NOT NULL AND NEW.external_receipt_id IS DISTINCT FROM OLD.external_receipt_id THEN RAISE EXCEPTION 'provider receipt reference is immutable' USING ERRCODE = 'check_violation'; END IF;
   IF NEW.status <> OLD.status AND NOT (OLD.status = 'pending' AND NEW.status IN ('succeeded','canceled')) THEN RAISE EXCEPTION 'invalid receipt status transition' USING ERRCODE = 'check_violation'; END IF;
   IF OLD.status IN ('succeeded','canceled') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'terminal receipt is immutable' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 SELECT * INTO p FROM commercial.payments WHERE id = NEW.payment_id;
 SELECT * INTO pa FROM commercial.payment_provider_accounts WHERE id = NEW.provider_account_id;
 IF p.status IS DISTINCT FROM 'succeeded' OR p.invoice_id IS DISTINCT FROM NEW.invoice_id OR p.commercial_account_id IS DISTINCT FROM NEW.commercial_account_id OR p.currency IS DISTINCT FROM NEW.currency THEN RAISE EXCEPTION 'receipt must match succeeded payment' USING ERRCODE = 'check_violation'; END IF;
 IF NEW.receipt_type = 'payment' THEN
   SELECT * INTO a FROM commercial.payment_attempts WHERE id = NEW.payment_attempt_id;
   IF p.amount_minor IS DISTINCT FROM NEW.amount_minor OR a.status IS DISTINCT FROM 'succeeded' OR a.payment_id IS DISTINCT FROM NEW.payment_id OR a.provider_account_id IS DISTINCT FROM NEW.provider_account_id OR a.provider_payment_id IS NULL THEN RAISE EXCEPTION 'payment receipt requires succeeded provider attempt' USING ERRCODE = 'check_violation'; END IF;
 ELSE
   SELECT * INTO rf FROM commercial.refunds WHERE id = NEW.refund_id;
   IF rf.status IS DISTINCT FROM 'succeeded' OR rf.payment_id IS DISTINCT FROM NEW.payment_id OR rf.commercial_account_id IS DISTINCT FROM NEW.commercial_account_id OR rf.provider_account_id IS DISTINCT FROM NEW.provider_account_id OR rf.amount_minor IS DISTINCT FROM NEW.amount_minor OR rf.currency IS DISTINCT FROM NEW.currency OR rf.provider_refund_id IS NULL THEN RAISE EXCEPTION 'refund receipt requires succeeded authoritative refund' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 IF TG_OP = 'INSERT' AND (pa.receipt_mode IS DISTINCT FROM 'yookassa' OR pa.receipt_vat_code IS DISTINCT FROM NEW.vat_code OR pa.receipt_payment_subject IS DISTINCT FROM NEW.payment_subject OR pa.receipt_payment_mode IS DISTINCT FROM NEW.payment_mode) THEN RAISE EXCEPTION 'receipt fiscal configuration mismatch' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER receipt_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.receipts FOR EACH ROW EXECUTE FUNCTION commercial.enforce_receipt_contract()');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_receipt_item_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; source record;
BEGIN
 IF TG_OP IN ('UPDATE','DELETE') THEN RAISE EXCEPTION 'receipt items are immutable' USING ERRCODE = 'check_violation'; END IF;
 SELECT * INTO r FROM commercial.receipts WHERE id = NEW.receipt_id;
 IF r.receipt_type = 'payment' THEN
   SELECT description, quantity, unit_amount_minor, total_minor INTO source FROM commercial.invoice_lines WHERE id = NEW.invoice_line_id AND invoice_id = r.invoice_id;
 ELSE
   SELECT description, quantity, unit_amount_minor, total_minor INTO source FROM commercial.refund_items WHERE refund_id = r.refund_id AND invoice_line_id = NEW.invoice_line_id;
 END IF;
 IF r.status IS DISTINCT FROM 'pending' OR source IS NULL OR source.description IS DISTINCT FROM NEW.description OR source.quantity IS DISTINCT FROM NEW.quantity OR source.unit_amount_minor IS DISTINCT FROM NEW.unit_amount_minor OR source.total_minor IS DISTINCT FROM NEW.total_minor OR r.currency IS DISTINCT FROM NEW.currency OR r.vat_code IS DISTINCT FROM NEW.vat_code OR r.payment_subject IS DISTINCT FROM NEW.payment_subject OR r.payment_mode IS DISTINCT FROM NEW.payment_mode THEN RAISE EXCEPTION 'receipt item must match fiscal source snapshot' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER receipt_item_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.receipt_items FOR EACH ROW EXECUTE FUNCTION commercial.enforce_receipt_item_contract()');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.validate_receipt_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_receipt_id uuid; expected_count bigint; actual_count bigint; actual_total numeric; r record;
BEGIN
 IF TG_TABLE_NAME = 'receipts' THEN target_receipt_id := (to_jsonb(NEW)->>'id')::uuid;
 ELSE target_receipt_id := (to_jsonb(NEW)->>'receipt_id')::uuid; END IF;
 SELECT * INTO r FROM commercial.receipts WHERE id = target_receipt_id;
 IF r.receipt_type = 'payment' THEN SELECT count(*) INTO expected_count FROM commercial.invoice_lines WHERE invoice_id = r.invoice_id;
 ELSE SELECT count(*) INTO expected_count FROM commercial.refund_items WHERE refund_id = r.refund_id; END IF;
 SELECT count(*), COALESCE(sum(total_minor), 0) INTO actual_count, actual_total FROM commercial.receipt_items WHERE receipt_id = target_receipt_id;
 IF expected_count <> actual_count OR r.amount_minor IS DISTINCT FROM actual_total THEN RAISE EXCEPTION 'receipt item snapshot is incomplete' USING ERRCODE = 'check_violation'; END IF;
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
        $this->addSql('DROP TRIGGER receipt_item_contract_guard ON commercial.receipt_items');
        $this->addSql('DROP TRIGGER receipt_contract_guard ON commercial.receipts');
        $this->addSql("DELETE FROM commercial.receipt_items WHERE receipt_id IN (SELECT id FROM commercial.receipts WHERE receipt_type = 'refund')");
        $this->addSql("DELETE FROM commercial.receipts WHERE receipt_type = 'refund'");
        $this->addSql('DROP INDEX commercial.receipts_one_refund_uidx');
        $this->addSql('DROP INDEX commercial.receipts_one_payment_uidx');
        $this->addSql('ALTER TABLE commercial.receipts DROP CONSTRAINT receipts_source_check, DROP CONSTRAINT receipts_type_check, DROP refund_id');
        $this->addSql('ALTER TABLE commercial.receipts ALTER payment_attempt_id SET NOT NULL');
        $this->addSql("ALTER TABLE commercial.receipts ADD CONSTRAINT receipts_type_check CHECK (receipt_type = 'payment'), ADD CONSTRAINT receipts_payment_id_receipt_type_key UNIQUE (payment_id, receipt_type)");
        $this->restoreReceiptFunctions();
        $this->addSql('CREATE TRIGGER receipt_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.receipts FOR EACH ROW EXECUTE FUNCTION commercial.enforce_receipt_contract()');
        $this->addSql('CREATE TRIGGER receipt_item_contract_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.receipt_items FOR EACH ROW EXECUTE FUNCTION commercial.enforce_receipt_item_contract()');
        $this->addSql('CREATE CONSTRAINT TRIGGER receipt_snapshot_check AFTER INSERT OR UPDATE ON commercial.receipts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.validate_receipt_snapshot()');
        $this->addSql('CREATE CONSTRAINT TRIGGER receipt_item_snapshot_check AFTER INSERT ON commercial.receipt_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.validate_receipt_snapshot()');
        $this->addSql('DROP TRIGGER refund_item_snapshot_check ON commercial.refund_items');
        $this->addSql('DROP TRIGGER refund_snapshot_check ON commercial.refunds');
        $this->addSql('DROP FUNCTION commercial.validate_refund_snapshot()');
        $this->addSql('DROP TRIGGER refund_item_contract_guard ON commercial.refund_items');
        $this->addSql('DROP FUNCTION commercial.enforce_refund_item_contract()');
        $this->addSql('DROP TABLE commercial.refund_items');
        $this->addSql('DROP TRIGGER refund_contract_guard ON commercial.refunds');
        $this->addSql('DROP FUNCTION commercial.enforce_refund_contract()');
        $this->addSql('DROP TABLE commercial.refunds');
    }

    private function restoreReceiptFunctions(): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_receipt_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p record; a record; pa record;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'receipts are historical and cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' THEN
   IF (NEW.payment_id, NEW.invoice_id, NEW.commercial_account_id, NEW.payment_attempt_id, NEW.provider_account_id, NEW.receipt_type, NEW.provider_request_key, NEW.amount_minor, NEW.currency, NEW.customer_email, NEW.customer_name, NEW.vat_code, NEW.payment_subject, NEW.payment_mode, NEW.created_at)
      IS DISTINCT FROM (OLD.payment_id, OLD.invoice_id, OLD.commercial_account_id, OLD.payment_attempt_id, OLD.provider_account_id, OLD.receipt_type, OLD.provider_request_key, OLD.amount_minor, OLD.currency, OLD.customer_email, OLD.customer_name, OLD.vat_code, OLD.payment_subject, OLD.payment_mode, OLD.created_at) THEN RAISE EXCEPTION 'receipt financial identity is immutable' USING ERRCODE = 'check_violation'; END IF;
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
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_receipt_item_contract() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record; l record;
BEGIN
 IF TG_OP IN ('UPDATE','DELETE') THEN RAISE EXCEPTION 'receipt items are immutable' USING ERRCODE = 'check_violation'; END IF;
 SELECT * INTO r FROM commercial.receipts WHERE id = NEW.receipt_id;
 SELECT * INTO l FROM commercial.invoice_lines WHERE id = NEW.invoice_line_id;
 IF r.status IS DISTINCT FROM 'pending' OR l.invoice_id IS DISTINCT FROM r.invoice_id OR l.description IS DISTINCT FROM NEW.description OR l.quantity IS DISTINCT FROM NEW.quantity OR l.unit_amount_minor IS DISTINCT FROM NEW.unit_amount_minor OR l.total_minor IS DISTINCT FROM NEW.total_minor OR r.currency IS DISTINCT FROM NEW.currency OR r.vat_code IS DISTINCT FROM NEW.vat_code OR r.payment_subject IS DISTINCT FROM NEW.payment_subject OR r.payment_mode IS DISTINCT FROM NEW.payment_mode THEN RAISE EXCEPTION 'receipt item must match invoice snapshot' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.validate_receipt_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_receipt_id uuid; expected_count bigint; actual_count bigint; actual_total numeric;
BEGIN
 IF TG_TABLE_NAME = 'receipts' THEN target_receipt_id := (to_jsonb(NEW)->>'id')::uuid;
 ELSE target_receipt_id := (to_jsonb(NEW)->>'receipt_id')::uuid; END IF;
 SELECT count(*) INTO expected_count FROM commercial.invoice_lines il JOIN commercial.receipts r ON r.invoice_id = il.invoice_id WHERE r.id = target_receipt_id;
 SELECT count(*), COALESCE(sum(total_minor), 0) INTO actual_count, actual_total FROM commercial.receipt_items WHERE receipt_items.receipt_id = target_receipt_id;
 IF expected_count <> actual_count OR NOT EXISTS (SELECT 1 FROM commercial.receipts r WHERE r.id = target_receipt_id AND r.amount_minor = actual_total) THEN RAISE EXCEPTION 'receipt item snapshot is incomplete' USING ERRCODE = 'check_violation'; END IF;
 RETURN NULL;
END $$
SQL);
    }
}
