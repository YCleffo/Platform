<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.4 usage periods, counters and metered feature semantics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE commercial.features ADD usage_mode varchar(16) NOT NULL DEFAULT 'none'");
        $this->addSql("ALTER TABLE commercial.features ADD CONSTRAINT features_usage_mode_check CHECK (usage_mode IN ('none','counter','gauge'))");
        $this->addSql("ALTER TABLE commercial.features ADD CONSTRAINT features_metered_integer_check CHECK (usage_mode = 'none' OR value_type = 'integer')");
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.protect_feature_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.code, NEW.value_type, NEW.usage_mode) IS DISTINCT FROM (OLD.code, OLD.value_type, OLD.usage_mode) THEN
   RAISE EXCEPTION 'feature identity, type and usage mode are immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_plan_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE plan_state text; feature_type text; feature_usage_mode text;
BEGIN
 SELECT status INTO plan_state FROM commercial.plans WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.plan_id ELSE NEW.plan_id END FOR UPDATE;
 IF plan_state <> 'draft' THEN RAISE EXCEPTION 'published plan terms are immutable' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' AND NEW.plan_id <> OLD.plan_id THEN
   SELECT status INTO plan_state FROM commercial.plans WHERE id = OLD.plan_id FOR UPDATE;
   IF plan_state <> 'draft' THEN RAISE EXCEPTION 'published plan terms are immutable' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 IF TG_TABLE_NAME = 'plan_features' AND TG_OP <> 'DELETE' THEN
   SELECT value_type, usage_mode INTO feature_type, feature_usage_mode FROM commercial.features WHERE id = NEW.feature_id;
   IF feature_type = 'boolean' AND jsonb_typeof(NEW.value) <> 'boolean'
      OR feature_type = 'integer' AND (jsonb_typeof(NEW.value) <> 'number' OR NEW.value::text !~ '^-?[0-9]+$')
      OR feature_type = 'string' AND jsonb_typeof(NEW.value) <> 'string'
      OR feature_type = 'json' AND jsonb_typeof(NEW.value) = 'null'
   THEN RAISE EXCEPTION 'feature value type mismatch' USING ERRCODE = 'check_violation'; END IF;
   IF feature_usage_mode <> 'none' AND (NEW.value::text)::numeric NOT BETWEEN 0 AND 9223372036854775807 THEN
     RAISE EXCEPTION 'metered feature limit must fit a nonnegative bigint' USING ERRCODE = 'check_violation';
   END IF;
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.usage_periods (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 subscription_id uuid NOT NULL REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT,
 starts_at timestamptz NOT NULL,
 ends_at timestamptz NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'open',
 created_at timestamptz NOT NULL DEFAULT now(),
 closed_at timestamptz,
 CONSTRAINT usage_periods_bounds_check CHECK (ends_at > starts_at),
 CONSTRAINT usage_periods_status_check CHECK (status IN ('open','closed')),
 CONSTRAINT usage_periods_closed_check CHECK ((status = 'open' AND closed_at IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL))
)
SQL);
        $this->addSql('CREATE INDEX usage_periods_subscription_history_idx ON commercial.usage_periods (subscription_id, starts_at DESC)');
        $this->addSql("CREATE UNIQUE INDEX usage_periods_one_open_per_subscription_uidx ON commercial.usage_periods (subscription_id) WHERE status = 'open'");
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_usage_period() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
   RAISE EXCEPTION 'usage periods are historical and cannot be deleted' USING ERRCODE = 'check_violation';
 END IF;
 IF (NEW.subscription_id, NEW.starts_at, NEW.ends_at, NEW.created_at) IS DISTINCT FROM (OLD.subscription_id, OLD.starts_at, OLD.ends_at, OLD.created_at) THEN
   RAISE EXCEPTION 'usage period contract is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF OLD.status = 'closed' AND NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'closed usage period is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF NEW.status <> OLD.status AND NOT (OLD.status = 'open' AND NEW.status = 'closed') THEN
   RAISE EXCEPTION 'invalid usage period status transition' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER usage_period_guard BEFORE UPDATE OR DELETE ON commercial.usage_periods FOR EACH ROW EXECUTE FUNCTION commercial.protect_usage_period()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.usage_counters (
 usage_period_id uuid NOT NULL REFERENCES commercial.usage_periods(id) ON DELETE RESTRICT,
 feature_id uuid NOT NULL REFERENCES commercial.features(id) ON DELETE RESTRICT,
 quantity bigint NOT NULL DEFAULT 0,
 updated_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY (usage_period_id, feature_id),
 CONSTRAINT usage_counters_quantity_check CHECK (quantity >= 0)
)
SQL);
        $this->addSql('CREATE INDEX usage_counters_feature_idx ON commercial.usage_counters (feature_id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_usage_counter() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE period_status text; feature_usage_mode text;
BEGIN
 IF TG_OP = 'DELETE' THEN
   RAISE EXCEPTION 'usage counters are historical and cannot be deleted' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'UPDATE' AND (NEW.usage_period_id, NEW.feature_id) IS DISTINCT FROM (OLD.usage_period_id, OLD.feature_id) THEN
   RAISE EXCEPTION 'usage counter identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 SELECT status INTO period_status FROM commercial.usage_periods WHERE id = NEW.usage_period_id FOR UPDATE;
 IF period_status IS DISTINCT FROM 'open' THEN
   RAISE EXCEPTION 'closed usage period counters are immutable' USING ERRCODE = 'check_violation';
 END IF;
 SELECT usage_mode INTO feature_usage_mode FROM commercial.features WHERE id = NEW.feature_id;
 IF feature_usage_mode IS DISTINCT FROM 'counter' AND feature_usage_mode IS DISTINCT FROM 'gauge' THEN
   RAISE EXCEPTION 'usage counter requires a metered feature' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER usage_counter_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.usage_counters FOR EACH ROW EXECUTE FUNCTION commercial.protect_usage_counter()');

        $this->addSql(<<<'SQL'
INSERT INTO commercial.usage_periods (subscription_id, starts_at, ends_at, status)
SELECT s.id, s.current_period_start, s.current_period_end, 'open'
FROM commercial.subscriptions s
WHERE s.status = 'active' AND s.current_period_start IS NOT NULL AND s.current_period_end IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM commercial.usage_periods up WHERE up.subscription_id = s.id AND up.status = 'open')
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.assert_subscription_usage_period() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_subscription_id uuid; subscription_status text; period_start timestamptz; period_end timestamptz; matching_open integer;
BEGIN
 IF TG_TABLE_NAME = 'subscriptions' THEN
   target_subscription_id := COALESCE(to_jsonb(NEW)->>'id', to_jsonb(OLD)->>'id')::uuid;
 ELSE
   target_subscription_id := COALESCE(to_jsonb(NEW)->>'subscription_id', to_jsonb(OLD)->>'subscription_id')::uuid;
 END IF;
 SELECT status, current_period_start, current_period_end INTO subscription_status, period_start, period_end
 FROM commercial.subscriptions WHERE id = target_subscription_id;
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT count(*) INTO matching_open FROM commercial.usage_periods
 WHERE subscription_id = target_subscription_id AND status = 'open'
   AND starts_at IS NOT DISTINCT FROM period_start AND ends_at IS NOT DISTINCT FROM period_end;
 IF subscription_status = 'active' AND matching_open <> 1 THEN
   RAISE EXCEPTION 'active subscription requires one matching open usage period' USING ERRCODE = 'check_violation';
 END IF;
 IF subscription_status <> 'active' AND EXISTS (SELECT 1 FROM commercial.usage_periods WHERE subscription_id = target_subscription_id AND status = 'open') THEN
   RAISE EXCEPTION 'terminal or draft subscription cannot have an open usage period' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NULL;
END $$
SQL);
        $this->addSql('CREATE CONSTRAINT TRIGGER subscription_usage_period_consistency AFTER INSERT OR UPDATE ON commercial.subscriptions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.assert_subscription_usage_period()');
        $this->addSql('CREATE CONSTRAINT TRIGGER usage_period_subscription_consistency AFTER INSERT OR UPDATE ON commercial.usage_periods DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.assert_subscription_usage_period()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER subscription_usage_period_consistency ON commercial.subscriptions');
        $this->addSql('DROP TRIGGER usage_period_subscription_consistency ON commercial.usage_periods');
        $this->addSql('DROP FUNCTION commercial.assert_subscription_usage_period()');
        $this->addSql('DROP TRIGGER usage_counter_guard ON commercial.usage_counters');
        $this->addSql('DROP FUNCTION commercial.protect_usage_counter()');
        $this->addSql('DROP TABLE commercial.usage_counters');
        $this->addSql('DROP TRIGGER usage_period_guard ON commercial.usage_periods');
        $this->addSql('DROP FUNCTION commercial.protect_usage_period()');
        $this->addSql('DROP TABLE commercial.usage_periods');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_plan_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE plan_state text; feature_type text;
BEGIN
 SELECT status INTO plan_state FROM commercial.plans WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.plan_id ELSE NEW.plan_id END FOR UPDATE;
 IF plan_state <> 'draft' THEN RAISE EXCEPTION 'published plan terms are immutable' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' AND NEW.plan_id <> OLD.plan_id THEN
   SELECT status INTO plan_state FROM commercial.plans WHERE id = OLD.plan_id FOR UPDATE;
   IF plan_state <> 'draft' THEN RAISE EXCEPTION 'published plan terms are immutable' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 IF TG_TABLE_NAME = 'plan_features' AND TG_OP <> 'DELETE' THEN
   SELECT value_type INTO feature_type FROM commercial.features WHERE id = NEW.feature_id;
   IF feature_type = 'boolean' AND jsonb_typeof(NEW.value) <> 'boolean'
      OR feature_type = 'integer' AND (jsonb_typeof(NEW.value) <> 'number' OR NEW.value::text !~ '^-?[0-9]+$')
      OR feature_type = 'string' AND jsonb_typeof(NEW.value) <> 'string'
      OR feature_type = 'json' AND jsonb_typeof(NEW.value) = 'null'
   THEN RAISE EXCEPTION 'feature value type mismatch' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.protect_feature_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.code, NEW.value_type) IS DISTINCT FROM (OLD.code, OLD.value_type) THEN
   RAISE EXCEPTION 'feature identity and type are immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('ALTER TABLE commercial.features DROP CONSTRAINT features_metered_integer_check');
        $this->addSql('ALTER TABLE commercial.features DROP CONSTRAINT features_usage_mode_check');
        $this->addSql('ALTER TABLE commercial.features DROP COLUMN usage_mode');
    }
}
