<?php

declare(strict_types=1);

namespace ControlPlane\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3.1 subscription foundation and selected plan prices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.subscriptions (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 commercial_account_id uuid NOT NULL REFERENCES commercial.commercial_accounts(id) ON DELETE RESTRICT,
 billing_profile_id uuid NOT NULL REFERENCES commercial.billing_profiles(id) ON DELETE RESTRICT,
 status varchar(24) NOT NULL DEFAULT 'draft',
 current_period_start timestamptz,
 current_period_end timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT subscriptions_status_check CHECK (status IN ('draft','active','cancelled','expired')),
 CONSTRAINT subscriptions_period_pair_check CHECK ((current_period_start IS NULL) = (current_period_end IS NULL)),
 CONSTRAINT subscriptions_period_order_check CHECK (current_period_end IS NULL OR current_period_end > current_period_start),
 CONSTRAINT subscriptions_draft_period_check CHECK (status <> 'draft' OR current_period_start IS NULL)
)
SQL);
        $this->addSql('CREATE INDEX subscriptions_account_history_idx ON commercial.subscriptions (commercial_account_id, created_at DESC)');
        $this->addSql("CREATE UNIQUE INDEX subscriptions_one_draft_per_account_uidx ON commercial.subscriptions (commercial_account_id) WHERE status = 'draft'");
        $this->addSql("CREATE UNIQUE INDEX subscriptions_one_active_per_account_uidx ON commercial.subscriptions (commercial_account_id) WHERE status = 'active'");
        $this->addSql(<<<'SQL'
CREATE TABLE commercial.subscription_items (
 id uuid PRIMARY KEY DEFAULT uuidv7(),
 subscription_id uuid NOT NULL REFERENCES commercial.subscriptions(id) ON DELETE RESTRICT,
 plan_price_id uuid NOT NULL REFERENCES commercial.plan_prices(id) ON DELETE RESTRICT,
 quantity integer NOT NULL,
 valid_from timestamptz NOT NULL,
 valid_until timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 CONSTRAINT subscription_items_quantity_check CHECK (quantity > 0),
 CONSTRAINT subscription_items_validity_check CHECK (valid_until IS NULL OR valid_until > valid_from)
)
SQL);
        $this->addSql('CREATE INDEX subscription_items_subscription_idx ON commercial.subscription_items (subscription_id, created_at)');
        $this->addSql('CREATE INDEX subscription_items_plan_price_idx ON commercial.subscription_items (plan_price_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commercial.subscription_items, commercial.subscriptions');
    }
}
