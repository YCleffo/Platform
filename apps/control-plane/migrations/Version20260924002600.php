<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924002600 extends AbstractMigration
{
    public function getDescription(): string { return 'Permit explicit local development database secret references'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.deployments DROP CONSTRAINT deployment_secret_ref_check');
        $this->addSql("ALTER TABLE commercial.deployments ADD CONSTRAINT deployment_secret_ref_check
            CHECK (core_db_secret_ref IS NULL OR core_db_secret_ref ~ '^(vault|kms|hsm)://[^[:space:]]+$'
                OR core_db_secret_ref ~ '^localdev://[0-9a-f-]{36}/db$')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.deployments DROP CONSTRAINT deployment_secret_ref_check');
        $this->addSql("ALTER TABLE commercial.deployments ADD CONSTRAINT deployment_secret_ref_check
            CHECK (core_db_secret_ref IS NULL OR core_db_secret_ref ~ '^(vault|kms|hsm)://[^[:space:]]+$')");
    }
}
