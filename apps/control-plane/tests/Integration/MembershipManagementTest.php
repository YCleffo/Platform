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

    public function testAdminCannotDemoteActiveOwnerThroughAdd(): void
    {
        $owner = $this->user('add-owner@example.test');
        $admin = $this->user('add-admin@example.test');
        $account = $this->createAccount($owner, 'add-owner-account');
        $service = $this->service();
        $service->add($owner, $account, $admin, 'admin', $this->uuid());

        try {
            $service->add($admin, $account, $owner, 'billing', $this->uuid());
            self::fail('Admin demoted an owner through add().');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        self::assertSame('owner', $this->connection->fetchOne(
            'SELECT role FROM commercial.commercial_account_memberships WHERE control_user_id = ? AND commercial_account_id = ?',
            [$owner, $account],
        ));
    }

    public function testOwnerAddIsIdempotentAndRoleChangesRequireTheDedicatedEndpoint(): void
    {
        $owner = $this->user('add-idempotent-owner@example.test');
        $member = $this->user('add-idempotent-member@example.test');
        $account = $this->createAccount($owner, 'add-idempotent-account');
        $service = $this->service();

        $first = $service->add($owner, $account, $member, 'technical', $this->uuid());
        $second = $service->add($owner, $account, $member, 'technical', $this->uuid());
        self::assertSame($first, $second);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commercial.commercial_account_memberships WHERE control_user_id = ? AND commercial_account_id = ?',
            [$member, $account],
        ));

        try {
            $service->add($owner, $account, $member, 'billing', $this->uuid());
            self::fail('add() changed an active membership role.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('technical', $this->connection->fetchOne(
            'SELECT role FROM commercial.commercial_account_memberships WHERE id = ?',
            [$first],
        ));
    }

    public function testRevokedMembershipReactivationIsAuthorizedAndAudited(): void
    {
        $owner = $this->user('add-reactivate-owner@example.test');
        $member = $this->user('add-reactivate-member@example.test');
        $account = $this->createAccount($owner, 'add-reactivate-account');
        $service = $this->service();

        $membershipId = $service->add($owner, $account, $member, 'billing', $this->uuid());
        $service->remove($owner, $account, $membershipId, $this->uuid());
        $reactivated = $service->add($owner, $account, $member, 'technical', $this->uuid());

        self::assertSame($membershipId, $reactivated);
        $row = $this->connection->fetchAssociative(
            'SELECT role, revoked_at FROM commercial.commercial_account_memberships WHERE id = ?',
            [$membershipId],
        );
        self::assertIsArray($row);
        self::assertSame('technical', $row['role']);
        self::assertNull($row['revoked_at']);
        self::assertGreaterThanOrEqual(1, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM commercial.audit_events WHERE commercial_account_id = ? AND action = 'commercial_membership.reactivated'",
            [$account],
        ));
    }

    public function testAdminCannotReactivateRevokedOwnerRowAsNonOwner(): void
    {
        $owner = $this->user('add-revoked-owner@example.test');
        $admin = $this->user('add-revoked-admin@example.test');
        $secondOwner = $this->user('add-revoked-second-owner@example.test');
        $account = $this->createAccount($owner, 'add-revoked-account');
        $service = $this->service();
        $service->add($owner, $account, $admin, 'admin', $this->uuid());
        $secondOwnerMembership = $service->add($owner, $account, $secondOwner, 'owner', $this->uuid());
        $service->remove($owner, $account, $secondOwnerMembership, $this->uuid());

        try {
            $service->add($admin, $account, $secondOwner, 'billing', $this->uuid());
            self::fail('Admin reactivated a revoked owner row as billing.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT role, revoked_at FROM commercial.commercial_account_memberships WHERE id = ?',
            [$secondOwnerMembership],
        );
        self::assertIsArray($row);
        self::assertSame('owner', $row['role']);
        self::assertNotNull($row['revoked_at']);
    }

    public function testInitiatorLosingPermissionIsRejected(): void
    {
        $owner = $this->user('add-lost-owner@example.test');
        $admin = $this->user('add-lost-admin@example.test');
        $target = $this->user('add-lost-target@example.test');
        $account = $this->createAccount($owner, 'add-lost-account');
        $service = $this->service();
        $adminMembership = $service->add($owner, $account, $admin, 'admin', $this->uuid());
        $service->remove($owner, $account, $adminMembership, $this->uuid());

        $this->expectException(AccessDeniedException::class);
        $service->add($admin, $account, $target, 'billing', $this->uuid());
    }

    private function createAccount(string $owner, string $code): string
    {
        return (new CreateCommercialAccount($this->connection))->create($owner, $code, 'Policy Account', null, $code . '-key', $this->uuid());
    }

    private function service(): MembershipService
    {
        return new MembershipService($this->connection, new AccountAuthorization($this->connection));
    }
}
