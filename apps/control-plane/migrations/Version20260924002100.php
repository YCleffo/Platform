<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924002100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Control Plane privacy lifecycle, safe audit metadata and email outbox scrubbing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.validate_privacy_audit_metadata() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF length(NEW.metadata::text) > 512 OR EXISTS (
   SELECT 1 FROM jsonb_object_keys(NEW.metadata) AS key
   WHERE key NOT IN ('role', 'reason', 'failure_code')
 ) OR EXISTS (
   SELECT 1 FROM jsonb_each(NEW.metadata) AS entry
   WHERE jsonb_typeof(entry.value) <> 'string' OR length(entry.value #>> '{}') > 80
 ) THEN
   RAISE EXCEPTION 'audit metadata is outside privacy allowlist' USING ERRCODE = 'check_violation';
 END IF;
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
SQL);
        $this->addSql('CREATE TRIGGER audit_privacy_metadata BEFORE INSERT ON commercial.audit_events FOR EACH ROW EXECUTE FUNCTION commercial.validate_privacy_audit_metadata()');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_control_user_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> OLD.status AND NOT (
   (OLD.status = 'pending_verification' AND NEW.status IN ('active','disabled')) OR
   (OLD.status = 'active' AND NEW.status IN ('suspended','disabled')) OR
   (OLD.status = 'suspended' AND NEW.status IN ('active','disabled'))
 ) THEN RAISE EXCEPTION 'invalid control user status transition' USING ERRCODE = 'check_violation'; END IF;
 IF NEW.email_normalized <> OLD.email_normalized AND OLD.email_verified_at IS NOT NULL
    AND NOT (current_setting('app.privacy_lifecycle', true) = 'on'
      AND NEW.status = 'disabled' AND NEW.email_normalized = 'erased-' || NEW.id::text || '@privacy.invalid') THEN
   RAISE EXCEPTION 'verified email identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_holds (
 id uuid PRIMARY KEY DEFAULT uuidv7(), control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 reason_code varchar(80) NOT NULL, opened_at timestamptz NOT NULL DEFAULT now(), released_at timestamptz,
 CONSTRAINT privacy_hold_reason_check CHECK (reason_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT privacy_hold_release_check CHECK (released_at IS NULL OR released_at >= opened_at)
)
SQL);
        $this->addSql('CREATE INDEX privacy_holds_active_user_idx ON commercial.privacy_holds (control_user_id) WHERE released_at IS NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_lifecycle_runs (
 id uuid PRIMARY KEY DEFAULT uuidv7(), category varchar(80) NOT NULL, dry_run boolean NOT NULL,
 selected_count integer NOT NULL, deleted_count integer NOT NULL, executed_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT privacy_lifecycle_counts_check CHECK (selected_count >= 0 AND deleted_count >= 0 AND deleted_count <= selected_count)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_erasure_cases (
 id uuid PRIMARY KEY DEFAULT uuidv7(), control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 status varchar(32) NOT NULL, reason_code varchar(80) NOT NULL,
 financial_evidence_count integer NOT NULL DEFAULT 0, created_at timestamptz NOT NULL DEFAULT now(),
 completed_at timestamptz, CONSTRAINT privacy_erasure_status_check CHECK (status IN ('blocked','partially_completed','completed')),
 CONSTRAINT privacy_erasure_count_check CHECK (financial_evidence_count >= 0)
)
SQL);
        $this->addSql('CREATE TRIGGER privacy_erasure_immutable BEFORE UPDATE OR DELETE ON commercial.privacy_erasure_cases FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_export_events (
 id uuid PRIMARY KEY DEFAULT uuidv7(), control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 actor_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 schema_version smallint NOT NULL DEFAULT 1, item_count integer NOT NULL,
 exported_at timestamptz NOT NULL DEFAULT now(), CONSTRAINT privacy_export_count_check CHECK (item_count >= 0)
)
SQL);
        $this->addSql('CREATE TRIGGER privacy_export_immutable BEFORE UPDATE OR DELETE ON commercial.privacy_export_events FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.guard_published_outbox() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' AND OLD.status = 'published' AND current_setting('app.privacy_lifecycle', true) IS DISTINCT FROM 'on' THEN
   RAISE EXCEPTION 'published outbox event is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF TG_OP = 'UPDATE' AND OLD.status = 'published' AND NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'published outbox event is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$
SQL);
        $this->addSql('DROP TRIGGER outbox_published_guard ON commercial.outbox_events');
        $this->addSql(<<<'SQL'
UPDATE commercial.outbox_events
SET payload = CASE WHEN status = 'published' THEN '{}'::jsonb ELSE payload - 'recipient' END
WHERE event_type = 'control_user.email_verification_requested' AND jsonb_exists(payload, 'recipient')
SQL);
        $this->addSql('CREATE TRIGGER outbox_published_guard BEFORE UPDATE OR DELETE ON commercial.outbox_events FOR EACH ROW EXECUTE FUNCTION commercial.guard_published_outbox()');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.guard_privacy_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' AND current_setting('app.privacy_lifecycle', true) = 'on' THEN RETURN OLD; END IF;
 RAISE EXCEPTION 'audit event is append only' USING ERRCODE = 'check_violation';
END $$
SQL);
        $this->addSql('DROP TRIGGER audit_append_only ON commercial.audit_events');
        $this->addSql('CREATE TRIGGER audit_append_only BEFORE UPDATE OR DELETE ON commercial.audit_events FOR EACH ROW EXECUTE FUNCTION commercial.guard_privacy_audit_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.enforce_control_user_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> OLD.status AND NOT (
   (OLD.status = 'pending_verification' AND NEW.status IN ('active','disabled')) OR
   (OLD.status = 'active' AND NEW.status IN ('suspended','disabled')) OR
   (OLD.status = 'suspended' AND NEW.status IN ('active','disabled'))
 ) THEN RAISE EXCEPTION 'invalid control user status transition' USING ERRCODE = 'check_violation'; END IF;
 IF NEW.email_normalized <> OLD.email_normalized AND OLD.email_verified_at IS NOT NULL THEN
   RAISE EXCEPTION 'verified email identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('DROP TRIGGER audit_append_only ON commercial.audit_events');
        $this->addSql('CREATE TRIGGER audit_append_only BEFORE UPDATE OR DELETE ON commercial.audit_events FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
        $this->addSql('DROP FUNCTION commercial.guard_privacy_audit_mutation()');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION commercial.guard_published_outbox() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' AND OLD.status = 'published' THEN RAISE EXCEPTION 'published outbox event is immutable' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' AND OLD.status = 'published' AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'published outbox event is immutable' USING ERRCODE = 'check_violation'; END IF;
 RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$
SQL);
        $this->addSql('DROP TABLE IF EXISTS commercial.privacy_export_events');
        $this->addSql('DROP TABLE commercial.privacy_erasure_cases, commercial.privacy_lifecycle_runs, commercial.privacy_holds');
        $this->addSql('DROP TRIGGER audit_privacy_metadata ON commercial.audit_events');
        $this->addSql('DROP FUNCTION commercial.validate_privacy_audit_metadata()');
    }
}
