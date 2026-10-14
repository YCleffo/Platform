<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reject reuse of historical plan-change keys by new financial operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.guard_plan_change_idempotency_key() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS (
  SELECT 1 FROM commercial.subscription_plan_changes c
  WHERE c.idempotency_key = NEW.idempotency_key
   AND NEW.scope <> ('subscription.plan_change.request.' || replace(c.commercial_account_id::text, '-', ''))
 ) THEN
  RAISE EXCEPTION 'idempotency key belongs to an existing plan change' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER idempotency_plan_change_key_guard BEFORE INSERT ON commercial.idempotency_keys FOR EACH ROW EXECUTE FUNCTION commercial.guard_plan_change_idempotency_key()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER idempotency_plan_change_key_guard ON commercial.idempotency_keys');
        $this->addSql('DROP FUNCTION commercial.guard_plan_change_idempotency_key()');
    }
}
