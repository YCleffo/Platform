<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Control Plane password reset, MFA challenges, TOTP factors, recovery codes and session authentication evidence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE commercial.control_sessions ADD COLUMN authentication_method varchar(24) NOT NULL DEFAULT 'password', ADD COLUMN primary_authenticated_at timestamptz NOT NULL DEFAULT now(), ADD COLUMN mfa_authenticated_at timestamptz");
        $this->addSql("ALTER TABLE commercial.control_sessions ADD CONSTRAINT control_sessions_authentication_method_check CHECK (authentication_method IN ('password','password_totp','password_recovery'))");
        $this->addSql("ALTER TABLE commercial.control_sessions ADD CONSTRAINT control_sessions_mfa_evidence_check CHECK ((authentication_method = 'password' AND mfa_authenticated_at IS NULL) OR (authentication_method <> 'password' AND mfa_authenticated_at IS NOT NULL))");
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.control_user_password_resets (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 token_hash bytea NOT NULL UNIQUE,
 expires_at timestamptz NOT NULL,
 consumed_at timestamptz,
 invalidated_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT control_password_reset_hash_check CHECK (octet_length(token_hash) = 32),
 CONSTRAINT control_password_reset_expiry_check CHECK (expires_at > created_at),
 CONSTRAINT control_password_reset_terminal_check CHECK (consumed_at IS NULL OR invalidated_at IS NULL)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX control_password_reset_one_active_idx ON commercial.control_user_password_resets (control_user_id) WHERE consumed_at IS NULL AND invalidated_at IS NULL');
        $this->addSql('CREATE INDEX control_password_reset_expiry_idx ON commercial.control_user_password_resets (expires_at) WHERE consumed_at IS NULL AND invalidated_at IS NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.control_user_totp_factors (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 secret_ciphertext bytea NOT NULL,
 key_version varchar(32) NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'pending',
 last_accepted_counter bigint NOT NULL DEFAULT -1,
 expires_at timestamptz,
 confirmed_at timestamptz,
 revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT control_totp_factor_status_check CHECK (status IN ('pending','active','revoked')),
 CONSTRAINT control_totp_factor_secret_check CHECK (octet_length(secret_ciphertext) > 24),
 CONSTRAINT control_totp_factor_pending_expiry_check CHECK ((status = 'pending' AND expires_at IS NOT NULL AND confirmed_at IS NULL AND revoked_at IS NULL) OR (status = 'active' AND expires_at IS NULL AND confirmed_at IS NOT NULL AND revoked_at IS NULL) OR (status = 'revoked' AND revoked_at IS NOT NULL))
)
SQL);
        $this->addSql("CREATE UNIQUE INDEX control_totp_one_active_idx ON commercial.control_user_totp_factors (control_user_id) WHERE status = 'active'");
        $this->addSql("CREATE INDEX control_totp_pending_expiry_idx ON commercial.control_user_totp_factors (expires_at) WHERE status = 'pending'");
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.control_user_recovery_codes (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 factor_id uuid NOT NULL REFERENCES commercial.control_user_totp_factors(id) ON DELETE RESTRICT,
 code_hash bytea NOT NULL UNIQUE,
 created_at timestamptz NOT NULL DEFAULT now(),
 consumed_at timestamptz,
 revoked_at timestamptz,
 CONSTRAINT control_recovery_code_hash_check CHECK (octet_length(code_hash) = 32),
 CONSTRAINT control_recovery_code_terminal_check CHECK (consumed_at IS NULL OR revoked_at IS NULL)
)
SQL);
        $this->addSql('CREATE INDEX control_recovery_code_lookup_idx ON commercial.control_user_recovery_codes (control_user_id, factor_id) WHERE consumed_at IS NULL AND revoked_at IS NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.control_auth_challenges (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 token_hash bytea NOT NULL UNIQUE,
 kind varchar(24) NOT NULL,
 expires_at timestamptz NOT NULL,
 attempts smallint NOT NULL DEFAULT 0,
 consumed_at timestamptz,
 invalidated_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT control_auth_challenge_hash_check CHECK (octet_length(token_hash) = 32),
 CONSTRAINT control_auth_challenge_kind_check CHECK (kind = 'mfa_login'),
 CONSTRAINT control_auth_challenge_attempts_check CHECK (attempts BETWEEN 0 AND 8),
 CONSTRAINT control_auth_challenge_expiry_check CHECK (expires_at > created_at),
 CONSTRAINT control_auth_challenge_terminal_check CHECK (consumed_at IS NULL OR invalidated_at IS NULL)
)
SQL);
        $this->addSql('CREATE INDEX control_auth_challenge_expiry_idx ON commercial.control_auth_challenges (expires_at) WHERE consumed_at IS NULL AND invalidated_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.control_auth_challenges');
        $this->addSql('DROP TABLE commercial.control_user_recovery_codes');
        $this->addSql('DROP TABLE commercial.control_user_totp_factors');
        $this->addSql('DROP TABLE commercial.control_user_password_resets');
        $this->addSql('ALTER TABLE commercial.control_sessions DROP CONSTRAINT control_sessions_mfa_evidence_check, DROP CONSTRAINT control_sessions_authentication_method_check, DROP COLUMN mfa_authenticated_at, DROP COLUMN primary_authenticated_at, DROP COLUMN authentication_method');
    }
}
