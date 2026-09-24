<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.2 subscription activation state machine and immutable active items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.subscriptions ADD CONSTRAINT subscriptions_active_period_check CHECK (status <> \'active\' OR (current_period_start IS NOT NULL AND current_period_end IS NOT NULL))');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_subscription_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> OLD.status AND NOT (
   (OLD.status = 'draft' AND NEW.status IN ('active','cancelled')) OR
   (OLD.status = 'active' AND NEW.status IN ('cancelled','expired'))
 ) THEN
   RAISE EXCEPTION 'invalid subscription status transition' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER subscription_transition BEFORE UPDATE ON commercial.subscriptions FOR EACH ROW EXECUTE FUNCTION commercial.enforce_subscription_transition()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_activated_subscription_item() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_status text; old_parent_status text;
BEGIN
 SELECT status INTO parent_status
 FROM commercial.subscriptions
 WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.subscription_id ELSE NEW.subscription_id END
 FOR UPDATE;
 IF parent_status IS DISTINCT FROM 'draft' THEN
   RAISE EXCEPTION 'activated subscription items are immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'UPDATE' AND NEW.subscription_id <> OLD.subscription_id THEN
   SELECT status INTO old_parent_status FROM commercial.subscriptions WHERE id = OLD.subscription_id FOR UPDATE;
   IF old_parent_status IS DISTINCT FROM 'draft' THEN
     RAISE EXCEPTION 'activated subscription items are immutable' USING ERRCODE = 'check_violation';
   END IF;
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER activated_subscription_item_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.subscription_items FOR EACH ROW EXECUTE FUNCTION commercial.protect_activated_subscription_item()');

        $this->addSql('ALTER TABLE commercial.commercial_accounts DROP CONSTRAINT commercial_accounts_onboarding_check');
        $this->addSql("ALTER TABLE commercial.commercial_accounts ADD CONSTRAINT commercial_accounts_onboarding_check CHECK (onboarding_state IN ('profile_required','catalog_selection_pending','ready_for_subscription','completed'))");
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_onboarding_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.onboarding_state <> OLD.onboarding_state AND NOT (
   (OLD.onboarding_state = 'profile_required' AND NEW.onboarding_state = 'catalog_selection_pending') OR
   (OLD.onboarding_state = 'catalog_selection_pending' AND NEW.onboarding_state = 'ready_for_subscription') OR
   (OLD.onboarding_state = 'ready_for_subscription' AND NEW.onboarding_state = 'completed')
 ) THEN RAISE EXCEPTION 'invalid onboarding state transition' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_onboarding_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.onboarding_state <> OLD.onboarding_state AND NOT (
   (OLD.onboarding_state = 'profile_required' AND NEW.onboarding_state = 'catalog_selection_pending') OR
   (OLD.onboarding_state = 'catalog_selection_pending' AND NEW.onboarding_state = 'ready_for_subscription') OR
   (OLD.onboarding_state = 'completed' AND NEW.onboarding_state = 'ready_for_subscription')
 ) THEN RAISE EXCEPTION 'invalid onboarding state transition' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription', updated_at = now() WHERE onboarding_state = 'completed'");
        $this->addSql('ALTER TABLE commercial.commercial_accounts DROP CONSTRAINT commercial_accounts_onboarding_check');
        $this->addSql("ALTER TABLE commercial.commercial_accounts ADD CONSTRAINT commercial_accounts_onboarding_check CHECK (onboarding_state IN ('profile_required','catalog_selection_pending','ready_for_subscription'))");
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_onboarding_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.onboarding_state <> OLD.onboarding_state AND NOT (
   (OLD.onboarding_state = 'profile_required' AND NEW.onboarding_state = 'catalog_selection_pending') OR
   (OLD.onboarding_state = 'catalog_selection_pending' AND NEW.onboarding_state = 'ready_for_subscription')
 ) THEN RAISE EXCEPTION 'invalid onboarding state transition' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('DROP TRIGGER activated_subscription_item_guard ON commercial.subscription_items');
        $this->addSql('DROP FUNCTION commercial.protect_activated_subscription_item()');
        $this->addSql('DROP TRIGGER subscription_transition ON commercial.subscriptions');
        $this->addSql('DROP FUNCTION commercial.enforce_subscription_transition()');
        $this->addSql('ALTER TABLE commercial.subscriptions DROP CONSTRAINT subscriptions_active_period_check');
    }
}
