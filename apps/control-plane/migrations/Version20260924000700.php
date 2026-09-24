<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Idempotency ledger, append-only audit and transactional outbox';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.idempotency_keys (
 id uuid PRIMARY KEY DEFAULT uuidv7(), scope varchar(120) NOT NULL, idempotency_key varchar(200) NOT NULL,
 request_sha256 bytea NOT NULL, status varchar(24) NOT NULL, result_reference varchar(200),
 created_at timestamptz NOT NULL DEFAULT now(), expires_at timestamptz,
 CONSTRAINT idempotency_scope_check CHECK (scope ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT idempotency_key_check CHECK (idempotency_key ~ '^[!-~]+$'),
 CONSTRAINT idempotency_hash_check CHECK (octet_length(request_sha256) = 32),
 CONSTRAINT idempotency_status_check CHECK (status IN ('pending','completed','failed')),
 CONSTRAINT idempotency_expiry_check CHECK (expires_at IS NULL OR expires_at > created_at),
 UNIQUE (scope, idempotency_key)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.audit_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, event_id uuid NOT NULL UNIQUE DEFAULT uuidv7(),
 occurred_at timestamptz NOT NULL DEFAULT now(), actor_type varchar(24) NOT NULL, actor_id uuid,
 commercial_account_id uuid REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 action varchar(160) NOT NULL, resource_type varchar(120) NOT NULL, resource_id varchar(160) NOT NULL,
 correlation_id uuid, outcome varchar(24) NOT NULL, metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
 CONSTRAINT audit_actor_check CHECK (actor_type IN ('control_user','system')),
 CONSTRAINT audit_action_check CHECK (action ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT audit_resource_type_check CHECK (resource_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT audit_outcome_check CHECK (outcome IN ('success','failure','denied')),
 CONSTRAINT audit_metadata_check CHECK (jsonb_typeof(metadata) = 'object')
)
SQL);
        $this->addSql('CREATE INDEX audit_events_account_time_idx ON commercial.audit_events (commercial_account_id, occurred_at DESC)');
        $this->addSql('CREATE TRIGGER audit_append_only BEFORE UPDATE OR DELETE ON commercial.audit_events FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.outbox_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, event_id uuid NOT NULL UNIQUE DEFAULT uuidv7(),
 event_type varchar(180) NOT NULL, aggregate_type varchar(120) NOT NULL, aggregate_id uuid NOT NULL,
 payload jsonb NOT NULL, status varchar(24) NOT NULL DEFAULT 'pending', attempts integer NOT NULL DEFAULT 0,
 available_at timestamptz NOT NULL DEFAULT now(), created_at timestamptz NOT NULL DEFAULT now(), published_at timestamptz,
 CONSTRAINT outbox_event_type_check CHECK (event_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT outbox_aggregate_check CHECK (aggregate_type ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT outbox_payload_check CHECK (jsonb_typeof(payload) = 'object'),
 CONSTRAINT outbox_status_check CHECK (status IN ('pending','processing','published','failed')),
 CONSTRAINT outbox_attempts_check CHECK (attempts >= 0)
)
SQL);
        $this->addSql('CREATE INDEX outbox_available_idx ON commercial.outbox_events (available_at, id) WHERE status IN (\'pending\',\'failed\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.outbox_events, commercial.audit_events, commercial.idempotency_keys');
    }
}
