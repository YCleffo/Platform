<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031000200 extends AbstractMigration
{
    public function getDescription(): string { return 'Extend audit metadata allowlist for invitations and membership reactivation'; }
    public function up(Schema $schema): void { $this->replace(true); }
    public function down(Schema $schema): void { $this->replace(false); }
    private function replace(bool $expanded): void
    {
        // This migration must not roll back protections that already existed
        // in Version20261001000100. Keep the expanded keys, deployment.updated
        // validation and role allowlist in down() as well; the only additive
        // restriction removed on rollback is the explicit object check.
        $keys = "'role','reason','failure_code','deploymentId','installationId','operationId','sourceReleaseId','targetReleaseId'";
        $roleActions = "'commercial_membership.saved','commercial_membership.role_changed','commercial_membership.reactivated','commercial_invitation.created','commercial_invitation.accepted'";
        $objectCheck = $expanded
            ? " IF jsonb_typeof(NEW.metadata) <> 'object' THEN RAISE EXCEPTION 'audit metadata must be an object' USING ERRCODE='check_violation'; END IF;\n"
            : '';
        $updateCheck = <<<'SQL'
 IF NEW.action = 'deployment.updated' AND (
   (SELECT array_agg(key ORDER BY key) FROM jsonb_object_keys(NEW.metadata) key)
     <> ARRAY['deploymentId','operationId','sourceReleaseId','targetReleaseId']::text[]
   OR EXISTS (SELECT 1 FROM jsonb_each_text(NEW.metadata) e WHERE e.value !~ '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$')
 ) THEN RAISE EXCEPTION 'update audit metadata is invalid' USING ERRCODE='check_violation'; END IF;
SQL;
        $this->addSql(str_replace(['__KEYS__','__ROLE_ACTIONS__','__OBJECT_CHECK__','__UPDATE_CHECK__'], [$keys, $roleActions, $objectCheck, $updateCheck], <<<'SQL'
CREATE OR REPLACE FUNCTION commercial.validate_privacy_audit_metadata() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
__OBJECT_CHECK__
 IF length(NEW.metadata::text)>512 OR EXISTS(SELECT 1 FROM jsonb_object_keys(NEW.metadata) key WHERE key NOT IN (__KEYS__))
 OR EXISTS(SELECT 1 FROM jsonb_each(NEW.metadata) entry WHERE jsonb_typeof(entry.value)<>'string' OR length(entry.value #>> '{}')>80)
 THEN RAISE EXCEPTION 'audit metadata is outside privacy allowlist' USING ERRCODE='check_violation'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_each_text(NEW.metadata) entry WHERE entry.key IN ('deploymentId','installationId','operationId')
   AND (NEW.action NOT IN ('deployment.decommission_requested','deployment.decommissioned','deployment.updated')
     OR entry.value !~ '^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$'))
 THEN RAISE EXCEPTION 'deployment audit metadata is invalid' USING ERRCODE='check_violation'; END IF;
__UPDATE_CHECK__
 IF jsonb_exists(NEW.metadata,'role') AND (NEW.action NOT IN (__ROLE_ACTIONS__) OR NEW.metadata->>'role' NOT IN ('owner','admin','billing','technical')) THEN RAISE EXCEPTION 'audit role metadata is invalid' USING ERRCODE='check_violation'; END IF;
 IF jsonb_exists(NEW.metadata,'reason') AND (NEW.action<>'control_session.login' OR NEW.metadata->>'reason'<>'invalid_credentials') THEN RAISE EXCEPTION 'audit reason metadata is invalid' USING ERRCODE='check_violation'; END IF;
 IF jsonb_exists(NEW.metadata,'failure_code') AND (NEW.action NOT IN ('payment.attempt.failed','refund.canceled') OR NEW.metadata->>'failure_code' !~ '^[a-z0-9]+([._-][a-z0-9]+)*$') THEN RAISE EXCEPTION 'audit failure metadata is invalid' USING ERRCODE='check_violation'; END IF;
 RETURN NEW;
END $$
SQL));
    }
}
