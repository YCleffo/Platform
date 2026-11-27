<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Dashboard;

use App\Workspace\Application\DashboardContext;
use App\Workspace\Application\DashboardProviderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class NotificationsDashboardProvider implements DashboardProviderInterface
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'notifications';
    }

    public function moduleCode(): ?string
    {
        return 'notifications';
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return $context->hasPermission('notifications.use');
    }

    public function provide(DashboardContext $context): array
    {
        $unreadCount = (int) ($this->db->createCommand(
            'SELECT count(*) FROM notifications.items
             WHERE organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid)
               AND read_at IS NULL
               AND archived_at IS NULL',
            [':org' => $context->organizationId, ':person' => $context->personId],
        )->queryScalar() ?: 0);

        $latest = $this->db->createCommand(
            'SELECT id::text, category, kind, title, body, priority,
                    resource_type AS "resourceType", resource_id::text AS "resourceId",
                    resource_path AS "resourcePath", created_at::text AS "createdAt",
                    read_at::text AS "readAt"
             FROM notifications.items
             WHERE organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid)
               AND archived_at IS NULL
             ORDER BY (read_at IS NOT NULL), created_at DESC, id DESC
             LIMIT 5',
            [':org' => $context->organizationId, ':person' => $context->personId],
        )->queryAll();

        $recentActivity = [];
        /** @var array<string, mixed> $n */
        foreach ($latest as $n) {
            $recentActivity[] = [
                'type' => 'notification',
                'id' => (string) $n['id'],
                'title' => (string) $n['title'],
                'module' => 'notifications',
                'occurredAt' => (string) $n['createdAt'],
                'route' => is_string($n['resourcePath'] ?? null) && $n['resourcePath'] !== '' ? $n['resourcePath'] : '/notifications',
            ];
        }

        return [
            'summary' => ['unreadNotifications' => $unreadCount],
            'widgets' => [[
                'id' => 'notifications.unread',
                'module' => 'notifications',
                'title' => 'Notifications',
                'data' => [
                    'unreadCount' => $unreadCount,
                    'items' => $latest,
                ],
            ]],
            'quickActions' => [[
                'key' => 'notifications.center',
                'label' => 'Notifications Center',
                'route' => '/notifications',
                'module' => 'notifications',
            ]],
            'recentActivity' => $recentActivity,
        ];
    }
}
