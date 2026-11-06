<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog operator grants for draft and publish workflow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.catalog_operators (
 control_user_id uuid PRIMARY KEY REFERENCES commercial.control_users(id) ON DELETE CASCADE,
 authorization_reference text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 revoked_at timestamptz,
 CONSTRAINT catalog_operators_reference_check CHECK (
   btrim(authorization_reference) <> ''
   AND length(authorization_reference) <= 500
   AND authorization_reference !~ '[[:cntrl:]]'
 )
)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.catalog_operators');
    }
}
