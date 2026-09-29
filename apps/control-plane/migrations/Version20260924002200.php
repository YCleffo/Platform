<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924002200 extends AbstractMigration
{
    public function getDescription(): string { return 'Deployment machine credentials'; }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.service_credentials (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 deployment_id uuid NOT NULL REFERENCES commercial.deployments(id) ON DELETE RESTRICT,
 credential_id uuid NOT NULL UNIQUE DEFAULT uuidv4(),
 secret_hash bytea NOT NULL CHECK (octet_length(secret_hash) = 32),
 status varchar(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active','rotated','revoked')),
 scopes text[] NOT NULL CHECK (cardinality(scopes) > 0 AND array_position(scopes, NULL) IS NULL),
 created_at timestamptz NOT NULL DEFAULT now(),
 expires_at timestamptz,
 last_used_at timestamptz,
 rotated_at timestamptz,
 revoked_at timestamptz,
 CONSTRAINT service_credential_expiry_check CHECK (expires_at IS NULL OR expires_at > created_at),
 CONSTRAINT service_credential_status_check CHECK (
  (status = 'active' AND rotated_at IS NULL AND revoked_at IS NULL)
  OR (status = 'rotated' AND rotated_at IS NOT NULL AND revoked_at IS NULL)
  OR (status = 'revoked' AND revoked_at IS NOT NULL)
 )
)
SQL);
        $this->addSql('CREATE INDEX service_credentials_deployment_idx ON commercial.service_credentials (deployment_id, created_at DESC)');
        $this->addSql('CREATE INDEX service_credentials_cleanup_idx ON commercial.service_credentials (COALESCE(revoked_at, rotated_at, expires_at)) WHERE status <> \'active\' OR expires_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.service_credentials');
    }
}
