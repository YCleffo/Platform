<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finalize fully refunded unapplied plan changes without deleting history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.subscription_plan_changes DROP CONSTRAINT plan_changes_status_check');
        $this->addSql("ALTER TABLE commercial.subscription_plan_changes ADD CONSTRAINT plan_changes_status_check CHECK (status IN ('scheduled','payment_required','applied','cancelled','paid_unapplied','refunded'))");
        $this->guard(true);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM commercial.subscription_plan_changes WHERE status = 'refunded') THEN
   RAISE EXCEPTION 'refunded plan change history must be retained' USING ERRCODE = 'check_violation';
 END IF;
END $$
SQL);
        $this->addSql('ALTER TABLE commercial.subscription_plan_changes DROP CONSTRAINT plan_changes_status_check');
        $this->addSql("ALTER TABLE commercial.subscription_plan_changes ADD CONSTRAINT plan_changes_status_check CHECK (status IN ('scheduled','payment_required','applied','cancelled','paid_unapplied'))");
        $this->guard(false);
    }

    private function guard(bool $allowRefunded): void
    {
        $transition = $allowRefunded
            ? "OR (OLD.status = 'paid_unapplied' AND NEW.status = 'refunded')"
            : '';
        $terminal = $allowRefunded
            ? "('applied','cancelled','refunded')"
            : "('applied','cancelled','paid_unapplied')";
        $this->addSql(<<<SQL
CREATE OR REPLACE FUNCTION commercial.guard_subscription_plan_change() RETURNS trigger LANGUAGE plpgsql AS \$\$
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
   {$transition}
 ) THEN
   RAISE EXCEPTION 'invalid plan change transition' USING ERRCODE = 'check_violation';
 END IF;
 IF OLD.status IN {$terminal} AND NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'terminal plan change is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END \$\$
SQL);
    }
}
