<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist compensation recovery state for paid unapplied plan changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.subscription_plan_changes ADD compensation_status varchar(24), ADD compensation_reason varchar(40)');
        $this->addSql(<<<'SQL'
UPDATE commercial.subscription_plan_changes c SET
 compensation_status = CASE WHEN c.status = 'refunded' THEN 'succeeded'
  WHEN EXISTS (SELECT 1 FROM commercial.payments p JOIN commercial.refunds r ON r.payment_id = p.id
   WHERE p.invoice_id = c.invoice_id AND r.client_request_key = 'plan-change-unapplied-' || c.id::text AND r.status = 'pending') THEN 'pending'
  ELSE 'recovery_required' END,
 compensation_reason = CASE WHEN c.status = 'refunded' THEN NULL
  WHEN EXISTS (SELECT 1 FROM commercial.payments p JOIN commercial.refunds r ON r.payment_id = p.id
   WHERE p.invoice_id = c.invoice_id AND r.client_request_key = 'plan-change-unapplied-' || c.id::text AND r.status = 'pending') THEN NULL
  WHEN EXISTS (SELECT 1 FROM commercial.payments p JOIN commercial.refunds r ON r.payment_id = p.id
   WHERE p.invoice_id = c.invoice_id AND r.client_request_key = 'plan-change-unapplied-' || c.id::text AND r.status = 'canceled') THEN 'provider_refund_canceled'
  ELSE 'legacy_unreserved' END
WHERE c.status IN ('paid_unapplied','refunded')
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE commercial.subscription_plan_changes ADD CONSTRAINT plan_changes_compensation_check CHECK (
 (status NOT IN ('paid_unapplied','refunded') AND compensation_status IS NULL AND compensation_reason IS NULL) OR
 (status = 'paid_unapplied' AND (
   (compensation_status = 'pending' AND compensation_reason IS NULL) OR
   (compensation_status = 'recovery_required' AND compensation_reason IS NOT NULL))) OR
 (status = 'refunded' AND compensation_status = 'succeeded' AND compensation_reason IS NULL)
)
SQL);
        $this->addSql("CREATE INDEX plan_changes_recovery_idx ON commercial.subscription_plan_changes (updated_at, id) WHERE compensation_status = 'recovery_required'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM commercial.subscription_plan_changes WHERE compensation_status IS NOT NULL) THEN
  RAISE EXCEPTION 'plan change compensation history must be retained' USING ERRCODE = 'check_violation';
 END IF;
END $$
SQL);
        $this->addSql('DROP INDEX commercial.plan_changes_recovery_idx');
        $this->addSql('ALTER TABLE commercial.subscription_plan_changes DROP CONSTRAINT plan_changes_compensation_check');
        $this->addSql('ALTER TABLE commercial.subscription_plan_changes DROP COLUMN compensation_reason, DROP COLUMN compensation_status');
    }
}
