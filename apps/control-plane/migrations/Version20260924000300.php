<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Typed versioned catalog with immutable published terms';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.features (
 id uuid PRIMARY KEY DEFAULT uuidv7(), code varchar(160) NOT NULL UNIQUE,
 value_type varchar(16) NOT NULL, description text, created_at timestamptz NOT NULL DEFAULT now(), retired_at timestamptz,
 CONSTRAINT features_code_check CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT features_type_check CHECK (value_type IN ('boolean','integer','string','json')),
 CONSTRAINT features_description_check CHECK (description IS NULL OR (btrim(description) <> '' AND length(description) <= 2000 AND description !~ '[[:cntrl:]]'))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_feature_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.code, NEW.value_type) IS DISTINCT FROM (OLD.code, OLD.value_type) THEN
   RAISE EXCEPTION 'feature identity and type are immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER feature_identity_guard BEFORE UPDATE ON commercial.features FOR EACH ROW EXECUTE FUNCTION commercial.protect_feature_identity()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.plans (
 id uuid PRIMARY KEY DEFAULT uuidv7(), code varchar(120) NOT NULL, version integer NOT NULL,
 status varchar(24) NOT NULL DEFAULT 'draft', name varchar(200) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), published_at timestamptz, retired_at timestamptz,
 CONSTRAINT plans_code_check CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT plans_version_check CHECK (version > 0),
 CONSTRAINT plans_status_check CHECK (status IN ('draft','published','retired')),
 CONSTRAINT plans_name_check CHECK (btrim(name) <> '' AND name !~ '[[:cntrl:]]'),
 CONSTRAINT plans_published_check CHECK ((status = 'draft' AND published_at IS NULL AND retired_at IS NULL) OR (status = 'published' AND published_at IS NOT NULL AND retired_at IS NULL) OR (status = 'retired' AND published_at IS NOT NULL AND retired_at IS NOT NULL)),
 UNIQUE (code, version)
)
SQL);
        $this->addSql('CREATE TRIGGER plans_immutable BEFORE UPDATE OR DELETE ON commercial.plans FOR EACH ROW EXECUTE FUNCTION commercial.prevent_published_plan_mutation()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.plan_prices (
 id uuid PRIMARY KEY DEFAULT uuidv7(), plan_id uuid NOT NULL REFERENCES commercial.plans(id) ON DELETE RESTRICT,
 currency char(3) NOT NULL, amount_minor bigint NOT NULL, billing_period varchar(24) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT plan_prices_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
 CONSTRAINT plan_prices_amount_check CHECK (amount_minor >= 0),
 CONSTRAINT plan_prices_period_check CHECK (billing_period IN ('month','year','one_time')),
 UNIQUE (plan_id, currency, billing_period)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.plan_features (
 plan_id uuid NOT NULL REFERENCES commercial.plans(id) ON DELETE RESTRICT,
 feature_id uuid NOT NULL REFERENCES commercial.features(id) ON DELETE RESTRICT,
 value jsonb NOT NULL, PRIMARY KEY (plan_id, feature_id)
)
SQL);
        $this->addSql('CREATE INDEX plan_features_feature_idx ON commercial.plan_features (feature_id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_plan_child() RETURNS trigger LANGUAGE plpgsql AS $$
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
        $this->addSql('CREATE TRIGGER plan_prices_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.plan_prices FOR EACH ROW EXECUTE FUNCTION commercial.enforce_plan_child()');
        $this->addSql('CREATE TRIGGER plan_features_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.plan_features FOR EACH ROW EXECUTE FUNCTION commercial.enforce_plan_child()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.plan_features, commercial.plan_prices, commercial.plans, commercial.features');
        $this->addSql('DROP FUNCTION commercial.enforce_plan_child()');
        $this->addSql('DROP FUNCTION commercial.protect_feature_identity()');
    }
}
