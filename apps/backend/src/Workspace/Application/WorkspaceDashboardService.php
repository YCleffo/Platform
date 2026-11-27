<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Realtime\Application\RealtimeService;
use App\Workspace\Domain\WorkspaceAccess;

final readonly class WorkspaceDashboardService
{
    public function __construct(
        private WorkspaceAccess $access,
        private DashboardProviderRegistry $registry,
    ) {}

    /** @return array<string, mixed> */
    public function getDashboard(AuthenticatedIdentity $identity, string $organizationId, ?string $studentId = null): array
    {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $cleanStudentId = $studentId !== null && $studentId !== '' ? RealtimeService::assertUuid($studentId, 'studentId') : null;

        $enabledModulesMap = $this->access->enabledModulesMap();
        $educationRoles = $this->access->educationRoles($identity, $orgId, $personId);
        $permissions = $this->access->availablePermissionCodes($identity);

        $context = new DashboardContext(
            identity: $identity,
            organizationId: $orgId,
            accountId: $identity->accountId,
            personId: $personId,
            permissions: $permissions,
            isSuperadmin: $this->access->isSuperadmin($identity),
            isOrgAdmin: $this->access->isOrgAdmin($identity),
            enabledModules: $enabledModulesMap,
            educationRoles: $educationRoles,
            locale: $identity->locale ?? 'ru-RU',
            studentId: $cleanStudentId,
        );

        $availableModules = [];
        foreach ($enabledModulesMap as $code => $enabled) {
            if ($enabled) {
                $availableModules[] = $code;
            }
        }
        sort($availableModules);

        $summary = [];
        $widgets = [];
        $quickActions = [];
        $recentActivity = [];

        foreach ($this->registry->all() as $provider) {
            $moduleCode = $provider->moduleCode();
            if ($moduleCode !== null && !$context->isModuleEnabled($moduleCode)) {
                continue;
            }
            if (!$provider->isAvailable($context)) {
                continue;
            }
            $data = $provider->provide($context);
            if (isset($data['summary'])) {
                foreach ($data['summary'] as $k => $v) {
                    $summary[$k] = $v;
                }
            }
            if (isset($data['widgets'])) {
                foreach ($data['widgets'] as $w) {
                    $widgets[] = $w;
                }
            }
            if (isset($data['quickActions'])) {
                foreach ($data['quickActions'] as $qa) {
                    $quickActions[] = $qa;
                }
            }
            if (isset($data['recentActivity'])) {
                foreach ($data['recentActivity'] as $ra) {
                    $recentActivity[] = $ra;
                }
            }
        }

        usort($recentActivity, static function (array $a, array $b): int {
            return strcmp((string) ($b['occurredAt'] ?? ''), (string) ($a['occurredAt'] ?? ''));
        });
        $recentActivity = array_slice($recentActivity, 0, 20);

        return [
            'organizationId' => $orgId,
            'accountId' => $identity->accountId,
            'personId' => $personId,
            'roleContext' => [
                'roles' => $identity->roles,
                'isSuperadmin' => $context->isSuperadmin,
                'isOrgAdmin' => $context->isOrgAdmin,
                'education' => $educationRoles,
            ],
            'availableModules' => $availableModules,
            'summary' => $summary,
            'widgets' => $widgets,
            'quickActions' => $quickActions,
            'recentActivity' => $recentActivity,
        ];
    }
}
