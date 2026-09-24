<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signing key metadata, license policy and immutable issued documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.signing_keys (
 key_id varchar(160) PRIMARY KEY, algorithm varchar(24) NOT NULL, purpose varchar(32) NOT NULL,
 public_key bytea NOT NULL, private_key_secret_ref varchar(300) NOT NULL,
 status varchar(24) NOT NULL, valid_from timestamptz NOT NULL, valid_until timestamptz,
 revoked_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT signing_key_id_check CHECK (key_id ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT signing_algorithm_check CHECK (algorithm = 'Ed25519'),
 CONSTRAINT signing_purpose_check CHECK (purpose IN ('entitlement','release')),
 CONSTRAINT signing_public_key_check CHECK (octet_length(public_key) = 32),
 CONSTRAINT signing_secret_ref_check CHECK (private_key_secret_ref ~ '^(vault|kms|hsm)://[^[:space:]]+$'),
 CONSTRAINT signing_status_check CHECK (status IN ('active','retired','revoked')),
 CONSTRAINT signing_interval_check CHECK (valid_until IS NULL OR valid_until > valid_from),
 CONSTRAINT signing_revocation_check CHECK ((status = 'revoked') = (revoked_at IS NOT NULL))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_signing_key_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.key_id, NEW.algorithm, NEW.purpose, NEW.public_key, NEW.private_key_secret_ref, NEW.valid_from)
    IS DISTINCT FROM (OLD.key_id, OLD.algorithm, OLD.purpose, OLD.public_key, OLD.private_key_secret_ref, OLD.valid_from) THEN
   RAISE EXCEPTION 'signing key identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER signing_key_identity_guard BEFORE UPDATE ON commercial.signing_keys FOR EACH ROW EXECUTE FUNCTION commercial.protect_signing_key_identity()');
        $this->addSql('CREATE TRIGGER signing_key_no_delete BEFORE DELETE ON commercial.signing_keys FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.licenses (
 id uuid PRIMARY KEY DEFAULT uuidv7(), commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 kind varchar(24) NOT NULL, status varchar(24) NOT NULL DEFAULT 'draft',
 valid_from timestamptz NOT NULL, valid_until timestamptz, max_installations integer NOT NULL,
 policy jsonb NOT NULL DEFAULT '{}'::jsonb, created_at timestamptz NOT NULL DEFAULT now(), revoked_at timestamptz,
 CONSTRAINT licenses_kind_check CHECK (kind IN ('subscription','perpetual')),
 CONSTRAINT licenses_status_check CHECK (status IN ('draft','active','expired','revoked')),
 CONSTRAINT licenses_interval_check CHECK (valid_until IS NULL OR valid_until > valid_from),
 CONSTRAINT licenses_max_installations_check CHECK (max_installations > 0),
 CONSTRAINT licenses_policy_check CHECK (jsonb_typeof(policy) = 'object'),
 CONSTRAINT licenses_revocation_check CHECK ((status = 'revoked') = (revoked_at IS NOT NULL))
)
SQL);
        $this->addSql('CREATE INDEX licenses_account_idx ON commercial.licenses (commercial_account_id)');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.maintenance_contracts (
 id uuid PRIMARY KEY DEFAULT uuidv7(), license_id uuid NOT NULL REFERENCES commercial.licenses(id) ON DELETE RESTRICT,
 valid_from timestamptz NOT NULL, valid_until timestamptz NOT NULL, status varchar(24) NOT NULL,
 CONSTRAINT maintenance_interval_check CHECK (valid_until > valid_from),
 CONSTRAINT maintenance_status_check CHECK (status IN ('active','expired','revoked')),
 UNIQUE (license_id, valid_from)
)
SQL);
        $this->addSql('CREATE INDEX maintenance_license_idx ON commercial.maintenance_contracts (license_id)');
        $this->addSql('CREATE TRIGGER license_no_delete BEFORE DELETE ON commercial.licenses FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
        $this->addSql('CREATE TRIGGER maintenance_no_delete BEFORE DELETE ON commercial.maintenance_contracts FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_license_limit_update() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE active_count integer;
BEGIN
 IF NEW.max_installations < OLD.max_installations THEN
   SELECT count(*) INTO active_count FROM commercial.license_installations WHERE license_id = NEW.id AND revoked_at IS NULL;
   IF NEW.max_installations < active_count THEN RAISE EXCEPTION 'license installation limit exceeded' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.license_installations (
 license_id uuid NOT NULL REFERENCES commercial.licenses(id) ON DELETE RESTRICT,
 installation_id uuid NOT NULL, activated_at timestamptz NOT NULL DEFAULT now(), revoked_at timestamptz,
 PRIMARY KEY (license_id, installation_id)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_license_installation_limit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE max_allowed integer; active_count integer; license_status text;
BEGIN
 SELECT max_installations, status INTO max_allowed, license_status FROM commercial.licenses WHERE id = NEW.license_id FOR UPDATE;
 IF license_status <> 'active' THEN RAISE EXCEPTION 'license is not active' USING ERRCODE = 'check_violation'; END IF;
 IF NEW.revoked_at IS NULL THEN
   SELECT count(*) INTO active_count FROM commercial.license_installations WHERE license_id = NEW.license_id AND revoked_at IS NULL AND installation_id <> NEW.installation_id;
   IF active_count >= max_allowed THEN RAISE EXCEPTION 'license installation limit exceeded' USING ERRCODE = 'check_violation'; END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER license_installation_limit BEFORE INSERT OR UPDATE ON commercial.license_installations FOR EACH ROW EXECUTE FUNCTION commercial.enforce_license_installation_limit()');
        $this->addSql('CREATE TRIGGER license_limit_update BEFORE UPDATE ON commercial.licenses FOR EACH ROW EXECUTE FUNCTION commercial.enforce_license_limit_update()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.issued_entitlement_documents (
 id uuid PRIMARY KEY DEFAULT uuidv7(), license_id uuid NOT NULL REFERENCES commercial.licenses(id) ON DELETE RESTRICT,
 installation_id uuid NOT NULL, document_version integer NOT NULL, sequence bigint NOT NULL,
 envelope bytea NOT NULL, envelope_sha256 bytea NOT NULL, signature bytea NOT NULL,
 signing_key_id varchar(160) NOT NULL REFERENCES commercial.signing_keys(key_id) ON DELETE RESTRICT,
 issued_at timestamptz NOT NULL, valid_from timestamptz NOT NULL, valid_until timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT issued_doc_version_check CHECK (document_version > 0),
 CONSTRAINT issued_doc_sequence_check CHECK (sequence > 0),
 CONSTRAINT issued_doc_envelope_check CHECK (octet_length(envelope) > 0 AND octet_length(envelope_sha256) = 32 AND octet_length(signature) = 64),
 CONSTRAINT issued_doc_interval_check CHECK (valid_until IS NULL OR valid_until > valid_from),
 UNIQUE (installation_id, sequence)
)
SQL);
        $this->addSql('CREATE TRIGGER issued_doc_immutable BEFORE UPDATE OR DELETE ON commercial.issued_entitlement_documents FOR EACH ROW EXECUTE FUNCTION commercial.reject_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.issued_entitlement_documents, commercial.license_installations, commercial.maintenance_contracts, commercial.licenses, commercial.signing_keys');
        $this->addSql('DROP FUNCTION commercial.enforce_license_installation_limit()');
        $this->addSql('DROP FUNCTION commercial.enforce_license_limit_update()');
        $this->addSql('DROP FUNCTION commercial.protect_signing_key_identity()');
    }
}
