<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Application\GetCommercialAccount;
use ControlPlane\Account\Authorization\AccountAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccountDetailTest extends DatabaseTestCase
{
    public function testOwnerDetailCarriesRoleAndCapabilities(): void
    {
        $accountId = $this->accountWithoutMembership('detail-owner');
        $owner = $this->user();
        $this->membership($owner, $accountId, 'owner');

        $detail = $this->service()->forUser($owner, $accountId);

        self::assertSame($accountId, $detail['id']);
        self::assertSame('owner', $detail['role']);
        self::assertTrue($detail['permissions']['read_account']);
        self::assertTrue($detail['permissions']['view_members']);
        self::assertTrue($detail['permissions']['manage_members']);
        self::assertTrue($detail['permissions']['manage_ownership']);
        self::assertTrue($detail['permissions']['manage_billing']);
        self::assertTrue($detail['permissions']['manage_technical']);
        self::assertArrayHasKey('code', $detail);
        self::assertArrayHasKey('display_name', $detail);
        self::assertArrayHasKey('status', $detail);
        self::assertArrayHasKey('onboarding_state', $detail);
        self::assertArrayNotHasKey('password_hash', $detail);
        self::assertArrayNotHasKey('email_normalized', $detail);
    }

    #[DataProvider('roleCapabilities')]
    public function testDetailCapabilitiesMatchRole(string $role, bool $viewMembers, bool $manageMembers, bool $manageOwnership): void
    {
        $accountId = $this->accountWithoutMembership('detail-' . $role);
        $userId = $this->user();
        $this->membership($userId, $accountId, $role);

        $detail = $this->service()->forUser($userId, $accountId);

        self::assertSame($role, $detail['role']);
        self::assertTrue($detail['permissions']['read_account']);
        self::assertSame($viewMembers, $detail['permissions']['view_members']);
        self::assertSame($manageMembers, $detail['permissions']['manage_members']);
        self::assertSame($manageOwnership, $detail['permissions']['manage_ownership']);
    }

    /** @return array<string, array{string, bool, bool, bool}> */
    public static function roleCapabilities(): array
    {
        return [
            'owner' => ['owner', true, true, true],
            'admin' => ['admin', true, true, false],
            'billing' => ['billing', false, false, false],
            'technical' => ['technical', false, false, false],
        ];
    }

    public function testNonMemberCannotReadDetail(): void
    {
        $accountId = $this->accountWithoutMembership('detail-private');
        $stranger = $this->user();

        $this->expectException(AccessDeniedException::class);
        $this->service()->forUser($stranger, $accountId);
    }

    public function testRevokedMembershipLosesAccess(): void
    {
        $accountId = $this->accountWithoutMembership('detail-revoked');
        $userId = $this->user();
        $this->membership($userId, $accountId, 'owner');
        $this->connection->executeStatement(
            'UPDATE commercial.commercial_account_memberships SET revoked_at = now() WHERE control_user_id = ? AND commercial_account_id = ?',
            [$userId, $accountId],
        );

        $this->expectException(AccessDeniedException::class);
        $this->service()->forUser($userId, $accountId);
    }

    public function testMalformedAccountIdIsRejectedWithoutDatabaseError(): void
    {
        $userId = $this->user();

        $this->expectException(AccessDeniedException::class);
        $this->service()->forUser($userId, 'not-a-uuid');
    }

    private function service(): GetCommercialAccount
    {
        return new GetCommercialAccount($this->connection, new AccountAuthorization($this->connection));
    }

    private function accountWithoutMembership(string $code): string
    {
        $id = $this->connection->fetchOne(
            "INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Detail Account') RETURNING id",
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
