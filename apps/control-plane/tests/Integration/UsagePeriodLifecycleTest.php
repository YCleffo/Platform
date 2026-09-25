<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Subscription\Application\CancelSubscription;
use ControlPlane\Subscription\Application\CreateSubscription;
use ControlPlane\Subscription\Application\ExpireSubscription;
use ControlPlane\Usage\Application\ApplyUsageDelta;

final class UsagePeriodLifecycleTest extends SubscriptionTestCase
{
    public function testActivationCreatesMatchingOpenPeriodAndDraftCancellationDoesNot(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('usage-period-activation');
        $feature = $this->feature('usage.period.activation', 'integer', 'counter');
        [, $price] = $this->planPrice('usage-period-activation-plan', featureValues: [$feature => 10]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $active = $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        $period = $this->connection->fetchAssociative('SELECT starts_at, ends_at, status FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]);
        self::assertIsArray($period);
        self::assertSame($active['current_period_start'], $period['starts_at']);
        self::assertSame($active['current_period_end'], $period['ends_at']);
        self::assertSame('open', $period['status']);

        [$draftOwner, $draftAccount, $draftProfile] = $this->readyAccount('usage-period-draft-cancel');
        $draft = $this->draftSubscription($draftAccount, $draftProfile, [$price]);
        (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))->cancel($draftOwner, $draftAccount, $draft, 'usage-draft-cancel', $this->uuid());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_periods WHERE subscription_id = ?', [$draft]));
    }

    public function testCancellationAndExpirationClosePeriodsWithoutDeletingCounters(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('usage-period-cancel');
        $feature = $this->feature('usage.period.gauge', 'integer', 'gauge');
        [, $price] = $this->planPrice('usage-period-cancel-plan', featureValues: [$feature => 5]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        (new ApplyUsageDelta($this->connection))->apply($account, 'usage.period.gauge', 2, 'usage-before-cancel');
        (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))->cancel($owner, $account, $subscription, 'usage-close-cancel', $this->uuid());
        self::assertSame('closed', $this->connection->fetchOne('SELECT status FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
        self::assertNotNull($this->connection->fetchOne('SELECT closed_at FROM commercial.usage_periods WHERE subscription_id = ?', [$subscription]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT uc.quantity FROM commercial.usage_counters uc JOIN commercial.usage_periods up ON up.id = uc.usage_period_id WHERE up.subscription_id = ?', [$subscription]));

        [, $expireAccount, $expireProfile] = $this->readyAccount('usage-period-expire');
        $expired = $this->draftSubscription($expireAccount, $expireProfile, [$price]);
        $this->activatePaid($expireAccount, $expired, new \DateTimeImmutable('2026-01-01T00:00:00Z'), $this->uuid());
        (new ExpireSubscription($this->connection))->expire($expireAccount, $expired, new \DateTimeImmutable('2026-02-01T00:00:00Z'), $this->uuid());
        self::assertSame('closed', $this->connection->fetchOne('SELECT status FROM commercial.usage_periods WHERE subscription_id = ?', [$expired]));
    }

    public function testReplacementGetsNewOpenPeriodAndStartsAtZero(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('usage-period-replacement');
        $feature = $this->feature('usage.period.replacement', 'integer', 'counter');
        [, $price] = $this->planPrice('usage-period-replacement-plan', featureValues: [$feature => 10]);
        $old = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $old, $this->activationInstant(), $this->uuid());
        (new ApplyUsageDelta($this->connection))->apply($account, 'usage.period.replacement', 4, 'usage-old-four');
        (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))->cancel($owner, $account, $old, 'usage-old-cancel', $this->uuid());

        $new = (new CreateSubscription($this->connection, new AccountAuthorization($this->connection)))->create($owner, $account, $price, 1, 'usage-new-create', $this->uuid())['id'];
        $this->activatePaid($account, $new, $this->activationInstant(), $this->uuid());

        self::assertSame('closed', $this->connection->fetchOne('SELECT status FROM commercial.usage_periods WHERE subscription_id = ?', [$old]));
        self::assertSame('open', $this->connection->fetchOne('SELECT status FROM commercial.usage_periods WHERE subscription_id = ?', [$new]));
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT quantity FROM commercial.usage_counters uc JOIN commercial.usage_periods up ON up.id = uc.usage_period_id WHERE up.subscription_id = ?', [$old]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_counters uc JOIN commercial.usage_periods up ON up.id = uc.usage_period_id WHERE up.subscription_id = ?', [$new]));
    }
}
