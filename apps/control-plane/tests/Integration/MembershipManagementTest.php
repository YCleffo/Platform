<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\CreateCommercialAccount;
use ControlPlane\Account\Application\MembershipService;
use ControlPlane\Account\Authorization\AccountAuthorization;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class MembershipManagementTest extends DatabaseTestCase
{
    public function testRoleChangesAndRemovalRespectAccountPolicy(): void
    {
        $owner = $this->user('mgmt-owner@example.test');
        $admin = $this->user('mgmt-admin@example.test');
        $billing = $this->user('mgmt-billing@example.test');
        $correlation = $this->uuid();
        $account = (new CreateCommercialAccount($this->connection))->create($owner, 'mgmt-account', 'Mgmt', null, 'mgmt-key', $correlation);
        $authorization = new AccountAuthorization($this->connection);
        $service = new MembershipService($this->connection, $authorization);

        $service->add($owner, $account, $admin, 'admin', $this->uuid());
        $billingMembership = $service->add($owner, $account, $billing, 'billing', $this->uuid());

        $service->changeRole($admin, $account, $billingMembership, 'technical', $this->uuid());
        self::assertSame('technical', $this->connection->fetchOne('SELECT role FROM commercial.commercial_account_memberships WHERE id = ?', [$billingMembership]));

        try {
            $service->changeRole($admin, $account, $billingMembership, 'owner', $this->uuid());
            self::fail('Admin granted ownership.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $ownerMembership = (string) $this->connection->fetchOne(
            "SELECT id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner'",
            [$account],
        );
        try {
            $service->changeRole($admin, $account, $ownerMembership, 'admin', $this->uuid());
            self::fail('Admin changed the owner membership.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $service->remove($admin, $account, $billingMembership, $this->uuid());
        self::assertNotNull($this->connection->fetchOne('SELECT revoked_at FROM commercial.commercial_account_memberships WHERE id = ?', [$billingMembership]));

        try {
            $service->remove($owner, $account, $ownerMembership, $this->uuid());
            self::fail('The last owner was removed.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        self::assertGreaterThanOrEqual(1, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM commercial.audit_events WHERE commercial_account_id = ? AND action = 'commercial_membership.role_changed'",
            [$account],
        ));
        self::assertGreaterThanOrEqual(1, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM commercial.audit_events WHERE commercial_account_id = ? AND action = 'commercial_membership.removed'",
            [$account],
        ));
    }

    public function testCrossAccountMembershipManagementIsDenied(): void
    {
        $owner = $this->user('mgmt-cross-a@example.test');
        $stranger = $this->user('mgmt-cross-b@example.test');
        $creator = new CreateCommercialAccount($this->connection);
        $accountA = $creator->create($owner, 'mgmt-cross-account-a', 'Cross A', null, 'mgmt-cross-a', $this->uuid());
        $accountB = $creator->create($stranger, 'mgmt-cross-account-b', 'Cross B', null, 'mgmt-cross-b', $this->uuid());
        $service = new MembershipService($this->connection, new AccountAuthorization($this->connection));

        $foreignMembership = (string) $this->connection->fetchOne(
            "SELECT id FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner'",
            [$accountB],
        );

        $this->expectException(AccessDeniedException::class);
        $service->changeRole($owner, $accountB, $foreignMembership, 'admin', $this->uuid());
    }
}
