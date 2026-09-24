<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Central identity, commercial customers and billing profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.control_users (
 id uuid PRIMARY KEY DEFAULT uuidv7(), email varchar(320) NOT NULL UNIQUE,
 status varchar(24) NOT NULL DEFAULT 'invited', created_at timestamptz NOT NULL DEFAULT now(), archived_at timestamptz,
 CONSTRAINT control_users_email_check CHECK (email = lower(email) AND email ~ '^[a-z0-9.!#$%&''*+/=?^_`{|}~-]+@[a-z0-9-]+(\.[a-z0-9-]+)+$'),
 CONSTRAINT control_users_status_check CHECK (status IN ('invited','active','suspended','archived'))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.commercial_accounts (
 id uuid PRIMARY KEY DEFAULT uuidv7(), code varchar(120) NOT NULL UNIQUE,
 display_name varchar(200) NOT NULL, status varchar(24) NOT NULL DEFAULT 'draft',
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), archived_at timestamptz,
 CONSTRAINT commercial_accounts_code_check CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
 CONSTRAINT commercial_accounts_name_check CHECK (btrim(display_name) <> '' AND display_name !~ '[[:cntrl:]]'),
 CONSTRAINT commercial_accounts_status_check CHECK (status IN ('draft','active','suspended','closed')),
 CONSTRAINT commercial_accounts_closed_check CHECK ((status = 'closed') = (archived_at IS NOT NULL))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.enforce_account_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> OLD.status AND NOT (
   (OLD.status = 'draft' AND NEW.status IN ('active','closed')) OR
   (OLD.status = 'active' AND NEW.status IN ('suspended','closed')) OR
   (OLD.status = 'suspended' AND NEW.status IN ('active','closed'))
 ) THEN RAISE EXCEPTION 'invalid commercial account status transition' USING ERRCODE = 'check_violation'; END IF;
 RETURN NEW;
END $$
SQL);
        $this->addSql('CREATE TRIGGER commercial_account_transition BEFORE UPDATE ON commercial.commercial_accounts FOR EACH ROW EXECUTE FUNCTION commercial.enforce_account_transition()');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.commercial_account_memberships (
 id uuid PRIMARY KEY DEFAULT uuidv7(), control_user_id uuid NOT NULL REFERENCES commercial.control_users(id) ON DELETE RESTRICT,
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 role varchar(24) NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), revoked_at timestamptz,
 CONSTRAINT memberships_role_check CHECK (role IN ('owner','admin','billing','technical')),
 UNIQUE (control_user_id, commercial_account_id)
)
SQL);
        $this->addSql('CREATE INDEX memberships_account_idx ON commercial.commercial_account_memberships (commercial_account_id)');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.billing_profiles (
 id uuid PRIMARY KEY DEFAULT uuidv7(), commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 legal_name varchar(240) NOT NULL, country_code char(2) NOT NULL, is_default boolean NOT NULL DEFAULT false,
 created_at timestamptz NOT NULL DEFAULT now(), archived_at timestamptz,
 CONSTRAINT billing_profile_name_check CHECK (btrim(legal_name) <> '' AND legal_name !~ '[[:cntrl:]]'),
 CONSTRAINT billing_profile_country_check CHECK (country_code ~ '^[A-Z]{2}$')
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX billing_profile_default_uidx ON commercial.billing_profiles (commercial_account_id) WHERE is_default AND archived_at IS NULL');
        $this->addSql('CREATE INDEX billing_profiles_account_idx ON commercial.billing_profiles (commercial_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.billing_profiles, commercial.commercial_account_memberships, commercial.commercial_accounts, commercial.control_users');
        $this->addSql('DROP FUNCTION commercial.enforce_account_transition()');
    }
}
