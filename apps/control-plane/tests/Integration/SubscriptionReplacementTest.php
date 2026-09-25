<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Subscription\Application\CancelSubscription;
use ControlPlane\Subscription\Application\CreateSubscription;
use ControlPlane\Subscription\Application\ExpireSubscription;

final class SubscriptionReplacementTest extends SubscriptionTestCase
{
    public function testCancelledSubscriptionCanBeReplacedAndNewEntitlementsTakeEffect(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('replace-cancelled');
        $oldFeature = $this->feature('replace.old', 'boolean');
        $newFeature = $this->feature('replace.new', 'boolean');
        [, $oldPrice] = $this->planPrice('replace-old-plan', featureValues: [$oldFeature => true]);
        [, $newPrice] = $this->planPrice('replace-new-plan', featureValues: [$newFeature => true]);
        $old = $this->draftSubscription($account, $profile, [$oldPrice]);
        $this->activatePaid($account, $old, $this->activationInstant(), $this->uuid());
        (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))->cancel($owner, $account, $old, 'replace-cancel-old', $this->uuid());

        $new = $this->creator()->create($owner, $account, $newPrice, 1, 'replace-create-new', $this->uuid())['id'];
        $this->activatePaid($account, $new, $this->activationInstant(), $this->uuid());

        self::assertNotSame($old, $new);
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$old]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$new]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.subscriptions WHERE commercial_account_id = ? AND status = 'active'", [$account]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        self::assertSame('completed', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        $entitlements = (new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection)))->forAccount($owner, $account);
        self::assertSame($new, $entitlements['subscription']['id']);
        self::assertSame(['replace.new' => true], $entitlements['features']);
    }

    public function testExpiredSubscriptionCanBeReplacedButActiveSubscriptionCannot(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('replace-expired');
        [, $oldPrice] = $this->planPrice('replace-expired-old');
        [, $newPrice] = $this->planPrice('replace-expired-new');
        $old = $this->draftSubscription($account, $profile, [$oldPrice]);
        $this->activatePaid($account, $old, new \DateTimeImmutable('2026-01-01T00:00:00Z'), $this->uuid());

        try {
            $this->creator()->create($owner, $account, $newPrice, 1, 'replace-while-active', $this->uuid());
            self::fail('Replacement must not be created while another subscription is active.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        (new ExpireSubscription($this->connection))->expire($account, $old, new \DateTimeImmutable('2026-02-01T00:00:00Z'), $this->uuid());
        $replacement = $this->creator()->create($owner, $account, $newPrice, 1, 'replace-after-expiry', $this->uuid());

        self::assertSame('draft', $replacement['status']);
        self::assertNotSame($old, $replacement['id']);
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$old]));
    }

    private function creator(): CreateSubscription
    {
        return new CreateSubscription($this->connection, new AccountAuthorization($this->connection));
    }
}
