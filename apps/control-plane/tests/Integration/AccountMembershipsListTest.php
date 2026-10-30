<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\ListAccountMemberships;
use ControlPlane\Account\Authorization\AccountAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccountMembershipsListTest extends DatabaseTestCase
{
    public function testListsActiveMembershipsOnlyWithSafeFields(): void
    {
        $accountId = $this->accountWithoutMembership('members-active');
        $owner = $this->user('owner@example.test');
        $admin = $this->user('admin@example.test');
        $revoked = $this->user('revoked@example.test');
        $this->membership($owner, $accountId, 'owner');
        $this->membership($admin, $accountId, 'admin');
        $this->membership($revoked, $accountId, 'technical');
        $this->connection->executeStatement(
            'UPDATE commercial.commercial_account_memberships SET revoked_at = now() WHERE control_user_id = ? AND commercial_account_id = ?',
            [$revoked, $accountId],
        );

        $result = $this->service()->forUser($owner, $accountId, 50, 0);

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['items']);
        $emails = array_column($result['items'], 'email');
        self::assertContains('owner@example.test', $emails);
        self::assertContains('admin@example.test', $emails);
        self::assertNotContains('revoked@example.test', $emails);
        foreach ($result['items'] as $item) {
            self::assertArrayHasKey('id', $item);
            self::assertArrayHasKey('user_id', $item);
            self::assertArrayHasKey('email', $item);
            self::assertArrayHasKey('role', $item);
            self::assertArrayHasKey('created_at', $item);
            self::assertArrayNotHasKey('password_hash', $item);
            self::assertArrayNotHasKey('email_normalized', $item);
            self::assertArrayNotHasKey('token_hash', $item);
        }
    }

    public function testPaginationIsStableAndNonOverlapping(): void
    {
        $accountId = $this->accountWithoutMembership('members-page');
        $owner = $this->user();
        $this->membership($owner, $accountId, 'owner');
        for ($index = 0; $index < 3; $index++) {
            $this->membership($this->user(), $accountId, 'technical');
        }

        $first = $this->service()->forUser($owner, $accountId, 2, 0);
        $second = $this->service()->forUser($owner, $accountId, 2, 2);

        self::assertSame(4, $first['total']);
        self::assertSame(4, $second['total']);
        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        $ids = array_merge(array_column($first['items'], 'id'), array_column($second['items'], 'id'));
        self::assertCount(4, array_unique($ids));
    }

    #[DataProvider('viewers')]
    public function testRoleBasedAccess(string $role, bool $allowed): void
    {
        $accountId = $this->accountWithoutMembership('members-role-' . $role);
        $actor = $this->user();
        $this->membership($actor, $accountId, $role);
        $this->membership($this->user(), $accountId, 'technical');

        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }

        $result = $this->service()->forUser($actor, $accountId, 50, 0);
        self::assertGreaterThanOrEqual(1, $result['total']);
    }

    /** @return array<string, array{string, bool}> */
    public static function viewers(): array
    {
        return [
            'owner can view' => ['owner', true],
            'admin can view' => ['admin', true],
            'billing cannot view' => ['billing', false],
            'technical cannot view' => ['technical', false],
        ];
    }

    public function testMemberCannotListAnotherAccount(): void
    {
        $accountA = $this->accountWithoutMembership('members-isolation-a');
        $accountB = $this->accountWithoutMembership('members-isolation-b');
        $actor = $this->user();
        $this->membership($actor, $accountA, 'owner');
        $this->membership($this->user(), $accountB, 'owner');

        $this->expectException(AccessDeniedException::class);
        $this->service()->forUser($actor, $accountB, 50, 0);
    }

    public function testMalformedAccountIdIsRejectedWithoutDatabaseError(): void
    {
        $actor = $this->user();

        $this->expectException(AccessDeniedException::class);
        $this->service()->forUser($actor, 'not-a-uuid', 50, 0);
    }

    private function service(): ListAccountMemberships
    {
        return new ListAccountMemberships($this->connection, new AccountAuthorization($this->connection));
    }

    private function accountWithoutMembership(string $code): string
    {
        $id = $this->connection->fetchOne(
            "INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Membership Account') RETURNING id",
            [$code],
        );
        self::assertIsString($id);

        return $id;
    }

    private function membership(string $userId, string $accountId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, ?)',
            [$userId, $accountId, $role],
        );
    }
}
