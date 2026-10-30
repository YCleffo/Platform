<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add commercial account email invitations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.commercial_account_invitations (
    id uuid PRIMARY KEY,
    commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts (id),
    email varchar(320) NOT NULL,
    email_normalized varchar(320) NOT NULL,
    role varchar(16) NOT NULL CHECK (role IN ('admin', 'billing', 'technical')),
    invited_by uuid NOT NULL REFERENCES commercial.control_users (id),
    token_hash bytea NOT NULL,
    token_version integer NOT NULL DEFAULT 1 CHECK (token_version >= 1),
    status varchar(16) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'declined', 'revoked', 'expired')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    accepted_by uuid NULL REFERENCES commercial.control_users (id),
    accepted_at timestamptz NULL,
    declined_at timestamptz NULL,
    revoked_at timestamptz NULL,
    CONSTRAINT commercial_account_invitations_token_hash_key UNIQUE (token_hash),
    CONSTRAINT commercial_account_invitations_email_check CHECK (length(btrim(email_normalized)) > 0)
)
SQL);
        $this->addSql('CREATE INDEX commercial_account_invitations_account_status_idx ON commercial.commercial_account_invitations (commercial_account_id, status)');
        $this->addSql('CREATE INDEX commercial_account_invitations_expires_at_idx ON commercial.commercial_account_invitations (expires_at)');
        $this->addSql("CREATE UNIQUE INDEX commercial_account_invitations_pending_email_key ON commercial.commercial_account_invitations (commercial_account_id, email_normalized) WHERE status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.commercial_account_invitations');
    }
}
