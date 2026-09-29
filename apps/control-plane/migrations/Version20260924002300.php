<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924002300 extends AbstractMigration
{
    public function getDescription(): string { return 'Non-user privacy subjects and Control Plane incident evidence'; }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_operators (
 control_user_id uuid PRIMARY KEY REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 authorization_reference varchar(500) NOT NULL,
 authorized_at timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_subject_requests (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 subject_kind varchar(32) NOT NULL CHECK (subject_kind IN ('billing_contact','financial_snapshot','other')),
 subject_reference varchar(500) NOT NULL,
 identity_evidence_reference varchar(500) NOT NULL,
 billing_profile_id uuid REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT,
 invoice_id uuid REFERENCES commercial.invoices(id) ON DELETE RESTRICT,
 receipt_id uuid REFERENCES commercial.receipts(id) ON DELETE RESTRICT,
 request_type varchar(32) NOT NULL CHECK (request_type IN ('access','processing_information','correction','erasure')),
 source_channel varchar(32) NOT NULL CHECK (source_channel IN ('paper','email','signed_electronic_document')),
 received_at timestamptz NOT NULL,
 status varchar(32) NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted','in_review','completed','partially_completed','blocked')),
 resolution_code varchar(120),
 response_evidence_reference varchar(500),
 operator_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 completed_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT privacy_subject_binding CHECK (
   (subject_kind = 'billing_contact' AND billing_profile_id IS NOT NULL)
   OR (subject_kind = 'financial_snapshot' AND (invoice_id IS NOT NULL OR receipt_id IS NOT NULL))
   OR subject_kind = 'other'),
 CONSTRAINT privacy_subject_completion CHECK ((completed_at IS NOT NULL) = (status IN ('completed','partially_completed','blocked')))
)
SQL);
        $this->addSql('CREATE INDEX privacy_subject_requests_status_idx ON commercial.privacy_subject_requests(status, received_at)');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_subject_holds (
 id uuid PRIMARY KEY DEFAULT uuidv7(), billing_profile_id uuid NOT NULL REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT,
 reason_code varchar(80) NOT NULL, opened_at timestamptz NOT NULL DEFAULT now(), released_at timestamptz,
 CONSTRAINT privacy_subject_hold_release CHECK (released_at IS NULL OR released_at >= opened_at)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_incidents (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 detected_at timestamptz NOT NULL,
 initial_notification_due_at timestamptz NOT NULL,
 investigation_notification_due_at timestamptz NOT NULL,
 category_codes text[] NOT NULL CHECK (cardinality(category_codes) > 0),
 estimated_subjects integer CHECK (estimated_subjects IS NULL OR estimated_subjects >= 0),
 status varchar(32) NOT NULL DEFAULT 'detected' CHECK (status IN ('detected','contained','investigating','closed')),
 containment_reference varchar(500),
 investigation_reference varchar(500),
 initial_notification_reference varchar(500),
 investigation_notification_reference varchar(500),
 created_by_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT privacy_incident_deadlines CHECK (
  initial_notification_due_at = detected_at + interval '24 hours'
  AND investigation_notification_due_at = detected_at + interval '72 hours')
)
SQL);
        $this->addSql('CREATE INDEX privacy_incidents_due_idx ON commercial.privacy_incidents(initial_notification_due_at, investigation_notification_due_at)');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.privacy_incident_events (
 id uuid PRIMARY KEY DEFAULT uuidv7(), incident_id uuid NOT NULL REFERENCES commercial.privacy_incidents(id) ON DELETE RESTRICT,
 event_code varchar(80) NOT NULL, evidence_reference varchar(500),
 actor_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 occurred_at timestamptz NOT NULL DEFAULT now()
)
SQL);
        $this->addSql('CREATE TRIGGER privacy_incident_event_immutable BEFORE UPDATE OR DELETE ON commercial.privacy_incident_events FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.privacy_incident_events, commercial.privacy_incidents, commercial.privacy_subject_holds, commercial.privacy_subject_requests, commercial.privacy_operators');
    }
}
