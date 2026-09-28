<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Versioned outbox events with leased delivery, bounded retries and dead letters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial.outbox_events ADD schema_version smallint NOT NULL DEFAULT 1, ADD locked_at timestamptz, ADD lock_token uuid, ADD last_error varchar(160)');
        $this->addSql('ALTER TABLE commercial.outbox_events DROP CONSTRAINT outbox_status_check');
        $this->addSql("ALTER TABLE commercial.outbox_events ADD CONSTRAINT outbox_status_check CHECK (status IN ('pending','processing','published','failed','dead'))");
        $this->addSql("UPDATE commercial.outbox_events SET status = 'failed', available_at = now(), locked_at = NULL, lock_token = NULL WHERE status = 'processing'");
        $this->addSql('ALTER TABLE commercial.outbox_events ADD CONSTRAINT outbox_schema_version_check CHECK (schema_version > 0)');
        $this->addSql("ALTER TABLE commercial.outbox_events ADD CONSTRAINT outbox_lease_check CHECK ((status = 'processing') = (locked_at IS NOT NULL AND lock_token IS NOT NULL))");
        $this->addSql("ALTER TABLE commercial.outbox_events ADD CONSTRAINT outbox_published_check CHECK ((status = 'published') = (published_at IS NOT NULL))");
        $this->addSql('CREATE INDEX outbox_expired_lease_idx ON commercial.outbox_events (locked_at, id) WHERE status = \'processing\'');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.guard_published_outbox() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' AND OLD.status = 'published' THEN
        RAISE EXCEPTION 'published outbox event is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.status = 'published' AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'published outbox event is immutable' USING ERRCODE = 'check_violation';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END
$$
SQL);
        $this->addSql('CREATE TRIGGER outbox_published_guard BEFORE UPDATE OR DELETE ON commercial.outbox_events FOR EACH ROW EXECUTE FUNCTION commercial.guard_published_outbox()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER outbox_published_guard ON commercial.outbox_events');
        $this->addSql('DROP FUNCTION commercial.guard_published_outbox()');
        $this->addSql('DROP INDEX commercial.outbox_expired_lease_idx');
        $this->addSql("UPDATE commercial.outbox_events SET status = 'failed', available_at = now() WHERE status = 'dead'");
        $this->addSql("UPDATE commercial.outbox_events SET status = 'failed', available_at = now(), locked_at = NULL, lock_token = NULL WHERE status = 'processing'");
        $this->addSql('ALTER TABLE commercial.outbox_events DROP CONSTRAINT outbox_lease_check, DROP CONSTRAINT outbox_published_check, DROP CONSTRAINT outbox_schema_version_check, DROP CONSTRAINT outbox_status_check');
        $this->addSql("ALTER TABLE commercial.outbox_events ADD CONSTRAINT outbox_status_check CHECK (status IN ('pending','processing','published','failed'))");
        $this->addSql('ALTER TABLE commercial.outbox_events DROP COLUMN schema_version, DROP COLUMN locked_at, DROP COLUMN lock_token, DROP COLUMN last_error');
    }
}
