<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Subscription\Application\ExpireSubscription;

final class SubscriptionExpirationTest extends SubscriptionTestCase
{
    public function testDueActiveSubscriptionExpiresOnceAndLeavesAccountActive(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('expire-due');
        $feature = $this->feature('expire.students.max', 'integer');
        [, $price] = $this->planPrice('expire-due-plan', featureValues: [$feature => 100]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $subscription, new \DateTimeImmutable('2026-01-01T00:00:00Z'), $this->uuid());
        $service = new ExpireSubscription($this->connection);

        $first = $service->expire($account, $subscription, new \DateTimeImmutable('2026-02-01T00:00:00Z'), $this->uuid());
        $second = $service->expire($account, $subscription, new \DateTimeImmutable('2026-03-01T00:00:00Z'), $this->uuid());

        self::assertSame(['id' => $subscription, 'status' => 'expired'], $first);
        self::assertSame($first, $second);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        self::assertSame('completed', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        $entitlements = (new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection)))->forAccount($owner, $account);
        self::assertNull($entitlements['subscription']);
        self::assertInstanceOf(\stdClass::class, $entitlements['features']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE actor_type = 'system' AND action = 'subscription.expired' AND resource_id = ?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.expired' AND aggregate_id = ?", [$subscription]));
        $payload = json_decode((string) $this->connection->fetchOne("SELECT payload::text FROM commercial.outbox_events WHERE event_type = 'subscription.expired' AND aggregate_id = ?", [$subscription]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($subscription, $payload['subscription_id']);
        self::assertSame($account, $payload['commercial_account_id']);
        self::assertArrayHasKey('current_period_end', $payload);
    }

    public function testExpirationBeforePeriodEndAndInvalidSourceStatesAreRejected(): void
    {
        [, $account, $profile] = $this->readyAccount('expire-invalid');
        [, $price] = $this->planPrice('expire-invalid-plan');
        $active = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $active, new \DateTimeImmutable('2026-01-01T00:00:00Z'), $this->uuid());
        $service = new ExpireSubscription($this->connection);
        try {
            $service->expire($account, $active, new \DateTimeImmutable('2026-01-31T23:59:59Z'), $this->uuid());
            self::fail('Expiration before current_period_end must fail.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$active]));

        $otherAccount = $this->readyAccount('expire-draft')[1];
        $otherProfile = (string) $this->connection->fetchOne('SELECT id FROM commercial.billing_profiles WHERE commercial_account_id = ?', [$otherAccount]);
        $draft = $this->draftSubscription($otherAccount, $otherProfile, [$price]);
        try {
            $service->expire($otherAccount, $draft, new \DateTimeImmutable('2027-01-01T00:00:00Z'), $this->uuid());
            self::fail('Draft subscriptions must not expire.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }

    public function testDueProcessingUsesBoundedBatches(): void
    {
        $subscriptions = [];
        foreach (['expire-batch-a', 'expire-batch-b'] as $suffix) {
            [, $account, $profile] = $this->readyAccount($suffix);
            [, $price] = $this->planPrice($suffix . '-plan');
            $subscription = $this->draftSubscription($account, $profile, [$price]);
            $this->activatePaid($account, $subscription, new \DateTimeImmutable('2000-01-01T00:00:00Z'), $this->uuid());
            $subscriptions[] = $subscription;
        }
        $service = new ExpireSubscription($this->connection);
        self::assertSame(1, $service->expireDue(new \DateTimeImmutable('2000-02-01T00:00:00Z'), 1, $this->uuid()));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.subscriptions WHERE id IN (?, ?) AND status = 'expired'", $subscriptions));
        self::assertSame(1, $service->expireDue(new \DateTimeImmutable('2000-02-01T00:00:00Z'), 1, $this->uuid()));
        self::assertSame(0, $service->expireDue(new \DateTimeImmutable('2000-02-01T00:00:00Z'), 1, $this->uuid()));
    }
}
