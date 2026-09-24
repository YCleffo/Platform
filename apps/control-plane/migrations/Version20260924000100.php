<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate Commercial schema and common database invariants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA commercial');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.reject_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'immutable commercial record: %', TG_TABLE_NAME USING ERRCODE = 'check_violation';
END
$$
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.prevent_published_plan_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'published plan is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status = 'published' AND NEW.status = 'retired'
       AND ROW(NEW.id, NEW.code, NEW.version, NEW.name, NEW.created_at, NEW.published_at)
           IS NOT DISTINCT FROM ROW(OLD.id, OLD.code, OLD.version, OLD.name, OLD.created_at, OLD.published_at)
       AND NEW.retired_at IS NOT NULL THEN
        RETURN NEW;
    END IF;
    IF OLD.status IN ('published', 'retired') THEN
        RAISE EXCEPTION 'published plan is immutable' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$$
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.prevent_published_release_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'published release is immutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status = 'published' AND NEW.status = 'retired'
       AND ROW(NEW.id, NEW.version, NEW.channel, NEW.compatibility, NEW.oci_reference, NEW.image_digest, NEW.manifest_sha256, NEW.manifest_signature, NEW.signing_key_id, NEW.created_at, NEW.published_at)
           IS NOT DISTINCT FROM ROW(OLD.id, OLD.version, OLD.channel, OLD.compatibility, OLD.oci_reference, OLD.image_digest, OLD.manifest_sha256, OLD.manifest_signature, OLD.signing_key_id, OLD.created_at, OLD.published_at)
       AND NEW.retired_at IS NOT NULL THEN
        RETURN NEW;
    END IF;
    IF OLD.status IN ('published', 'retired') THEN
        RAISE EXCEPTION 'published release is immutable' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA commercial CASCADE');
    }
}
