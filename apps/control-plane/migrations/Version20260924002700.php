<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924002700 extends AbstractMigration
{
    public function getDescription(): string { return 'Allow only deployment decommission UUID audit metadata'; }

    public function up(Schema $schema): void
    {
        $this->replace(true);
    }

    public function down(Schema $schema): void
    {
        $this->replace(false);
    }

    private function replace(bool $decommission): void
    {
        $extra = $decommission ? <<<'SQL'
 IF EXISTS (
   SELECT 1 FROM jsonb_each_text(NEW.metadata) AS entry
   WHERE entry.key IN ('deploymentId','installationId','operationId')
     AND (NEW.action NOT IN ('deployment.decommission_requested','deployment.decommissioned')
       OR entry.value !~ '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$')
 ) THEN
   RAISE EXCEPTION 'decommission audit metadata is invalid' USING ERRCODE = 'check_violation';
 END IF;
SQL : '';
        $keys = $decommission
            ? "'role', 'reason', 'failure_code', 'deploymentId', 'installationId', 'operationId'"
            : "'role', 'reason', 'failure_code'";
        $sql = <<<'SQL'
CREATE OR REPLACE FUNCTION commercial.validate_privacy_audit_metadata() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF length(NEW.metadata::text) > 512 OR EXISTS (
   SELECT 1 FROM jsonb_object_keys(NEW.metadata) AS key
   WHERE key NOT IN (__KEYS__)
 ) OR EXISTS (
   SELECT 1 FROM jsonb_each(NEW.metadata) AS entry
   WHERE jsonb_typeof(entry.value) <> 'string' OR length(entry.value #>> '{}') > 80
 ) THEN
   RAISE EXCEPTION 'audit metadata is outside privacy allowlist' USING ERRCODE = 'check_violation';
 END IF;
__EXTRA__
 IF jsonb_exists(NEW.metadata, 'role') AND (NEW.action NOT IN ('commercial_membership.saved', 'commercial_membership.role_changed') OR NEW.metadata->>'role' NOT IN ('owner','admin','billing','technical')) THEN
   RAISE EXCEPTION 'audit role metadata is invalid' USING ERRCODE = 'check_violation';
 END IF;
 IF jsonb_exists(NEW.metadata, 'reason') AND (NEW.action <> 'control_session.login' OR NEW.metadata->>'reason' <> 'invalid_credentials') THEN
   RAISE EXCEPTION 'audit reason metadata is invalid' USING ERRCODE = 'check_violation';
 END IF;
 IF jsonb_exists(NEW.metadata, 'failure_code') AND (NEW.action NOT IN ('payment.attempt.failed','refund.canceled') OR NEW.metadata->>'failure_code' !~ '^[a-z0-9]+([._-][a-z0-9]+)*$') THEN
   RAISE EXCEPTION 'audit failure metadata is invalid' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL;
        $this->addSql(str_replace(['__KEYS__', '__EXTRA__'], [$keys, $extra], $sql));
    }
}
