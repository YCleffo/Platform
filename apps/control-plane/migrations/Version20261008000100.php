<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008000100 extends AbstractMigration
{
    public function getDescription(): string { return 'Harden renewal dunning identity and guarded state transitions'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.renewal_collection_states DROP COLUMN grace_deadline, ADD COLUMN network_retry_count integer NOT NULL DEFAULT 0, ADD CONSTRAINT renewal_collection_network_retry_count_check CHECK (network_retry_count >= 0)');
        $this->addSql('ALTER TABLE commercial.renewal_collection_states DROP CONSTRAINT renewal_collection_failure_class_check');
        $this->addSql("ALTER TABLE commercial.renewal_collection_states ADD CONSTRAINT renewal_collection_failure_class_check CHECK (last_failure_class IS NULL OR last_failure_class IN ('retryable','non_retryable','payment_method_invalid','unknown','operational'))");
        $this->addSql('CREATE UNIQUE INDEX invoices_account_subscription_id_uidx ON commercial.invoices (commercial_account_id, subscription_id, id)');
        $this->addSql('ALTER TABLE commercial.renewal_collection_states ADD CONSTRAINT renewal_collection_invoice_identity_fk FOREIGN KEY (commercial_account_id, subscription_id, invoice_id) REFERENCES commercial.invoices(commercial_account_id, subscription_id, id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.guard_renewal_collection_state() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'renewal collection state is historical' USING ERRCODE = 'check_violation'; END IF;
 IF (NEW.invoice_id, NEW.commercial_account_id, NEW.subscription_id, NEW.created_at)
    IS DISTINCT FROM (OLD.invoice_id, OLD.commercial_account_id, OLD.subscription_id, OLD.created_at) THEN
   RAISE EXCEPTION 'renewal collection identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF NEW.attempt_count < OLD.attempt_count OR NEW.network_retry_count < OLD.network_retry_count THEN
   RAISE EXCEPTION 'renewal attempt counters cannot decrease' USING ERRCODE = 'check_violation';
 END IF;
 IF NEW.state <> OLD.state AND NOT (
   (OLD.state = 'ready' AND NEW.state IN ('collecting','payment_method_required','exhausted')) OR
   (OLD.state = 'collecting' AND NEW.state IN ('retry_wait','payment_method_required','succeeded','exhausted')) OR
   (OLD.state = 'retry_wait' AND NEW.state IN ('collecting','payment_method_required','succeeded','exhausted')) OR
   (OLD.state = 'payment_method_required' AND NEW.state IN ('ready','succeeded','exhausted'))
 ) THEN RAISE EXCEPTION 'invalid renewal collection state transition' USING ERRCODE = 'check_violation'; END IF;
 IF OLD.state IN ('succeeded','exhausted') AND NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'terminal renewal collection state is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER renewal_collection_state_guard BEFORE UPDATE OR DELETE ON commercial.renewal_collection_states FOR EACH ROW EXECUTE FUNCTION commercial.guard_renewal_collection_state()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER renewal_collection_state_guard ON commercial.renewal_collection_states');
        $this->addSql('DROP FUNCTION commercial.guard_renewal_collection_state()');
        $this->addSql('ALTER TABLE commercial.renewal_collection_states DROP CONSTRAINT renewal_collection_invoice_identity_fk');
        $this->addSql('DROP INDEX commercial.invoices_account_subscription_id_uidx');
        $this->addSql('ALTER TABLE commercial.renewal_collection_states DROP CONSTRAINT renewal_collection_failure_class_check, DROP CONSTRAINT renewal_collection_network_retry_count_check, DROP COLUMN network_retry_count, ADD COLUMN grace_deadline timestamptz');
        $this->addSql("ALTER TABLE commercial.renewal_collection_states ADD CONSTRAINT renewal_collection_failure_class_check CHECK (last_failure_class IS NULL OR last_failure_class IN ('retryable','non_retryable','payment_method_invalid','unknown'))");
    }
}
