<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.7 YooKassa webhook inbox';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.payment_provider_events (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 provider_account_id uuid NOT NULL REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT,
 event_key varchar(400) NOT NULL,
 event_type varchar(160) NOT NULL,
 provider_object_id varchar(200) NOT NULL,
 payload_sha256 bytea NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'received',
 received_at timestamptz NOT NULL DEFAULT now(),
 processed_at timestamptz,
 last_error_code varchar(120),
 CONSTRAINT payment_provider_events_key_check CHECK (btrim(event_key) <> '' AND event_key !~ '[[:cntrl:]]'),
 CONSTRAINT payment_provider_events_type_check CHECK (event_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT payment_provider_events_object_check CHECK (btrim(provider_object_id) <> '' AND provider_object_id !~ '[[:cntrl:]]'),
 CONSTRAINT payment_provider_events_hash_check CHECK (octet_length(payload_sha256) = 32),
 CONSTRAINT payment_provider_events_status_check CHECK (status IN ('received','processed','ignored','failed')),
 CONSTRAINT payment_provider_events_timestamps_check CHECK (
   (status IN ('received','failed') AND processed_at IS NULL) OR
   (status IN ('processed','ignored') AND processed_at IS NOT NULL)
 ),
 CONSTRAINT payment_provider_events_error_check CHECK (
   (status = 'failed' AND last_error_code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$') OR
   (status <> 'failed' AND last_error_code IS NULL)
 ),
 UNIQUE (provider_account_id, event_key)
)
SQL);
        $this->addSql('CREATE INDEX payment_provider_events_status_received_idx ON commercial.payment_provider_events (status, received_at)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_payment_provider_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
   RAISE EXCEPTION 'payment provider events are historical and cannot be deleted' USING ERRCODE = 'check_violation';
 END IF;
 IF (NEW.provider_account_id, NEW.event_key, NEW.event_type, NEW.provider_object_id, NEW.payload_sha256, NEW.received_at)
    IS DISTINCT FROM (OLD.provider_account_id, OLD.event_key, OLD.event_type, OLD.provider_object_id, OLD.payload_sha256, OLD.received_at) THEN
   RAISE EXCEPTION 'payment provider event identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF OLD.status IN ('processed','ignored') AND NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'terminal payment provider event is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF NEW.status <> OLD.status AND NOT (
   (OLD.status = 'received' AND NEW.status IN ('processed','ignored','failed')) OR
   (OLD.status = 'failed' AND NEW.status IN ('processed','ignored'))
 ) THEN
   RAISE EXCEPTION 'invalid payment provider event transition' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER payment_provider_event_guard BEFORE UPDATE OR DELETE ON commercial.payment_provider_events FOR EACH ROW EXECUTE FUNCTION commercial.protect_payment_provider_event()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER payment_provider_event_guard ON commercial.payment_provider_events');
        $this->addSql('DROP FUNCTION commercial.protect_payment_provider_event()');
        $this->addSql('DROP TABLE commercial.payment_provider_events');
    }
}
