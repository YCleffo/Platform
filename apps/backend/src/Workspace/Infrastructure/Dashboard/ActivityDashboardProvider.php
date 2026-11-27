<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Dashboard;

use App\Activity\Application\ActivityDomainPolicyInterface;
use App\Activity\Application\ActivityPayload;
use App\Workspace\Application\DashboardContext;
use App\Workspace\Application\DashboardProviderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ActivityDashboardProvider implements DashboardProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private ActivityDomainPolicyInterface $domainPolicy,
    ) {}

    public function code(): string
    {
        return 'activity';
    }

    public function moduleCode(): ?string
    {
        return 'activity';
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return $context->hasPermission('activity.use');
    }

    public function provide(DashboardContext $context): array
    {
        $rows = $this->db->createCommand(
            "SELECT i.id::text, i.plugin_code AS \"pluginCode\", i.author_person_id::text AS \"authorPersonId\",
                    i.title, i.status, i.visibility, i.updated_at::text AS \"updatedAt\",
                    v.config::text AS \"configJson\"
             FROM activity.items i
             LEFT JOIN activity.versions v ON v.id = i.active_version_id
             WHERE i.organization_id = CAST(:org AS uuid)
               AND i.deleted_at IS NULL
               AND i.status <> 'ARCHIVED'
             ORDER BY i.updated_at DESC, i.id DESC
             LIMIT 30",
            [':org' => $context->organizationId],
        )->queryAll();

        $visibleActivities = [];
        /** @var array<string, mixed> $row */
        foreach ($rows as $row) {
            $row['config'] = ActivityPayload::decodeObject((string) ($row['configJson'] ?? '{}'));
            if (!$this->domainPolicy->canAccessActivity($context->identity, $context->organizationId, $row)) {
                continue;
            }
            unset($row['configJson'], $row['config']);
            $visibleActivities[] = $row;
            if (count($visibleActivities) >= 5) {
                break;
            }
        }

        $assignmentRows = $this->db->createCommand(
            "SELECT a.id::text, a.activity_id::text AS \"activityId\", a.target_type AS \"targetType\",
                    a.target_id::text AS \"targetId\", a.assigned_by_person_id::text AS \"assignedByPersonId\",
                    a.due_at::text AS \"dueAt\", a.status, i.title AS \"activityTitle\"
             FROM activity.assignments a
             JOIN activity.items i ON i.id = a.activity_id
             WHERE a.organization_id = CAST(:org AS uuid)
               AND a.status = 'ACTIVE'
               AND a.revoked_at IS NULL
               AND i.deleted_at IS NULL
             ORDER BY a.due_at ASC NULLS LAST, a.created_at DESC
             LIMIT 30",
            [':org' => $context->organizationId],
        )->queryAll();

        $visibleAssignments = [];
        /** @var array<string, mixed> $ar */
        foreach ($assignmentRows as $ar) {
            if (!$this->domainPolicy->canViewAssignment($context->identity, $context->organizationId, $ar)) {
                continue;
            }
            $visibleAssignments[] = $ar;
            if (count($visibleAssignments) >= 5) {
                break;
            }
        }

        $quickActions = [];
        if ($context->hasPermission('activity.create')) {
            $quickActions[] = ['key' => 'activity.create', 'label' => 'Create Activity', 'route' => '/activities/new', 'module' => 'activity'];
        } else {
            $quickActions[] = ['key' => 'activity.list', 'label' => 'Activities', 'route' => '/activities', 'module' => 'activity'];
        }

        return [
            'summary' => [
                'activeActivities' => count($visibleActivities),
                'activeAssignments' => count($visibleAssignments),
            ],
            'widgets' => [[
                'id' => 'activity.active',
                'module' => 'activity',
                'title' => 'Activities & Assignments',
                'data' => [
                    'activities' => $visibleActivities,
                    'assignments' => $visibleAssignments,
                ],
            ]],
            'quickActions' => $quickActions,
        ];
    }
}
