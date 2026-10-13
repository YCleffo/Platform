<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record immutable subscription plan-change terms and guarded lifecycle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.subscription_plan_changes (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 source_subscription_id uuid NOT NULL REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT,
 target_subscription_id uuid NOT NULL UNIQUE REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT,
 old_plan_price_id uuid NOT NULL REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT,
 new_plan_price_id uuid NOT NULL REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT,
 old_quantity integer NOT NULL,
 new_quantity integer NOT NULL,
 change_kind varchar(16) NOT NULL,
 status varchar(24) NOT NULL,
 idempotency_key varchar(200) NOT NULL,
 request_sha256 bytea NOT NULL,
 calculation jsonb NOT NULL,
 currency char(3) NOT NULL,
 proration_net_minor bigint NOT NULL,
 amount_due_minor bigint NOT NULL,
 period_start timestamptz NOT NULL,
 period_end timestamptz NOT NULL,
 invoice_id uuid UNIQUE REFERENCES commercial.invoices(id) ON DELETE RESTRICT,
 requested_at timestamptz NOT NULL DEFAULT now(),
 applied_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT plan_changes_distinct_subscriptions_check CHECK (source_subscription_id <> target_subscription_id),
 CONSTRAINT plan_changes_quantity_check CHECK (old_quantity > 0 AND new_quantity > 0),
 CONSTRAINT plan_changes_kind_check CHECK (change_kind IN ('upgrade','downgrade')),
 CONSTRAINT plan_changes_status_check CHECK (status IN ('scheduled','payment_required','applied','cancelled','paid_unapplied')),
 CONSTRAINT plan_changes_money_check CHECK (currency ~ '^[A-Z]{3}$' AND amount_due_minor >= 0),
 CONSTRAINT plan_changes_period_check CHECK (period_end > period_start),
 CONSTRAINT plan_changes_request_hash_check CHECK (octet_length(request_sha256) = 32),
 CONSTRAINT plan_changes_calculation_check CHECK (jsonb_typeof(calculation) = 'object'),
 CONSTRAINT plan_changes_applied_at_check CHECK ((status = 'applied') = (applied_at IS NOT NULL)),
 CONSTRAINT plan_changes_invoice_check CHECK (status <> 'payment_required' OR invoice_id IS NOT NULL),
 UNIQUE (commercial_account_id, idempotency_key)
)
SQL);
        $this->addSql("CREATE UNIQUE INDEX plan_changes_one_open_source_uidx ON commercial.subscription_plan_changes (source_subscription_id) WHERE status IN ('scheduled','payment_required','paid_unapplied')");
        $this->addSql('CREATE INDEX plan_changes_due_idx ON commercial.subscription_plan_changes (period_end, id) WHERE status = \'scheduled\'');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.guard_subscription_plan_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
   RAISE EXCEPTION 'plan change history cannot be deleted' USING ERRCODE = 'check_violation';
 END IF;
 IF (NEW.commercial_account_id, NEW.source_subscription_id, NEW.target_subscription_id,
     NEW.old_plan_price_id, NEW.new_plan_price_id, NEW.old_quantity, NEW.new_quantity,
     NEW.change_kind, NEW.idempotency_key, NEW.request_sha256, NEW.calculation,
     NEW.currency, NEW.proration_net_minor, NEW.amount_due_minor, NEW.period_start,
     NEW.period_end, NEW.requested_at)
    IS DISTINCT FROM
    (OLD.commercial_account_id, OLD.source_subscription_id, OLD.target_subscription_id,
     OLD.old_plan_price_id, OLD.new_plan_price_id, OLD.old_quantity, OLD.new_quantity,
     OLD.change_kind, OLD.idempotency_key, OLD.request_sha256, OLD.calculation,
     OLD.currency, OLD.proration_net_minor, OLD.amount_due_minor, OLD.period_start,
     OLD.period_end, OLD.requested_at) THEN
   RAISE EXCEPTION 'plan change calculation is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF OLD.invoice_id IS NOT NULL AND NEW.invoice_id IS DISTINCT FROM OLD.invoice_id THEN
   RAISE EXCEPTION 'plan change invoice is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF NEW.status <> OLD.status AND NOT (
   (OLD.status = 'scheduled' AND NEW.status IN ('payment_required','applied','cancelled')) OR
   (OLD.status = 'payment_required' AND NEW.status IN ('applied','cancelled','paid_unapplied'))
 ) THEN
   RAISE EXCEPTION 'invalid plan change transition' USING ERRCODE = 'check_violation';
 END IF;
 IF OLD.status IN ('applied','cancelled','paid_unapplied') AND NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'terminal plan change is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER subscription_plan_change_guard BEFORE UPDATE OR DELETE ON commercial.subscription_plan_changes FOR EACH ROW EXECUTE FUNCTION commercial.guard_subscription_plan_change()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM commercial.subscription_plan_changes) THEN
   RAISE EXCEPTION 'plan change history must be retained' USING ERRCODE = 'check_violation';
 END IF;
END $$
SQL);
        $this->addSql('DROP TRIGGER subscription_plan_change_guard ON commercial.subscription_plan_changes');
        $this->addSql('DROP FUNCTION commercial.guard_subscription_plan_change()');
        $this->addSql('DROP TABLE commercial.subscription_plan_changes');
    }
}
