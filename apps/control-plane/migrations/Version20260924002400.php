<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924002400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Immutable signed deployment entitlement documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.deployment_entitlement_documents (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 deployment_id uuid NOT NULL REFERENCES commercial.deployments(id) ON DELETE RESTRICT,
 installation_id uuid NOT NULL,
 document_version integer NOT NULL,
 sequence bigint NOT NULL,
 canonical_document bytea NOT NULL,
 document_sha256 bytea NOT NULL,
 signature bytea NOT NULL,
 signing_key_id varchar(160) NOT NULL REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT,
 source_fingerprint bytea NOT NULL,
 issued_at timestamptz NOT NULL,
 valid_from timestamptz NOT NULL,
 valid_until timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT deployment_entitlement_version_check CHECK (document_version = 1),
 CONSTRAINT deployment_entitlement_sequence_check CHECK (sequence > 0),
 CONSTRAINT deployment_entitlement_bytes_check CHECK (
   octet_length(canonical_document) > 0 AND octet_length(document_sha256) = 32
   AND octet_length(signature) = 64 AND octet_length(source_fingerprint) = 32),
 CONSTRAINT deployment_entitlement_validity_check CHECK (valid_until IS NULL OR valid_until > valid_from),
 UNIQUE (deployment_id, sequence)
)
SQL);
        $this->addSql('CREATE INDEX deployment_entitlement_latest_idx ON commercial.deployment_entitlement_documents (deployment_id, sequence DESC)');
        $this->addSql('CREATE TRIGGER deployment_entitlement_immutable BEFORE UPDATE OR DELETE ON commercial.deployment_entitlement_documents FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.deployment_entitlement_documents');
    }
}
