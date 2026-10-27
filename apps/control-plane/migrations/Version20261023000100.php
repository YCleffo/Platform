<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist customer choice to stop subscription auto-renewal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.subscriptions ADD COLUMN auto_renew_enabled boolean NOT NULL DEFAULT true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM commercial.subscriptions WHERE auto_renew_enabled = false) THEN
  RAISE EXCEPTION 'auto-renew cancellation choices must be retained' USING ERRCODE='check_violation';
 END IF;
END $$
SQL);
        $this->addSql('ALTER TABLE commercial.subscriptions DROP COLUMN auto_renew_enabled');
    }
}
