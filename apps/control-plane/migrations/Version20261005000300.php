<?php
declare(strict_types=1);
namespace ControlPlane\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261005000300 extends AbstractMigration
{
 public function getDescription(): string { return 'Add safe account-scoped saved payment methods for authoritative card payments'; }
 public function up(Schema $schema): void
 {
  $this->addSql(<<<'SQL'
CREATE TABLE commercial.saved_payment_methods (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 provider_account_id uuid NOT NULL REFERENCES commercial.payment_provider_accounts(id) ON DELETE RESTRICT,
 provider_method_reference varchar(200) NOT NULL,
 method_type varchar(80) NOT NULL,
 status varchar(24) NOT NULL DEFAULT 'active',
 display_title varchar(200), card_brand varchar(80), last4 varchar(4), expiry_month smallint, expiry_year smallint,
 is_default boolean NOT NULL DEFAULT false,
 created_from_payment_attempt_id uuid NOT NULL REFERENCES commercial.payment_attempts(id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), last_used_at timestamptz, disabled_at timestamptz,
 CONSTRAINT saved_payment_methods_status_check CHECK (status IN ('active','disabled','provider_unusable')),
 CONSTRAINT saved_payment_methods_reference_check CHECK (btrim(provider_method_reference) <> '' AND provider_method_reference !~ '[[:cntrl:]]'),
 CONSTRAINT saved_payment_methods_last4_check CHECK (last4 IS NULL OR last4 ~ '^[0-9]{4}$'),
 CONSTRAINT saved_payment_methods_expiry_check CHECK ((expiry_month IS NULL AND expiry_year IS NULL) OR (expiry_month BETWEEN 1 AND 12 AND expiry_year BETWEEN 2000 AND 9999)),
 CONSTRAINT saved_payment_methods_disabled_check CHECK ((status = 'active' AND disabled_at IS NULL) OR (status <> 'active' AND disabled_at IS NOT NULL))
)
SQL);
  $this->addSql('CREATE UNIQUE INDEX saved_payment_methods_provider_reference_uidx ON commercial.saved_payment_methods (provider_account_id, provider_method_reference)');
  $this->addSql("CREATE UNIQUE INDEX saved_payment_methods_one_default_uidx ON commercial.saved_payment_methods (commercial_account_id) WHERE is_default AND status = 'active'");
  $this->addSql('CREATE INDEX saved_payment_methods_account_idx ON commercial.saved_payment_methods (commercial_account_id, status, created_at DESC)');
  $this->addSql(<<<'SQL'
CREATE FUNCTION commercial.protect_saved_payment_method() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'saved payment methods are historical and cannot be deleted' USING ERRCODE = 'check_violation'; END IF;
 IF NEW.commercial_account_id IS DISTINCT FROM OLD.commercial_account_id
    OR NEW.provider_account_id IS DISTINCT FROM OLD.provider_account_id
    OR NEW.provider_method_reference IS DISTINCT FROM OLD.provider_method_reference
    OR NEW.method_type IS DISTINCT FROM OLD.method_type
    OR NEW.created_from_payment_attempt_id IS DISTINCT FROM OLD.created_from_payment_attempt_id
    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
   RAISE EXCEPTION 'saved payment method identity is immutable' USING ERRCODE = 'check_violation';
 END IF;
 IF OLD.status <> 'active' AND NEW IS DISTINCT FROM OLD THEN
   RAISE EXCEPTION 'disabled saved payment method is immutable' USING ERRCODE = 'check_violation';
 END IF;
 RETURN NEW;
END $$
SQL);
  $this->addSql('CREATE TRIGGER saved_payment_methods_contract BEFORE UPDATE OR DELETE ON commercial.saved_payment_methods FOR EACH ROW EXECUTE FUNCTION commercial.protect_saved_payment_method()');
 }
 public function down(Schema $schema): void { $this->addSql('DROP TABLE commercial.saved_payment_methods'); $this->addSql('DROP FUNCTION commercial.protect_saved_payment_method()'); }
}
