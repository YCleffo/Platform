<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class SubscriptionActivationConstraintsTest extends SubscriptionTestCase
{
    public function testSubscriptionStateMachineAndActivePeriodAreDatabaseGuarded(): void
    {
        [, $account, $profile] = $this->readyAccount('state-machine');
        $active = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'draft') RETURNING id", [$account, $profile]);
        self::assertIsString($active);
        $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'active', current_period_start = now(), current_period_end = now() + interval '1 month' WHERE id = ?", [$active]);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'draft', current_period_start = NULL, current_period_end = NULL WHERE id = ?", [$active]));

        $cancelled = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'cancelled') RETURNING id", [$account, $profile]);
        $expired = $this->connection->fetchOne("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'expired') RETURNING id", [$account, $profile]);
        self::assertIsString($cancelled);
        self::assertIsString($expired);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'active', current_period_start = now(), current_period_end = now() + interval '1 month' WHERE id = ?", [$cancelled]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'active', current_period_start = now(), current_period_end = now() + interval '1 month' WHERE id = ?", [$expired]));
        [, $noPeriodAccount, $noPeriodProfile] = $this->readyAccount('active-no-period');
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status) VALUES (?, ?, 'active')", [$noPeriodAccount, $noPeriodProfile]));
        [, $invalidPeriodAccount, $invalidPeriodProfile] = $this->readyAccount('active-invalid-period');
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscriptions (commercial_account_id, billing_profile_id, status, current_period_start, current_period_end) VALUES (?, ?, 'active', now(), now())", [$invalidPeriodAccount, $invalidPeriodProfile]));
    }

    public function testActivatedSubscriptionItemsAreImmutable(): void
    {
        [, $account, $profile] = $this->readyAccount('immutable-items');
        [, $price] = $this->planPrice('immutable-items-plan');
        [, $otherPrice] = $this->planPrice('immutable-items-other-plan');
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $item = $this->connection->fetchOne('SELECT id FROM commercial.subscription_items WHERE subscription_id = ?', [$subscription]);
        self::assertIsString($item);
        $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'active', current_period_start = now(), current_period_end = now() + interval '1 month' WHERE id = ?", [$subscription]);

        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.subscription_items (subscription_id, plan_price_id, quantity, valid_from) VALUES (?, ?, 1, now())", [$subscription, $price]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.subscription_items WHERE id = ?', [$item]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.subscription_items SET plan_price_id = ? WHERE id = ?', [$otherPrice, $item]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.subscription_items SET quantity = 2 WHERE id = ?', [$item]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.subscription_items SET valid_from = valid_from - interval '1 day' WHERE id = ?", [$item]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.subscription_items SET valid_until = now() + interval '1 day' WHERE id = ?", [$item]));
    }
}
