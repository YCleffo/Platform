<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require two authorized operators and immutable evidence for external plan-change compensation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.financial_operators (
 control_user_id uuid PRIMARY KEY REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 authorization_reference varchar(500) NOT NULL CHECK (authorization_reference ~ '^urn:.+'),
 authorized_at timestamptz NOT NULL DEFAULT now(),
 revoked_at timestamptz,
 CONSTRAINT financial_operator_revocation_check CHECK (revoked_at IS NULL OR revoked_at >= authorized_at)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.external_compensation_attestations (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 plan_change_id uuid NOT NULL REFERENCES commercial.subscription_plan_changes(id) ON DELETE RESTRICT,
 payment_id uuid NOT NULL REFERENCES commercial.payments(id) ON DELETE RESTRICT,
 amount_minor bigint NOT NULL CHECK (amount_minor > 0),
 currency char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
 external_transaction_reference varchar(200) NOT NULL CHECK (length(btrim(external_transaction_reference)) > 0),
 document_reference varchar(500) NOT NULL CHECK (document_reference ~ '^urn:.+'),
 document_sha256 char(64) NOT NULL CHECK (document_sha256 ~ '^[0-9a-f]{64}$'),
 proposed_by uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 proposed_at timestamptz NOT NULL DEFAULT now(),
 status varchar(16) NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed','approved','rejected')),
 reviewed_by uuid REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 reviewed_at timestamptz,
 rejection_reason varchar(500),
 CONSTRAINT external_compensation_review_check CHECK (
  (status='proposed' AND reviewed_by IS NULL AND reviewed_at IS NULL AND rejection_reason IS NULL) OR
  (status='approved' AND reviewed_by IS NOT NULL AND reviewed_by<>proposed_by AND reviewed_at IS NOT NULL AND rejection_reason IS NULL) OR
  (status='rejected' AND reviewed_by IS NOT NULL AND reviewed_by<>proposed_by AND reviewed_at IS NOT NULL AND rejection_reason IS NOT NULL)
 )
)
SQL);
        $this->addSql("CREATE UNIQUE INDEX external_compensation_open_change_idx ON commercial.external_compensation_attestations(plan_change_id) WHERE status IN ('proposed','approved')");
        $this->addSql("CREATE UNIQUE INDEX external_compensation_approved_transfer_idx ON commercial.external_compensation_attestations(external_transaction_reference) WHERE status='approved'");
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_external_compensation_attestation_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'external compensation evidence is append-only' USING ERRCODE='check_violation';
 END IF;
 IF OLD.status <> 'proposed' OR NEW.status NOT IN ('approved','rejected')
  OR NEW.id IS DISTINCT FROM OLD.id OR NEW.plan_change_id IS DISTINCT FROM OLD.plan_change_id
  OR NEW.payment_id IS DISTINCT FROM OLD.payment_id OR NEW.amount_minor IS DISTINCT FROM OLD.amount_minor
  OR NEW.currency IS DISTINCT FROM OLD.currency
  OR NEW.external_transaction_reference IS DISTINCT FROM OLD.external_transaction_reference
  OR NEW.document_reference IS DISTINCT FROM OLD.document_reference
  OR NEW.document_sha256 IS DISTINCT FROM OLD.document_sha256
  OR NEW.proposed_by IS DISTINCT FROM OLD.proposed_by OR NEW.proposed_at IS DISTINCT FROM OLD.proposed_at THEN
  RAISE EXCEPTION 'external compensation evidence cannot be rewritten' USING ERRCODE='check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER external_compensation_attestation_transition BEFORE UPDATE OR DELETE ON commercial.external_compensation_attestations FOR EACH ROW EXECUTE FUNCTION commercial.enforce_external_compensation_attestation_transition()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM commercial.external_compensation_attestations)
  OR EXISTS (SELECT 1 FROM commercial.financial_operators) THEN
  RAISE EXCEPTION 'external compensation history must be retained' USING ERRCODE='check_violation';
 END IF;
END $$
SQL);
        $this->addSql('DROP TABLE commercial.external_compensation_attestations');
        $this->addSql('DROP FUNCTION commercial.enforce_external_compensation_attestation_transition()');
        $this->addSql('DROP TABLE commercial.financial_operators');
    }
}
