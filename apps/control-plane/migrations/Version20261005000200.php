<?php
declare(strict_types=1);
namespace ControlPlane\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261005000200 extends AbstractMigration
{
    public function getDescription(): string { return 'Enforce one active MFA login challenge and one pending TOTP setup per Control user'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
WITH ranked AS (
 SELECT id, row_number() OVER (PARTITION BY control_user_id, kind ORDER BY created_at DESC, id DESC) AS rn
 FROM commercial.control_auth_challenges
 WHERE kind = 'mfa_login' AND consumed_at IS NULL AND invalidated_at IS NULL
)
UPDATE commercial.control_auth_challenges c SET invalidated_at = now()
FROM ranked r WHERE c.id = r.id AND r.rn > 1
SQL);
        $this->addSql(<<<'SQL'
WITH ranked AS (
 SELECT id, row_number() OVER (PARTITION BY control_user_id ORDER BY created_at DESC, id DESC) AS rn
 FROM commercial.control_user_totp_factors WHERE status = 'pending'
)
UPDATE commercial.control_user_totp_factors f SET status = 'revoked', revoked_at = now()
FROM ranked r WHERE f.id = r.id AND r.rn > 1
SQL);
        $this->addSql("CREATE UNIQUE INDEX control_auth_challenge_one_active_login_idx ON commercial.control_auth_challenges (control_user_id, kind) WHERE kind = 'mfa_login' AND consumed_at IS NULL AND invalidated_at IS NULL");
        $this->addSql("CREATE UNIQUE INDEX control_totp_one_pending_idx ON commercial.control_user_totp_factors (control_user_id) WHERE status = 'pending'");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX commercial.control_totp_one_pending_idx');
        $this->addSql('DROP INDEX commercial.control_auth_challenge_one_active_login_idx');
    }
}
