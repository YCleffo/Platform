<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Account\Authorization\AccountAuthorization;
use PHPUnit\Framework\TestCase;

final class AccountAuthorizationTest extends TestCase
{
    public function testOwnerCapabilities(): void
    {
        $permissions = AccountAuthorization::permissionsForRole('owner');

        self::assertSame([
            'read_account' => true,
            'view_members' => true,
            'manage_members' => true,
            'manage_ownership' => true,
            'manage_billing' => true,
            'manage_technical' => true,
        ], $permissions);
    }

    public function testAdminCapabilities(): void
    {
        $permissions = AccountAuthorization::permissionsForRole('admin');

        self::assertSame([
            'read_account' => true,
            'view_members' => true,
            'manage_members' => true,
            'manage_ownership' => false,
            'manage_billing' => false,
            'manage_technical' => true,
        ], $permissions);
    }

    public function testBillingCapabilities(): void
    {
        $permissions = AccountAuthorization::permissionsForRole('billing');

        self::assertSame([
            'read_account' => true,
            'view_members' => false,
            'manage_members' => false,
            'manage_ownership' => false,
            'manage_billing' => true,
            'manage_technical' => false,
        ], $permissions);
    }

    public function testTechnicalCapabilities(): void
    {
        $permissions = AccountAuthorization::permissionsForRole('technical');

        self::assertSame([
            'read_account' => true,
            'view_members' => false,
            'manage_members' => false,
            'manage_ownership' => false,
            'manage_billing' => false,
            'manage_technical' => true,
        ], $permissions);
    }

    public function testUnknownRoleHasNoCapabilities(): void
    {
        self::assertSame([
            'read_account' => false,
            'view_members' => false,
            'manage_members' => false,
            'manage_ownership' => false,
            'manage_billing' => false,
            'manage_technical' => false,
        ], AccountAuthorization::permissionsForRole('superuser'));
    }

    public function testAssertRoleAcceptsKnownRolesAndRejectsUnknown(): void
    {
        foreach (['owner', 'admin', 'billing', 'technical'] as $role) {
            AccountAuthorization::assertRole($role);
        }

        $this->expectException(\InvalidArgumentException::class);
        AccountAuthorization::assertRole('superuser');
    }
}
