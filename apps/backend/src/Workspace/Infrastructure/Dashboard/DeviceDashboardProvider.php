<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Dashboard;

use App\Workspace\Application\DashboardContext;
use App\Workspace\Application\DashboardProviderInterface;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class DeviceDashboardProvider implements DashboardProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function code(): string
    {
        return 'device_management';
    }

    public function moduleCode(): ?string
    {
        return 'device_management';
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return $context->hasPermission('education.devices.manage')
            && $this->access->tableExists('device_management.devices');
    }

    public function provide(DashboardContext $context): array
    {
        $sql = "SELECT id::text, name, number, status, enrollment_state AS \"enrollmentState\",
                       last_seen_at::text AS \"lastSeenAt\"
                FROM device_management.devices
                WHERE organization_id = CAST(:org AS uuid)
                  AND status IN ('ERROR', 'RECOVERY_REQUIRED', 'OFFLINE')";
        $params = [':org' => $context->organizationId];
        if (!$context->isOrgAdmin) {
            $sql .= ' AND owner_account_id = CAST(:account AS uuid)';
            $params[':account'] = $context->accountId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 10';
        $alerts = $this->db->createCommand($sql, $params)->queryAll();

        return [
            'summary' => ['deviceAlerts' => count($alerts)],
            'widgets' => [[
                'id' => 'device_management.alerts',
                'module' => 'device_management',
                'title' => 'Device Alerts',
                'data' => [
                    'alertCount' => count($alerts),
                    'items' => $alerts,
                ],
            ]],
            'quickActions' => [[
                'key' => 'device_management.devices',
                'label' => 'Classroom Devices',
                'route' => '/devices',
                'module' => 'device_management',
            ]],
        ];
    }
}
