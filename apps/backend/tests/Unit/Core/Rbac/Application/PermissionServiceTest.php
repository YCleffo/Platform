<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Rbac\Application;

use App\Core\Rbac\Application\PermissionPage;
use App\Core\Rbac\Application\PermissionRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Rbac\Application\PermissionView;
use App\Core\Rbac\Application\RolePage;
use App\Core\Rbac\Application\RoleRepository;
use App\Core\Rbac\Application\RoleView;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class PermissionServiceTest extends Unit
{
    public function testSuperadminBypassIsCentralized(): void
    {
        $permission = $this->permission();
        $service = new PermissionService(
            $this->roles(superadmin: true),
            $this->permissions(granted: false, all: [$permission]),
        );

        assertTrue($service->hasPermission('account-id', 'anything'));
        assertSame([$permission], $service->getPermissions('account-id'));
    }

    public function testRegularAccountUsesExplicitGrant(): void
    {
        $service = new PermissionService(
            $this->roles(superadmin: false),
            $this->permissions(granted: true),
        );

        assertTrue($service->hasPermission('account-id', 'core.read'));

        $denied = new PermissionService(
            $this->roles(superadmin: false),
            $this->permissions(granted: false),
        );
        assertFalse($denied->hasPermission('account-id', 'core.read'));
    }

    private function roles(bool $superadmin): RoleRepository
    {
        return new class ($superadmin) implements RoleRepository {
            public function __construct(private readonly bool $superadmin) {}

            public function findPage(int $limit, int $offset): RolePage
            {
                return new RolePage([], $limit, $offset, 0);
            }

            public function findById(string $id): ?RoleView
            {
                return null;
            }

            public function findByAccountId(string $accountId): array
            {
                return [];
            }

            public function accountHasRoleCode(string $accountId, string $roleCode): bool
            {
                return $this->superadmin && $roleCode === 'core.superadmin';
            }

            public function assign(string $accountId, string $roleId, string $assignedByAccountId): bool
            {
                return false;
            }

            public function revoke(string $accountId, string $roleId): bool
            {
                return false;
            }
        };
    }

    /** @param list<PermissionView> $all */
    private function permissions(bool $granted, array $all = []): PermissionRepository
    {
        return new class ($granted, $all) implements PermissionRepository {
            /** @param list<PermissionView> $all */
            public function __construct(
                private readonly bool $granted,
                private readonly array $all,
            ) {}

            public function findPage(int $limit, int $offset): PermissionPage
            {
                return new PermissionPage([], $limit, $offset, 0);
            }

            public function findByAccountId(string $accountId): array
            {
                return [];
            }

            public function findAll(): array
            {
                return $this->all;
            }

            public function accountHasPermission(string $accountId, string $permissionCode): bool
            {
                return $this->granted;
            }
        };
    }

    private function permission(): PermissionView
    {
        return new PermissionView(
            id: 'permission-id',
            moduleId: null,
            code: 'core.read',
            name: 'Read',
            description: null,
            isSystem: true,
            createdAt: '2026-01-01T00:00:00+00:00',
            updatedAt: '2026-01-01T00:00:00+00:00',
        );
    }
}
