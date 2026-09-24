<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signed release metadata and immutable published releases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.releases (
 id uuid PRIMARY KEY DEFAULT uuidv7(), version varchar(80) NOT NULL UNIQUE,
 channel varchar(24) NOT NULL, status varchar(24) NOT NULL DEFAULT 'draft',
 compatibility jsonb NOT NULL DEFAULT '{}'::jsonb, oci_reference varchar(400), image_digest varchar(80),
 manifest_sha256 bytea, manifest_signature bytea,
 signing_key_id varchar(160) REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT now(), published_at timestamptz, retired_at timestamptz,
 CONSTRAINT release_version_check CHECK (version ~ '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'),
 CONSTRAINT release_channel_check CHECK (channel IN ('stable','beta','canary')),
 CONSTRAINT release_status_check CHECK (status IN ('draft','published','retired')),
 CONSTRAINT release_compatibility_check CHECK (jsonb_typeof(compatibility) = 'object'),
 CONSTRAINT release_digest_check CHECK (image_digest IS NULL OR image_digest ~ '^sha256:[0-9a-f]{64}$'),
 CONSTRAINT release_manifest_check CHECK (manifest_sha256 IS NULL OR octet_length(manifest_sha256) = 32),
 CONSTRAINT release_signature_check CHECK (manifest_signature IS NULL OR octet_length(manifest_signature) = 64),
 CONSTRAINT release_publish_check CHECK ((status = 'draft' AND published_at IS NULL AND retired_at IS NULL) OR (status = 'published' AND published_at IS NOT NULL AND retired_at IS NULL) OR (status = 'retired' AND published_at IS NOT NULL AND retired_at IS NOT NULL)),
 CONSTRAINT release_complete_check CHECK (status = 'draft' OR (oci_reference IS NOT NULL AND image_digest IS NOT NULL AND manifest_sha256 IS NOT NULL AND manifest_signature IS NOT NULL AND signing_key_id IS NOT NULL))
)
SQL);
        $this->addSql('CREATE TRIGGER releases_immutable BEFORE UPDATE OR DELETE ON commercial.releases FOR EACH ROW EXECUTE FUNCTION commercial.prevent_published_release_mutation()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.release_entitlements (
 release_id uuid NOT NULL REFERENCES commercial.releases(id) ON DELETE RESTRICT,
 feature_id uuid NOT NULL REFERENCES commercial.features(id) ON DELETE RESTRICT,
 requirement jsonb NOT NULL, PRIMARY KEY (release_id, feature_id)
)
SQL);
        $this->addSql('CREATE INDEX release_entitlements_feature_idx ON commercial.release_entitlements (feature_id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_release_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE release_state text;
BEGIN
 SELECT status INTO release_state FROM commercial.releases WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.release_id ELSE NEW.release_id END FOR UPDATE;
 IF release_state <> 'draft' THEN RAISE EXCEPTION 'published release entitlements are immutable' USING ERRCODE = 'check_violation'; END IF;
 IF TG_OP = 'UPDATE' AND NEW.release_id <> OLD.release_id THEN
   SELECT status INTO release_state FROM commercial.releases WHERE id = OLD.release_id FOR UPDATE;
   IF release_state <> 'draft' THEN RAISE EXCEPTION 'published release entitlements are immutable' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER release_entitlements_guard BEFORE INSERT OR UPDATE OR DELETE ON commercial.release_entitlements FOR EACH ROW EXECUTE FUNCTION commercial.enforce_release_child()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.release_entitlements, commercial.releases');
        $this->addSql('DROP FUNCTION commercial.enforce_release_child()');
    }
}
