<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 rotating server-side sessions and bounded database rate limits';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.control_sessions (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 token_family_id uuid NOT NULL DEFAULT uuidv7(),
 access_token_hash bytea NOT NULL UNIQUE,
 refresh_token_hash bytea NOT NULL UNIQUE,
 previous_refresh_token_hash bytea,
 generation integer NOT NULL DEFAULT 0,
 issued_at timestamptz NOT NULL DEFAULT now(),
 access_expires_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,
 last_used_at timestamptz,
 rotated_at timestamptz,
 revoked_at timestamptz,
 revoke_reason varchar(80),
 ip_hash bytea,
 user_agent varchar(300),
 CONSTRAINT control_session_access_hash_check CHECK (octet_length(access_token_hash) = 32),
 CONSTRAINT control_session_refresh_hash_check CHECK (octet_length(refresh_token_hash) = 32),
 CONSTRAINT control_session_previous_hash_check CHECK (previous_refresh_token_hash IS NULL OR octet_length(previous_refresh_token_hash) = 32),
 CONSTRAINT control_session_generation_check CHECK (generation >= 0),
 CONSTRAINT control_session_expiry_check CHECK (access_expires_at > issued_at AND expires_at > issued_at AND access_expires_at <= expires_at),
 CONSTRAINT control_session_ip_hash_check CHECK (ip_hash IS NULL OR octet_length(ip_hash) = 32),
 CONSTRAINT control_session_revoke_check CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL))
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX control_sessions_previous_refresh_uidx ON commercial.control_sessions (previous_refresh_token_hash) WHERE previous_refresh_token_hash IS NOT NULL');
        $this->addSql('CREATE INDEX control_sessions_user_idx ON commercial.control_sessions (control_user_id, expires_at DESC)');
        $this->addSql('CREATE INDEX control_sessions_cleanup_idx ON commercial.control_sessions (expires_at)');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.control_session_refresh_history (
 token_hash bytea PRIMARY KEY,
 control_session_id uuid NOT NULL REFERENCES commercial.control_sessions(id) ON DELETE CASCADE,
 consumed_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT control_session_history_hash_check CHECK (octet_length(token_hash) = 32)
)
SQL);
        $this->addSql('CREATE INDEX control_session_refresh_history_session_idx ON commercial.control_session_refresh_history (control_session_id, consumed_at DESC)');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.rate_limit_counters (
 scope varchar(80) NOT NULL,
 key_hash bytea NOT NULL,
 window_started_at timestamptz NOT NULL,
 hits integer NOT NULL DEFAULT 1,
 expires_at timestamptz NOT NULL,
 PRIMARY KEY (scope, key_hash, window_started_at),
 CONSTRAINT rate_limit_scope_check CHECK (scope ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT rate_limit_hash_check CHECK (octet_length(key_hash) = 32),
 CONSTRAINT rate_limit_hits_check CHECK (hits > 0),
 CONSTRAINT rate_limit_expiry_check CHECK (expires_at > window_started_at)
)
SQL);
        $this->addSql('CREATE INDEX rate_limit_cleanup_idx ON commercial.rate_limit_counters (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.rate_limit_counters, commercial.control_session_refresh_history, commercial.control_sessions');
    }
}
