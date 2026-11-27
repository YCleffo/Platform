<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Dashboard;

use App\Workspace\Application\DashboardContext;
use App\Workspace\Application\DashboardProviderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class CoreDashboardProvider implements DashboardProviderInterface
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'core';
    }

    public function moduleCode(): ?string
    {
        return null;
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return true;
    }

    public function provide(DashboardContext $context): array
    {
        $summary = [];
        $widgets = [];
        $quickActions = [
            ['key' => 'workspace.search', 'label' => 'Universal Search', 'route' => '/workspace/search', 'module' => 'workspace'],
        ];

        $orgRow = $this->db->createCommand(
            'SELECT id::text, code, name, status FROM core.organizations WHERE id = CAST(:org AS uuid)',
            [':org' => $context->organizationId],
        )->queryOne();

        if ($context->isOrgAdmin || $context->hasPermission('core.organization_memberships.read')) {
            $memberCount = (int) ($this->db->createCommand(
                "SELECT count(*) FROM core.organization_memberships
                 WHERE organization_id = CAST(:org AS uuid) AND status = 'active'",
                [':org' => $context->organizationId],
            )->queryScalar() ?: 0);
            $summary['organizationMembers'] = $memberCount;
            $widgets[] = [
                'id' => 'core.organization',
                'module' => 'core',
                'title' => 'Organization Overview',
                'data' => [
                    'organization' => is_array($orgRow) ? $orgRow : null,
                    'activeMembers' => $memberCount,
                ],
            ];
            $quickActions[] = ['key' => 'core.members', 'label' => 'Directory & Members', 'route' => '/admin/users', 'module' => 'core'];
        } else {
            $widgets[] = [
                'id' => 'core.organization',
                'module' => 'core',
                'title' => 'Organization',
                'data' => ['organization' => is_array($orgRow) ? $orgRow : null],
            ];
        }

        if ($context->hasPermission('core.privacy.read')) {
            $quickActions[] = ['key' => 'core.privacy', 'label' => 'Privacy & Compliance', 'route' => '/privacy', 'module' => 'core'];
        }

        return [
            'summary' => $summary,
            'widgets' => $widgets,
            'quickActions' => $quickActions,
        ];
    }
}
