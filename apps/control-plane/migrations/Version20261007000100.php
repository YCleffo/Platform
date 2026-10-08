<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007000100 extends AbstractMigration
{
    public function getDescription(): string { return 'Add durable renewal collection and subscription billing health state'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE commercial.subscriptions ADD COLUMN billing_status varchar(16) NOT NULL DEFAULT 'current', ADD COLUMN past_due_since timestamptz, ADD COLUMN grace_until timestamptz, ADD CONSTRAINT subscriptions_billing_status_check CHECK (billing_status IN ('current','past_due')), ADD CONSTRAINT subscriptions_billing_grace_check CHECK ((billing_status = 'current' AND past_due_since IS NULL AND grace_until IS NULL) OR (billing_status = 'past_due' AND past_due_since IS NOT NULL AND grace_until IS NOT NULL AND grace_until >= past_due_since))");
        $this->addSql('CREATE UNIQUE INDEX subscriptions_account_id_uidx ON commercial.subscriptions (commercial_account_id, id)');
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.renewal_collection_states (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 invoice_id uuid NOT NULL UNIQUE REFERENCES commercial.invoices(id) ON DELETE RESTRICT,
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 subscription_id uuid NOT NULL REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT,
 state varchar(32) NOT NULL DEFAULT 'ready',
 attempt_count integer NOT NULL DEFAULT 0,
 last_attempt_at timestamptz,
 next_attempt_at timestamptz,
 last_failure_class varchar(32),
 last_failure_code varchar(120),
 grace_deadline timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT renewal_collection_state_check CHECK (state IN ('ready','collecting','retry_wait','payment_method_required','succeeded','exhausted')),
 CONSTRAINT renewal_collection_attempt_count_check CHECK (attempt_count >= 0),
 CONSTRAINT renewal_collection_failure_class_check CHECK (last_failure_class IS NULL OR last_failure_class IN ('retryable','non_retryable','payment_method_invalid','unknown'))
)
SQL);
        $this->addSql('ALTER TABLE commercial.renewal_collection_states ADD CONSTRAINT renewal_collection_account_subscription_fk FOREIGN KEY (commercial_account_id, subscription_id) REFERENCES commercial.subscriptions(commercial_account_id, id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX renewal_collection_due_idx ON commercial.renewal_collection_states (state, next_attempt_at, invoice_id)');
        $this->addSql('CREATE INDEX subscriptions_billing_due_idx ON commercial.subscriptions (billing_status, grace_until) WHERE status = \'active\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.renewal_collection_states');
        $this->addSql('DROP INDEX commercial.subscriptions_account_id_uidx');
        $this->addSql('ALTER TABLE commercial.subscriptions DROP CONSTRAINT subscriptions_billing_grace_check, DROP CONSTRAINT subscriptions_billing_status_check, DROP COLUMN grace_until, DROP COLUMN past_due_since, DROP COLUMN billing_status');
    }
}
