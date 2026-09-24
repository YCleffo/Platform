<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deployment reservations, canonical domains and durable operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.deployments (
 id uuid PRIMARY KEY DEFAULT uuidv7(), commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 installation_id uuid NOT NULL UNIQUE, mode varchar(16) NOT NULL, system_slug varchar(63) UNIQUE,
 status varchar(24) NOT NULL DEFAULT 'reserved', provisioning_state varchar(24) NOT NULL DEFAULT 'pending',
 current_release_id uuid REFERENCES commercial.releases(id) ON DELETE RESTRICT,
 desired_release_id uuid REFERENCES commercial.releases(id) ON DELETE RESTRICT,
 core_db_secret_ref varchar(300), created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(), archived_at timestamptz,
 CONSTRAINT deployment_mode_check CHECK (mode IN ('shared','dedicated','on_prem')),
 CONSTRAINT deployment_slug_check CHECK (system_slug IS NULL OR (system_slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND system_slug NOT IN ('www','api','admin','app','auth','billing','control','control-plane','support','status','cdn','storage','static','mail','smtp','root','system','platform'))),
 CONSTRAINT deployment_cloud_slug_check CHECK ((mode = 'on_prem') = (system_slug IS NULL)),
 CONSTRAINT deployment_status_check CHECK (status IN ('reserved','provisioning','active','suspended','failed','decommissioned')),
 CONSTRAINT deployment_provision_state_check CHECK (provisioning_state IN ('pending','running','ready','failed','not_applicable')),
 CONSTRAINT deployment_secret_ref_check CHECK (core_db_secret_ref IS NULL OR core_db_secret_ref ~ '^(vault|kms|hsm)://[^[:space:]]+$')
)
SQL);
        $this->addSql('CREATE INDEX deployments_account_idx ON commercial.deployments (commercial_account_id)');
        $this->addSql('CREATE TRIGGER deployment_no_delete BEFORE DELETE ON commercial.deployments FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_cloud_system_domain() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE domain_count integer;
BEGIN
 IF NEW.mode <> 'on_prem' THEN
   SELECT count(*) INTO domain_count FROM commercial.deployment_domains WHERE deployment_id = NEW.id AND kind = 'system';
   IF domain_count <> 1 THEN RAISE EXCEPTION 'cloud deployment requires one system domain' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.deployment_domains (
 id uuid PRIMARY KEY DEFAULT uuidv7(), deployment_id uuid NOT NULL REFERENCES commercial.deployments(id) ON DELETE RESTRICT,
 kind varchar(16) NOT NULL, hostname varchar(253) NOT NULL UNIQUE,
 status varchar(24) NOT NULL DEFAULT 'pending', ownership_verified_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), disabled_at timestamptz,
 CONSTRAINT domain_kind_check CHECK (kind IN ('system','custom')),
 CONSTRAINT domain_hostname_check CHECK (hostname = lower(hostname) AND hostname ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$' AND hostname !~ '(^|\.)[a-z0-9-]{64,}(\.|$)' AND hostname !~ '(^|\.)(localhost|local|internal|invalid|test)(\.|$)'),
 CONSTRAINT domain_status_check CHECK (status IN ('pending','verified','active','disabled')),
 CONSTRAINT domain_custom_verification_check CHECK (kind = 'system' OR status NOT IN ('verified','active') OR ownership_verified_at IS NOT NULL)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX deployment_system_domain_uidx ON commercial.deployment_domains (deployment_id) WHERE kind = \'system\'');
        $this->addSql('CREATE CONSTRAINT TRIGGER cloud_system_domain_required AFTER INSERT OR UPDATE ON commercial.deployments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commercial.enforce_cloud_system_domain()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_system_domain() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE deployment_mode text; deployment_slug text;
BEGIN
 IF TG_OP <> 'DELETE' AND NEW.kind = 'system' THEN
   SELECT mode, system_slug INTO deployment_mode, deployment_slug FROM commercial.deployments WHERE id = NEW.deployment_id;
   IF deployment_mode = 'on_prem' OR NEW.hostname NOT LIKE deployment_slug || '.%' THEN
     RAISE EXCEPTION 'invalid system domain for deployment' USING ERRCODE = 'check_violation';
   END IF;
 END IF;
 IF TG_OP = 'DELETE' AND OLD.kind = 'system' THEN RAISE EXCEPTION 'system domain is immutable' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' AND OLD.kind = 'system' AND (NEW.kind, NEW.hostname, NEW.deployment_id) IS DISTINCT FROM (OLD.kind, OLD.hostname, OLD.deployment_id) THEN
   RAISE EXCEPTION 'system domain identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER system_domain_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.deployment_domains FOR EACH ROW EXECUTE FUNCTION commercial.protect_system_domain()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.deployment_operations (
 id uuid PRIMARY KEY DEFAULT uuidv7(), deployment_id uuid NOT NULL REFERENCES commercial.deployments(id) ON DELETE RESTRICT,
 operation_type varchar(32) NOT NULL, idempotency_key varchar(200) NOT NULL,
 status varchar(24) NOT NULL DEFAULT 'pending', current_step varchar(120), attempt_count integer NOT NULL DEFAULT 0,
 next_retry_at timestamptz, last_error_code varchar(120), started_at timestamptz, finished_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT deployment_operation_type_check CHECK (operation_type IN ('provision','update','deprovision','domain_attach','backup','restore')),
 CONSTRAINT deployment_operation_status_check CHECK (status IN ('pending','running','retry','succeeded','failed')),
 CONSTRAINT deployment_operation_attempts_check CHECK (attempt_count >= 0),
 CONSTRAINT deployment_operation_key_check CHECK (idempotency_key ~ '^[!-~]+$'),
 CONSTRAINT deployment_operation_error_check CHECK (last_error_code IS NULL OR last_error_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 UNIQUE (deployment_id, idempotency_key)
)
SQL);
        $this->addSql('CREATE INDEX deployment_operations_retry_idx ON commercial.deployment_operations (next_retry_at) WHERE status = \'retry\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.deployment_operations, commercial.deployment_domains, commercial.deployments');
        $this->addSql('DROP FUNCTION commercial.protect_system_domain()');
        $this->addSql('DROP FUNCTION commercial.enforce_cloud_system_domain()');
    }
}
