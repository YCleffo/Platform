<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Dashboard;

use App\Workspace\Application\DashboardContext;
use App\Workspace\Application\DashboardProviderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ChatDashboardProvider implements DashboardProviderInterface
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'chat';
    }

    public function moduleCode(): ?string
    {
        return 'chat';
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return $context->hasPermission('chat.use');
    }

    public function provide(DashboardContext $context): array
    {
        $rooms = $this->db->createCommand(
            'SELECT r.id::text, r.title, r.room_type AS "roomType", r.last_activity_at::text AS "lastActivityAt",
                    (
                        SELECT count(*)::int
                        FROM chat.messages msg
                        WHERE msg.room_id = r.id
                          AND msg.organization_id = r.organization_id
                          AND msg.deleted_at IS NULL
                          AND msg.deleted_for_all = false
                          AND msg.sender_person_id <> CAST(:person AS uuid)
                          AND (cm.last_read_at IS NULL OR msg.created_at > cm.last_read_at)
                          AND NOT EXISTS (
                              SELECT 1 FROM chat.message_deletions md
                              WHERE md.message_id = msg.id AND md.person_id = CAST(:person AS uuid)
                          )
                    ) AS "unreadCount"
             FROM chat.rooms r
             JOIN chat.members cm
               ON cm.room_id = r.id
              AND cm.organization_id = r.organization_id
              AND cm.person_id = CAST(:person AS uuid)
              AND cm.left_at IS NULL
              AND cm.archived_at IS NULL
             WHERE r.organization_id = CAST(:org AS uuid)
               AND r.archived_at IS NULL
             ORDER BY r.last_activity_at DESC, r.id DESC
             LIMIT 10',
            [':org' => $context->organizationId, ':person' => $context->personId],
        )->queryAll();

        $totalUnread = 0;
        $recentActivity = [];
        /** @var array<string, mixed> $room */
        foreach ($rooms as $room) {
            $unread = (int) ($room['unreadCount'] ?? 0);
            $totalUnread += $unread;
            $recentActivity[] = [
                'type' => 'chat.room',
                'id' => (string) $room['id'],
                'title' => (string) $room['title'],
                'module' => 'chat',
                'occurredAt' => (string) $room['lastActivityAt'],
                'route' => '/chat/' . (string) $room['id'],
            ];
        }

        return [
            'summary' => [
                'unreadChatMessages' => $totalUnread,
                'chatRooms' => count($rooms),
            ],
            'widgets' => [[
                'id' => 'chat.unread',
                'module' => 'chat',
                'title' => 'Recent Conversations',
                'data' => [
                    'unreadCount' => $totalUnread,
                    'rooms' => array_slice($rooms, 0, 5),
                ],
            ]],
            'quickActions' => [[
                'key' => 'chat.open',
                'label' => 'Messages',
                'route' => '/chat',
                'module' => 'chat',
            ]],
            'recentActivity' => array_slice($recentActivity, 0, 5),
        ];
    }
}
