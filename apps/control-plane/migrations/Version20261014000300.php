<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serialize new global idempotency key ownership across scopes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.guard_plan_change_idempotency_key() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 -- Every writer, including older application versions, must agree on one owner.
 -- The transaction advisory lock makes the existence check safe under concurrent inserts.
 PERFORM pg_advisory_xact_lock(hashtextextended(NEW.idempotency_key, 0));
 IF EXISTS (
  SELECT 1 FROM commercial.idempotency_keys k
  WHERE k.idempotency_key = NEW.idempotency_key AND k.scope <> NEW.scope
 ) THEN
  RAISE EXCEPTION 'idempotency key belongs to another operation' USING ERRCODE = 'check_violation';
 END IF;
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.guard_plan_change_idempotency_key() RETURNS trigger LANGUAGE plpgsql AS $$
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
    }
}
