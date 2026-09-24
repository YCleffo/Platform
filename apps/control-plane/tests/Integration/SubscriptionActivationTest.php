<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Subscription\Application\GetSubscription;

final class SubscriptionActivationTest extends SubscriptionTestCase
{
    public function testHappyPathActivatesSubscriptionAndCompletesAccountAtomically(): void
    {
        [, $account, $profile] = $this->readyAccount('activation-happy');
        $feature = $this->feature('activation.students.max', 'integer');
        [, $price] = $this->planPrice('activation-happy-plan', featureValues: [$feature => 500]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);

        $result = $this->activator()->activate($account, $subscription, $this->activationInstant(), $this->uuid());

        self::assertSame('active', $result['status']);
        self::assertNotEmpty($result['current_period_start']);
        self::assertNotEmpty($result['current_period_end']);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        self::assertSame('completed', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.activated' AND resource_id = ?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.activated' AND aggregate_id = ?", [$subscription]));
        $payload = json_decode((string) $this->connection->fetchOne("SELECT payload::text FROM commercial.outbox_events WHERE event_type = 'subscription.activated' AND aggregate_id = ?", [$subscription]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($subscription, $payload['subscription_id']);
        self::assertSame($account, $payload['commercial_account_id']);
        self::assertArrayHasKey('current_period_start', $payload);
        self::assertArrayHasKey('current_period_end', $payload);
        $view = (new GetSubscription($this->connection, new AccountAuthorization($this->connection)))->get((string) $this->connection->fetchOne("SELECT control_user_id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner'", [$account]), $account, $subscription);
        self::assertSame($result['current_period_start'], $view['current_period_start']);
        self::assertSame($result['current_period_end'], $view['current_period_end']);
    }

    public function testActivationRetryReturnsStablePeriodAndSingleEvents(): void
    {
        [, $account, $profile] = $this->readyAccount('activation-retry');
        [, $price] = $this->planPrice('activation-retry-plan');
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $activator = $this->activator();
        $first = $activator->activate($account, $subscription, new \DateTimeImmutable('2026-01-31T12:00:00Z'), $this->uuid());
        $second = $activator->activate($account, $subscription, new \DateTimeImmutable('2026-05-01T00:00:00Z'), $this->uuid());

        self::assertSame($first, $second);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.activated' AND resource_id = ?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.activated' AND aggregate_id = ?", [$subscription]));
    }

    public function testInvalidDraftContractsRollBackWithoutActivationArtifacts(): void
    {
        $cases = [
            'missing-items' => function (string $account, string $profile): string {
                return $this->draftSubscription($account, $profile, []);
            },
            'retired-plan' => function (string $account, string $profile): string {
                [, $price] = $this->planPrice('activation-retired-plan', status: 'retired');

                return $this->draftSubscription($account, $profile, [$price]);
            },
            'one-time' => function (string $account, string $profile): string {
                [, $price] = $this->planPrice('activation-one-time-plan', 'one_time');

                return $this->draftSubscription($account, $profile, [$price]);
            },
            'archived-profile' => function (string $account, string $profile): string {
                [, $price] = $this->planPrice('activation-archived-profile-plan');
                $subscription = $this->draftSubscription($account, $profile, [$price]);
                $this->connection->executeStatement('UPDATE commercial.billing_profiles SET archived_at = now() WHERE id = ?', [$profile]);

                return $subscription;
            },
            'mixed-period' => function (string $account, string $profile): string {
                [, $monthly] = $this->planPrice('activation-month-plan', 'month');
                [, $yearly] = $this->planPrice('activation-year-plan', 'year');

                return $this->draftSubscription($account, $profile, [$monthly, $yearly]);
            },
            'mixed-currency' => function (string $account, string $profile): string {
                [, $usd] = $this->planPrice('activation-usd-plan', currency: 'USD');
                [, $eur] = $this->planPrice('activation-eur-plan', currency: 'EUR');

                return $this->draftSubscription($account, $profile, [$usd, $eur]);
            },
        ];

        foreach ($cases as $name => $fixture) {
            [, $account, $profile] = $this->readyAccount('invalid-' . $name);
            $subscription = $fixture($account, $profile);
            try {
                $this->activator()->activate($account, $subscription, $this->activationInstant(), $this->uuid());
                self::fail('Invalid activation fixture must fail: ' . $name);
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
            self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
            self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.commercial_accounts WHERE id = ?', [$account]));
            self::assertSame('ready_for_subscription', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$account]));
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.activated' AND resource_id = ?", [$subscription]));
            self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.activated' AND aggregate_id = ?", [$subscription]));
        }
    }

    public function testConflictingFeatureValuesRejectActivationButIdenticalValuesDeduplicate(): void
    {
        $feature = $this->feature('composition.students.max', 'integer');
        [, $conflictAccount, $conflictProfile] = $this->readyAccount('activation-conflict');
        [, $priceA] = $this->planPrice('activation-conflict-a', featureValues: [$feature => 100]);
        [, $priceB] = $this->planPrice('activation-conflict-b', featureValues: [$feature => 500]);
        $conflictSubscription = $this->draftSubscription($conflictAccount, $conflictProfile, [$priceA, $priceB]);
        try {
            $this->activator()->activate($conflictAccount, $conflictSubscription, $this->activationInstant(), $this->uuid());
            self::fail('Conflicting feature values must reject activation.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$conflictSubscription]));
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.commercial_accounts WHERE id = ?', [$conflictAccount]));
        self::assertSame('ready_for_subscription', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$conflictAccount]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.activated' AND resource_id = ?", [$conflictSubscription]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.activated' AND aggregate_id = ?", [$conflictSubscription]));

        $sameFeature = $this->feature('composition.module.crm', 'boolean');
        [, $sameAccount, $sameProfile] = $this->readyAccount('activation-identical');
        [, $samePriceA] = $this->planPrice('activation-identical-a', featureValues: [$sameFeature => true]);
        [, $samePriceB] = $this->planPrice('activation-identical-b', featureValues: [$sameFeature => true]);
        $sameSubscription = $this->draftSubscription($sameAccount, $sameProfile, [$samePriceA, $samePriceB]);
        self::assertSame('active', $this->activator()->activate($sameAccount, $sameSubscription, $this->activationInstant(), $this->uuid())['status']);
    }
}
