<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Subscription\Application\CancelSubscription;

final class UsageConstraintsTest extends SubscriptionTestCase
{
    public function testUsageModeDefaultsValidationIdentityAndMeteredLimitsAreGuarded(): void
    {
        $plain = $this->connection->fetchOne("INSERT INTO commercial.features (code, value_type) VALUES ('usage.default', 'integer') RETURNING id");
        self::assertIsString($plain);
        self::assertSame('none', $this->connection->fetchOne('SELECT usage_mode FROM commercial.features WHERE id = ?', [$plain]));
        foreach (['none', 'counter', 'gauge'] as $mode) {
            $this->feature('usage.mode.' . $mode, 'integer', $mode);
        }
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.features (code, value_type, usage_mode) VALUES ('usage.bad.mode', 'integer', 'unknown')"));
        foreach (['boolean', 'string', 'json'] as $type) {
            $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('INSERT INTO commercial.features (code, value_type, usage_mode) VALUES (?, ?, ?)', ['usage.bad.' . $type, $type, 'counter']));
        }
        $metered = $this->feature('usage.immutable', 'integer', 'counter');
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.features SET usage_mode = 'gauge' WHERE id = ?", [$metered]));

        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES ('usage-negative-plan', 1, 'Usage Negative') RETURNING id");
        self::assertIsString($plan);
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.plan_features (plan_id, feature_id, value) VALUES (?, ?, '-1'::jsonb)", [$plan, $metered]));
    }

    public function testUsagePeriodAndCounterHistoryAreDatabaseGuarded(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('usage-constraints');
        $feature = $this->feature('usage.constraints.counter', 'integer', 'counter');
        $otherFeature = $this->feature('usage.constraints.other', 'integer', 'gauge');
        [, $price] = $this->planPrice('usage-constraints-plan', featureValues: [$feature => 10]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activator()->activate($account, $subscription, $this->activationInstant(), $this->uuid());
        $period = $this->connection->fetchAssociative("SELECT id, starts_at, ends_at FROM commercial.usage_periods WHERE subscription_id = ? AND status = 'open'", [$subscription]);
        self::assertIsArray($period);

        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.usage_periods (subscription_id, starts_at, ends_at, status, closed_at) VALUES (?, now(), now(), 'closed', now())", [$subscription]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("INSERT INTO commercial.usage_periods (subscription_id, starts_at, ends_at, status) VALUES (?, now(), now() + interval '1 day', 'open')", [$subscription]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.usage_periods SET starts_at = starts_at - interval '1 second' WHERE id = ?", [$period['id']]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('INSERT INTO commercial.usage_counters (usage_period_id, feature_id, quantity) VALUES (?, ?, -1)', [$period['id'], $feature]));

        $this->connection->executeStatement('INSERT INTO commercial.usage_counters (usage_period_id, feature_id, quantity) VALUES (?, ?, 3)', [$period['id'], $feature]);
        (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))->cancel($owner, $account, $subscription, 'usage-constraints-cancel', $this->uuid());
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.usage_periods SET status = 'open', closed_at = NULL WHERE id = ?", [$period['id']]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('UPDATE commercial.usage_counters SET quantity = 4 WHERE usage_period_id = ? AND feature_id = ?', [$period['id'], $feature]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('DELETE FROM commercial.usage_counters WHERE usage_period_id = ? AND feature_id = ?', [$period['id'], $feature]));
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement('INSERT INTO commercial.usage_counters (usage_period_id, feature_id, quantity) VALUES (?, ?, 0)', [$period['id'], $otherFeature]));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT quantity FROM commercial.usage_counters WHERE usage_period_id = ? AND feature_id = ?', [$period['id'], $feature]));
    }
}
