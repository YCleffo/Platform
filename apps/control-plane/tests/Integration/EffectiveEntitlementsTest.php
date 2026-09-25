<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class EffectiveEntitlementsTest extends SubscriptionTestCase
{
    public function testNoCurrentActiveSubscriptionReturnsEmptyReadModel(): void
    {
        [$owner, $account] = $this->readyAccount('entitlements-empty');
        $result = $this->resolver()->forAccount($owner, $account);

        self::assertSame($account, $result['commercial_account_id']);
        self::assertNull($result['subscription']);
        self::assertInstanceOf(\stdClass::class, $result['features']);
        self::assertSame('{}', json_encode($result['features'], JSON_THROW_ON_ERROR));
    }

    public function testActiveSubscriptionExposesTypedFeaturesWithoutQuantityMultiplicationAndSurvivesPlanRetirement(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('entitlements-typed');
        $boolean = $this->feature('typed.module.crm', 'boolean');
        $integer = $this->feature('typed.students.max', 'integer');
        $string = $this->feature('typed.edition', 'string');
        $json = $this->feature('typed.custom.policy', 'json');
        [$plan, $price] = $this->planPrice('entitlements-typed-plan', featureValues: [
            $boolean => true,
            $integer => 100,
            $string => 'pro',
            $json => ['x' => 1],
        ]);
        $subscription = $this->draftSubscription($account, $profile, [$price], 5);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());

        $features = $this->resolver()->forAccount($owner, $account)['features'];
        self::assertIsArray($features);
        self::assertTrue($features['typed.module.crm']);
        self::assertSame(100, $features['typed.students.max']);
        self::assertSame('pro', $features['typed.edition']);
        self::assertInstanceOf(\stdClass::class, $features['typed.custom.policy']);
        self::assertSame(1, $features['typed.custom.policy']->x);

        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'retired', retired_at = now() WHERE id = ?", [$plan]);
        $retiredFeatures = $this->resolver()->forAccount($owner, $account)['features'];
        self::assertIsArray($retiredFeatures);
        self::assertSame(100, $retiredFeatures['typed.students.max']);
    }

    public function testItemAndSubscriptionValidityWindowsAreApplied(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('entitlements-item-window');
        $currentFeature = $this->feature('window.current', 'boolean');
        $futureFeature = $this->feature('window.future', 'boolean');
        [, $currentPrice] = $this->planPrice('window-current-plan', featureValues: [$currentFeature => true]);
        [, $futurePrice] = $this->planPrice('window-future-plan', featureValues: [$futureFeature => true]);
        $subscription = $this->draftSubscription($account, $profile, [$currentPrice, $futurePrice]);
        $this->connection->executeStatement("UPDATE commercial.subscription_items SET valid_from = now() + interval '1 day' WHERE subscription_id = ? AND plan_price_id = ?", [$subscription, $futurePrice]);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        $features = $this->resolver()->forAccount($owner, $account)['features'];
        self::assertIsArray($features);
        self::assertArrayHasKey('window.current', $features);
        self::assertArrayNotHasKey('window.future', $features);

        [$pastOwner, $pastAccount, $pastProfile] = $this->readyAccount('entitlements-past-window');
        [, $pastPrice] = $this->planPrice('window-past-plan');
        $pastSubscription = $this->draftSubscription($pastAccount, $pastProfile, [$pastPrice]);
        $this->connection->executeStatement("UPDATE commercial.subscriptions SET status = 'active', current_period_start = now() - interval '2 months', current_period_end = now() - interval '1 month' WHERE id = ?", [$pastSubscription]);
        $past = $this->resolver()->forAccount($pastOwner, $pastAccount);
        self::assertNull($past['subscription']);
        self::assertInstanceOf(\stdClass::class, $past['features']);
    }

    public function testIdenticalValuesDeduplicateAndAccountReadBoundaryIsEnforced(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('entitlements-deduplicate');
        $feature = $this->feature('deduplicate.module.crm', 'boolean');
        [, $firstPrice] = $this->planPrice('deduplicate-plan-a', featureValues: [$feature => true]);
        [, $secondPrice] = $this->planPrice('deduplicate-plan-b', featureValues: [$feature => true]);
        $subscription = $this->draftSubscription($account, $profile, [$firstPrice, $secondPrice]);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());

        $reader = $this->user('entitlement-reader@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'technical')", [$reader, $account]);
        $features = $this->resolver()->forAccount($reader, $account)['features'];
        self::assertIsArray($features);
        self::assertSame(['deduplicate.module.crm' => true], $features);

        [, $foreignAccount] = $this->readyAccount('entitlements-foreign');
        try {
            $this->resolver()->forAccount($owner, $foreignAccount);
            self::fail('Foreign account entitlements must not cross the membership boundary.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
    }

    private function resolver(): EffectiveEntitlements
    {
        return new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection));
    }
}
