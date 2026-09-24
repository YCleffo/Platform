<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class SubscriptionConstraintsTest extends DatabaseTestCase
{
    public function testSubscriptionStatusPeriodsAndForeignKeysAreDatabaseGuarded(): void
    {
        [$account, $profile] = $this->accountAndProfile('subscription-constraints');
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'unknown')", [$account, $profile]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status, current_period_start) VALUES (?, ?, 'active', now())", [$account, $profile]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status, current_period_start, current_period_end) VALUES (?, ?, 'active', now(), now())", [$account, $profile]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status, current_period_start, current_period_end) VALUES (?, ?, 'draft', now(), now() + interval '1 month')", [$account, $profile]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft')", [$this->uuid(), $profile]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft')", [$account, $this->uuid()]));
    }

    public function testOnlyOneDraftAndOneActiveSubscriptionExistPerAccount(): void
    {
        [$account, $profile] = $this->accountAndProfile('subscription-unique');
        $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft')", [$account, $profile]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft')", [$account, $profile]));
        $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status, current_period_start, current_period_end) VALUES (?, ?, 'active', now(), now() + interval '1 month')", [$account, $profile]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status, current_period_start, current_period_end) VALUES (?, ?, 'active', now(), now() + interval '1 month')", [$account, $profile]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscriptions WHERE commercial_account_id = ?', [$account]));
    }

    public function testSubscriptionItemQuantityValidityAndForeignKeysAreDatabaseGuarded(): void
    {
        [$account, $profile] = $this->accountAndProfile('subscription-item-constraints');
        $subscription = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$account, $profile]);
        self::assertIsString($subscription);
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES ('constraint-plan', 1, 'Constraint Plan') RETURNING id");
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 1000, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);

        foreach ([0, -1] as $quantity) {
            $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, ?, now())', [$subscription, $price, $quantity]));
        }
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from, valid_until) VALUES (?, ?, 1, now(), now())", [$subscription, $price]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, now())', [$this->uuid(), $price]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, now())', [$subscription, $this->uuid()]));
    }

    /** @return array{string, string} */
    private function accountAndProfile(string $code): array
    {
        $account = $this->account($code);
        $profile = $this->connection->fetchOne("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default) VALUES (?, 'Example LLC', 'US', true) RETURNING id", [$account]);
        self::assertIsString($profile);

        return [$account, $profile];
    }
}
