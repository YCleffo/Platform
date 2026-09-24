<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\BillingProfileService;
use ControlPlane\Account\Application\CreateCommercialAccount;
use ControlPlane\Account\Application\MembershipService;
use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Catalog\Application\PlanCatalog;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccountOnboardingTest extends DatabaseTestCase
{
    public function testOwnerBillingOnboardingAndPublishedCatalog(): void
    {
        $owner = $this->user('owner@example.test');
        $correlation = $this->uuid();
        $account = (new CreateCommercialAccount($this->connection))->create($owner, 'academy', 'Academy', null, 'account-request', $correlation);
        $authorization = new AccountAuthorization($this->connection);
        self::assertSame('owner', $authorization->requirePermission($owner, $account, 'billing.manage'));

        $profile = (new BillingProfileService($this->connection, $authorization))->save($owner, $account, 'Academy LLC', 'US', 'Billing Team', 'BILLING@example.test', 'TAX-1', ['city' => 'New York'], $correlation);
        self::assertNotEmpty($profile);
        self::assertSame('catalog_selection_pending', $this->connection->fetchOne('SELECT onboarding_state FROM commercial.commercial_accounts WHERE id = ?', [$account]));

        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES ('starter', 1, 'Starter') RETURNING id");
        $this->connection->executeStatement("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 1000, 'month')", [$plan]);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $catalog = (new PlanCatalog($this->connection))->published();
        self::assertCount(1, $catalog);
        self::assertSame('starter', $catalog[0]['code']);
    }

    public function testCrossAccountAccessIsDeniedAndLastOwnerIsProtected(): void
    {
        $ownerA = $this->user('owner-a@example.test');
        $ownerB = $this->user('owner-b@example.test');
        $correlation = $this->uuid();
        $creator = new CreateCommercialAccount($this->connection);
        $accountA = $creator->create($ownerA, 'account-a', 'Account A', null, 'request-a', $correlation);
        $accountB = $creator->create($ownerB, 'account-b', 'Account B', null, 'request-b', $correlation);
        $authorization = new AccountAuthorization($this->connection);

        try {
            $authorization->requirePermission($ownerA, $accountB, 'account.read');
            self::fail('Cross-account access must be denied.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $memberships = new MembershipService($this->connection, $authorization);
        $ownerMembership = (string) $this->connection->fetchOne("SELECT id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner'", [$accountA]);
        $this->expectException(\DomainException::class);
        $memberships->changeRole($ownerA, $accountA, $ownerMembership, 'admin', $correlation);
    }

    public function testMembershipRolesUseCentralPolicyAndCanPreserveOwnership(): void
    {
        $owner = $this->user('owner-c@example.test');
        $second = $this->user('second@example.test');
        $correlation = $this->uuid();
        $account = (new CreateCommercialAccount($this->connection))->create($owner, 'account-c', 'Account C', null, 'request-c', $correlation);
        $authorization = new AccountAuthorization($this->connection);
        $memberships = new MembershipService($this->connection, $authorization);
        $secondMembership = $memberships->add($owner, $account, $second, 'owner', $correlation);
        $firstMembership = (string) $this->connection->fetchOne('SELECT id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND control_user_id = ?', [$account, $owner]);
        $memberships->changeRole($owner, $account, $firstMembership, 'admin', $correlation);
        self::assertSame('owner', $this->connection->fetchOne('SELECT role FROM commercial.commercial_account_memberships WHERE id = ?', [$secondMembership]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner' AND revoked_at IS NULL", [$account]));
    }

    public function testInvalidOnboardingTransitionIsRejectedByDatabase(): void
    {
        $account = $this->account('invalid-transition');
        $this->expectDatabaseRejection(fn () => $this->connection->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state = 'ready_for_subscription' WHERE id = ?", [$account]));
    }
}
