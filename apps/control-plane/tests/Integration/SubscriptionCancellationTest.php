<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Subscription\Application\CancelSubscription;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SubscriptionCancellationTest extends SubscriptionTestCase
{
    public function testDraftAndActiveSubscriptionsCanBeCancelledWithoutChangingAccountLifecycle(): void
    {
        [$draftOwner, $draftAccount, $draftProfile] = $this->readyAccount('cancel-draft');
        [, $draftPrice] = $this->planPrice('cancel-draft-plan');
        $draft = $this->draftSubscription($draftAccount, $draftProfile, [$draftPrice]);

        self::assertSame('cancelled', $this->canceller()->cancel($draftOwner, $draftAccount, $draft, 'cancel-draft', $this->uuid())['status']);
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.commercial_accounts WHERE id = ?', [$draftAccount]));
        self::assertSame('ready_for_subscription', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$draftAccount]));

        [$owner, $account, $profile] = $this->readyAccount('cancel-active');
        $feature = $this->feature('cancel.module.crm', 'boolean');
        [, $price] = $this->planPrice('cancel-active-plan', featureValues: [$feature => true]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        self::assertIsArray($this->entitlements()->forAccount($owner, $account)['features']);

        $result = $this->canceller()->cancel($owner, $account, $subscription, 'cancel-active', $this->uuid());

        self::assertSame(['id' => $subscription, 'status' => 'cancelled'], $result);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        self::assertSame('completed', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        self::assertNull($this->entitlements()->forAccount($owner, $account)['subscription']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.cancelled' AND resource_id = ?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.cancelled' AND aggregate_id = ?", [$subscription]));
        $payload = json_decode((string) $this->connection->fetchOne("SELECT payload::text FROM commercial.outbox_events WHERE event_type = 'subscription.cancelled' AND aggregate_id = ?", [$subscription]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['subscription_id' => $subscription, 'commercial_account_id' => $account], $payload);
    }

    public function testCancellationIsIdempotentAndRejectsExpiredOrCrossResourceKeyReuse(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('cancel-idempotent');
        [, $price] = $this->planPrice('cancel-idempotent-plan');
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $service = $this->canceller();
        $first = $service->cancel($owner, $account, $subscription, 'same-cancel', $this->uuid());
        $second = $service->cancel($owner, $account, $subscription, 'same-cancel', $this->uuid());
        $third = $service->cancel($owner, $account, $subscription, 'new-cancel-key', $this->uuid());

        self::assertSame($first, $second);
        self::assertSame($first, $third);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.cancelled' AND resource_id = ?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.cancelled' AND aggregate_id = ?", [$subscription]));

        $replacement = $this->draftSubscription($account, $profile, [$price]);
        try {
            $service->cancel($owner, $account, $replacement, 'same-cancel', $this->uuid());
            self::fail('An idempotency key must not be reused for another subscription.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$replacement]));

        $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'active', current_period_start = now() - interval '2 months', current_period_end = now() - interval '1 month' WHERE id = ?", [$replacement]);
        $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'expired' WHERE id = ?", [$replacement]);
        try {
            $service->cancel($owner, $account, $replacement, 'expired-cancel', $this->uuid());
            self::fail('An expired subscription must not become cancelled.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$replacement]));
    }

    public function testBillingPermissionAndAccountBoundaryAreEnforced(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('cancel-auth');
        [, $price] = $this->planPrice('cancel-auth-plan');
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $technical = $this->user('cancel-technical@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'technical')", [$technical, $account]);
        [$foreignOwner] = $this->readyAccount('cancel-foreign');

        foreach ([$technical, $foreignOwner] as $index => $actor) {
            try {
                $this->canceller()->cancel($actor, $account, $subscription, 'denied-' . $index, $this->uuid());
                self::fail('Cancellation without billing.manage must be denied.');
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('draft', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame('cancelled', $this->canceller()->cancel($owner, $account, $subscription, 'allowed', $this->uuid())['status']);
    }

    private function canceller(): CancelSubscription
    {
        return new CancelSubscription($this->connection, new AccountAuthorization($this->connection));
    }

    private function entitlements(): EffectiveEntitlements
    {
        return new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection));
    }
}
