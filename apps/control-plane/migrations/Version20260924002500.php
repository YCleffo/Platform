<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924002500 extends AbstractMigration
{
    public function getDescription(): string { return 'Durable Control Plane background job status'; }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.background_job_status (
    job_code varchar(120) PRIMARY KEY,
    last_started_at timestamptz,
    last_succeeded_at timestamptz,
    last_failed_at timestamptz,
    last_duration_ms integer CHECK (last_duration_ms IS NULL OR last_duration_ms >= 0),
    last_processed_count integer CHECK (last_processed_count IS NULL OR last_processed_count >= 0),
    consecutive_failures integer NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
    last_error_code varchar(120),
    last_result varchar(16) NOT NULL DEFAULT 'waiting'
        CHECK (last_result IN ('waiting','started','succeeded','failed','skipped','disabled')),
    heartbeat_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT background_job_code_safe CHECK (job_code ~ '^(__scheduler__|[a-z][a-z0-9]*(?:_[a-z0-9]+)*)$'),
    CONSTRAINT background_job_error_safe CHECK (last_error_code IS NULL OR
        last_error_code ~ '^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$')
)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.background_job_status');
    }
}
