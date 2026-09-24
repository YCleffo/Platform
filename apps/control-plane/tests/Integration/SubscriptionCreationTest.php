<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Catalog\Application\PlanCatalog;
use ControlPlane\Subscription\Application\CreateSubscription;
use ControlPlane\Subscription\Application\GetSubscription;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SubscriptionCreationTest extends DatabaseTestCase
{
    public function testReadyAccountCreatesDraftAtomicallyAndCanReadIt(): void
    {
        [$owner, $account, $profile, $plan, $price] = $this->readyContext('success');
        $service = $this->creator();
        $result = $service->create($owner, $account, $price, 2, 'subscription-success', $this->uuid());

        self::assertSame('draft', $result['status']);
        self::assertSame($account, $this->connection->fetchOne('SELECT commercial_account_id FROM commercial.subscriptions WHERE id = ?', [$result['id']]));
        self::assertSame($profile, $this->connection->fetchOne('SELECT billing_profile_id FROM commercial.subscriptions WHERE id = ?', [$result['id']]));
        self::assertNull($this->connection->fetchOne('SELECT current_period_start FROM commercial.subscriptions WHERE id = ?', [$result['id']]));
        self::assertSame($price, $this->connection->fetchOne('SELECT plan_price_id FROM commercial.subscription_items WHERE subscription_id = ?', [$result['id']]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT quantity FROM commercial.subscription_items WHERE subscription_id = ?', [$result['id']]));
        self::assertSame('ready_for_subscription', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$account]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.created' AND resource_id = ?", [$result['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.created' AND aggregate_id = ?", [$result['id']]));

        $view = (new GetSubscription($this->connection, new AccountAuthorization($this->connection)))->get($owner, $account, $result['id']);
        self::assertSame($result['id'], $view['id']);
        self::assertSame($account, $view['commercial_account_id']);
        self::assertSame($profile, $view['billing_profile_id']);
        self::assertSame($plan, $view['items'][0]['plan']['id']);
        self::assertSame('success-plan', $view['items'][0]['plan']['code']);
        self::assertSame($price, $view['items'][0]['price']['id']);
        self::assertSame('month', $view['items'][0]['price']['billing_period']);

        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'retired', retired_at = now() WHERE id = ?", [$plan]);
        $historicalView = (new GetSubscription($this->connection, new AccountAuthorization($this->connection)))->get($owner, $account, $result['id']);
        self::assertSame($plan, $historicalView['items'][0]['plan']['id']);
    }

    public function testPublishedCatalogExposesPriceId(): void
    {
        [, , , , $price] = $this->readyContext('catalog');
        $catalog = (new PlanCatalog($this->connection))->published();

        $priceIds = [];
        foreach ($catalog as $plan) {
            foreach ($plan['prices'] as $catalogPrice) {
                $priceIds[] = $catalogPrice['id'];
            }
        }
        self::assertContains($price, $priceIds);
    }

    public function testCentralBillingPermissionAllowsBillingAndDeniesTechnicalAndCrossAccount(): void
    {
        [$owner, $account, , , $price] = $this->readyContext('authorization');
        $billing = $this->user('billing-subscription@example.test');
        $technical = $this->user('technical-subscription@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'billing'), (?, ?, 'technical')", [$billing, $account, $technical, $account]);
        $result = $this->creator()->create($billing, $account, $price, 1, 'billing-allowed', $this->uuid());
        self::assertSame('draft', $result['status']);

        [$otherOwner, $otherAccount, , , $otherPrice] = $this->readyContext('authorization-other');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'technical')", [$technical, $otherAccount]);
        try {
            $this->creator()->create($technical, $otherAccount, $otherPrice, 1, 'technical-denied', $this->uuid());
            self::fail('Technical membership must not grant billing.manage.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        try {
            $this->creator()->create($owner, $otherAccount, $otherPrice, 1, 'cross-account-denied', $this->uuid());
            self::fail('A foreign account identifier must not cross the membership boundary.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        try {
            (new GetSubscription($this->connection, new AccountAuthorization($this->connection)))->get($otherOwner, $account, $result['id']);
            self::fail('A subscription must not be readable across the account boundary.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
    }

    public function testInvalidPlanStatesAndOneTimePriceAreRejected(): void
    {
        [$owner, $account] = $this->readyContext('invalid-catalog');
        $draftPrice = $this->planPrice('draft-plan', 'draft', 'month');
        $retiredPrice = $this->planPrice('retired-plan', 'retired', 'month');
        $oneTimePrice = $this->planPrice('one-time-plan', 'published', 'one_time');

        foreach ([$draftPrice, $retiredPrice, $oneTimePrice, $this->uuid()] as $index => $price) {
            try {
                $this->creator()->create($owner, $account, $price, 1, 'invalid-price-' . $index, $this->uuid());
                self::fail('Invalid catalog selection must be rejected.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscriptions WHERE commercial_account_id = ?', [$account]));
    }

    public function testAccountStateBillingProfileAndQuantityAreRequired(): void
    {
        [$owner, $account] = $this->accountWithProfile('requirements', false);
        $price = $this->planPrice('requirements-plan', 'published', 'month');
        try {
            $this->creator()->create($owner, $account, $price, 1, 'not-ready', $this->uuid());
            self::fail('Non-ready account must be rejected.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        [$noProfileOwner, $noProfileAccount, $profile, , $noProfilePrice] = $this->readyContext('no-profile');
        $this->connection->executeStatement('UPDATE commercial.billing_profiles SET archived_at = now() WHERE id = ?', [$profile]);
        try {
            $this->creator()->create($noProfileOwner, $noProfileAccount, $noProfilePrice, 1, 'no-profile', $this->uuid());
            self::fail('An active billing profile is required.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        foreach ([0, -1] as $quantity) {
            try {
                $this->creator()->create($noProfileOwner, $noProfileAccount, $noProfilePrice, $quantity, 'quantity-' . $quantity, $this->uuid());
                self::fail('Non-positive quantity must be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testIdempotencyReturnsSameSubscriptionAndChangedPayloadConflicts(): void
    {
        [$owner, $account, , , $price] = $this->readyContext('idempotency');
        $service = $this->creator();
        $first = $service->create($owner, $account, $price, 1, 'same-request', $this->uuid());
        $second = $service->create($owner, $account, $price, 1, 'same-request', $this->uuid());

        self::assertSame($first, $second);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscriptions WHERE commercial_account_id = ?', [$account]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscription_items WHERE subscription_id = ?', [$first['id']]));
        try {
            $service->create($owner, $account, $price, 2, 'same-request', $this->uuid());
            self::fail('Changed idempotent payload must conflict.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }

    public function testItemFailureRollsBackWholeCreation(): void
    {
        [$owner, $account, , , $price] = $this->readyContext('rollback');
        $this->connection->executeStatement("CREATE FUNCTION commercial.reject_test_subscription_item() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''forced item failure''; END'");
        $this->connection->executeStatement('CREATE TRIGGER reject_test_subscription_item BEFORE INSERT ON commercial.subscription_items FOR EACH ROW EXECUTE FUNCTION commercial.reject_test_subscription_item()');

        try {
            $this->creator()->create($owner, $account, $price, 1, 'rollback-request', $this->uuid());
            self::fail('Forced item failure must abort creation.');
        } catch (\Doctrine\DBAL\Exception) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.subscriptions WHERE commercial_account_id = ?', [$account]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'subscription.created' AND commercial_account_id = ?", [$account]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'subscription.created' AND payload->>'commercial_account_id' = ?", [$account]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.idempotency_keys WHERE idempotency_key = 'rollback-request'"));
    }

    private function creator(): CreateSubscription
    {
        return new CreateSubscription($this->connection, new AccountAuthorization($this->connection));
    }

    /** @return array{string, string, string, string, string} */
    private function readyContext(string $suffix): array
    {
        [$owner, $account, $profile] = $this->accountWithProfile($suffix, true);
        $plan = $this->connection->fetchOne('INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, ?) RETURNING id', [$suffix . '-plan', ucfirst($suffix) . ' Plan']);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 1000, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);

        return [$owner, $account, $profile, $plan, $price];
    }

    /** @return array{string, string, string} */
    private function accountWithProfile(string $suffix, bool $ready): array
    {
        $owner = $this->user($suffix . '-owner@example.test');
        $account = $this->connection->fetchOne('INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, ?) RETURNING id', [$suffix . '-account', ucfirst($suffix) . ' Account']);
        self::assertIsString($account);
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$owner, $account]);
        $profile = $this->connection->fetchOne("INSERT INTO commercial.billing_profiles (commercial_account_id, legal_name, country_code, is_default) VALUES (?, 'Example LLC', 'US', true) RETURNING id", [$account]);
        self::assertIsString($profile);
        $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'catalog_selection_pending' WHERE id = ?", [$account]);
        if ($ready) {
            $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription' WHERE id = ?", [$account]);
        }

        return [$owner, $account, $profile];
    }

    private function planPrice(string $code, string $status, string $period): string
    {
        $plan = $this->connection->fetchOne('INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, ?) RETURNING id', [$code, ucfirst($code)]);
        self::assertIsString($plan);
        $price = $this->connection->fetchOne('INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, ?, ?, ?) RETURNING id', [$plan, 'USD', 1000, $period]);
        self::assertIsString($price);
        if ($status !== 'draft') {
            $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        }
        if ($status === 'retired') {
            $this->connection->executeStatement("UPDATE commercial.plans SET status = 'retired', retired_at = now() WHERE id = ?", [$plan]);
        }

        return $price;
    }
}
