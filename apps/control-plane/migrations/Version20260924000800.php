<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 central Control Plane identity and one-time email verification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.control_users DROP CONSTRAINT control_users_email_key');
        $this->addSql('ALTER TABLE commercial.control_users DROP CONSTRAINT control_users_email_check');
        $this->addSql('ALTER TABLE commercial.control_users DROP CONSTRAINT control_users_status_check');
        $this->addSql(<<<'SQL'
ALTER TABLE commercial.control_users
 ADD COLUMN email_normalized varchar(320),
 ADD COLUMN password_hash varchar(255),
 ADD COLUMN email_verified_at timestamptz,
 ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now(),
 ADD COLUMN last_login_at timestamptz,
 ADD COLUMN password_changed_at timestamptz
SQL);
        $this->addSql("UPDATE commercial.control_users SET email = btrim(email), email_normalized = lower(btrim(email)), password_hash = '!legacy-disabled!', status = 'disabled', password_changed_at = now()");
        $this->addSql('ALTER TABLE commercial.control_users ALTER COLUMN email_normalized SET NOT NULL');
        $this->addSql('ALTER TABLE commercial.control_users ALTER COLUMN password_hash SET NOT NULL');
        $this->addSql('ALTER TABLE commercial.control_users ALTER COLUMN status SET DEFAULT \'pending_verification\'');
        $this->addSql('ALTER TABLE commercial.control_users ADD CONSTRAINT control_users_email_normalized_key UNIQUE (email_normalized)');
        $this->addSql(<<<'SQL'
ALTER TABLE commercial.control_users
 ADD CONSTRAINT control_users_email_check CHECK (
   email = btrim(email) AND length(email) BETWEEN 3 AND 320 AND email !~ '[[:cntrl:]]'
 ),
 ADD CONSTRAINT control_users_email_normalized_check CHECK (
   email_normalized = lower(btrim(email_normalized)) AND length(email_normalized) BETWEEN 3 AND 320
   AND email_normalized = lower(email) AND email_normalized !~ '[[:cntrl:]]' AND position('@' in email_normalized) > 1
 ),
 ADD CONSTRAINT control_users_status_check CHECK (status IN ('pending_verification','active','suspended','disabled')),
 ADD CONSTRAINT control_users_verification_check CHECK (
   (status = 'pending_verification' AND email_verified_at IS NULL) OR
   (status IN ('active','suspended') AND email_verified_at IS NOT NULL) OR
   status = 'disabled'
 )
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_control_user_transition() RETURNS trigger LANGUAGE plpgsql AS $$
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
        $this->addSql('CREATE TRIGGER control_user_transition BEFORE UPDATE ON commercial.control_users FOR EACH ROW EXECUTE FUNCTION commercial.enforce_control_user_transition()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.control_user_email_verifications (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 token_hash bytea NOT NULL UNIQUE,
 expires_at timestamptz NOT NULL,
 consumed_at timestamptz,
 invalidated_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT email_verification_hash_check CHECK (octet_length(token_hash) = 32),
 CONSTRAINT email_verification_expiry_check CHECK (expires_at > created_at),
 CONSTRAINT email_verification_terminal_check CHECK (consumed_at IS NULL OR invalidated_at IS NULL)
)
SQL);
        $this->addSql('CREATE INDEX email_verifications_user_idx ON commercial.control_user_email_verifications (control_user_id, created_at DESC)');
        $this->addSql('CREATE INDEX email_verifications_expiry_idx ON commercial.control_user_email_verifications (expires_at) WHERE consumed_at IS NULL AND invalidated_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.control_user_email_verifications');
        $this->addSql('DROP TRIGGER control_user_transition ON commercial.control_users');
        $this->addSql('DROP FUNCTION commercial.enforce_control_user_transition()');
        $this->addSql('ALTER TABLE commercial.control_users DROP CONSTRAINT control_users_email_normalized_key, DROP CONSTRAINT control_users_email_check, DROP CONSTRAINT control_users_email_normalized_check, DROP CONSTRAINT control_users_status_check, DROP CONSTRAINT control_users_verification_check');
        $this->addSql("ALTER TABLE commercial.control_users DROP COLUMN email_normalized, DROP COLUMN password_hash, DROP COLUMN email_verified_at, DROP COLUMN updated_at, DROP COLUMN last_login_at, DROP COLUMN password_changed_at");
        $this->addSql("ALTER TABLE commercial.control_users ALTER COLUMN status SET DEFAULT 'invited'");
        $this->addSql("ALTER TABLE commercial.control_users ADD CONSTRAINT control_users_email_key UNIQUE (email), ADD CONSTRAINT control_users_email_check CHECK (email = lower(email) AND email ~ '^[a-z0-9.!#$%&''*+/=?^_`{|}~-]+@[a-z0-9-]+(\\.[a-z0-9-]+)+$'), ADD CONSTRAINT control_users_status_check CHECK (status IN ('invited','active','suspended','archived'))");
    }
}
