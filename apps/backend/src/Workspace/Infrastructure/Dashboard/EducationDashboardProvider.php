<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Dashboard;

use App\Workspace\Application\DashboardContext;
use App\Workspace\Application\DashboardProviderInterface;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class EducationDashboardProvider implements DashboardProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function code(): string
    {
        return 'education';
    }

    public function moduleCode(): ?string
    {
        return 'education';
    }

    public function isAvailable(DashboardContext $context): bool
    {
        if (!$context->hasPermission('education.access')) {
            return false;
        }
        $roles = $context->educationRoles;
        return $roles['teacher'] || $roles['student'] || $roles['parent'] || $roles['admin'];
    }

    public function provide(DashboardContext $context): array
    {
        if (!$this->access->tableExists('education.lessons')) {
            return [];
        }

        $roles = $context->educationRoles;
        $orgId = $context->organizationId;
        $personId = $context->personId;

        $deadlines = [];
        $pendingReviews = [];
        $pendingManualGrading = [];
        $upcomingLessons = [];
        $currentGroups = [];

        $upcomingAssessments = [];
        $activityResults = [];

        if ($roles['teacher'] || $roles['admin']) {
            $lessonSql = "SELECT l.id::text, l.title, l.starts_at::text AS \"startsAt\", l.ends_at::text AS \"endsAt\",
                                 l.status, g.id::text AS \"groupId\", g.name AS \"groupName\"
                          FROM education.lessons l
                          JOIN education.groups g ON g.id = l.group_id
                          WHERE l.organization_id = CAST(:org AS uuid)
                            AND l.status = 'scheduled'
                            AND l.ends_at >= now()";
            $lessonParams = [':org' => $orgId];
            if (!$roles['admin']) {
                $lessonSql .= " AND (l.owner_person_id = CAST(:person AS uuid) OR EXISTS (
                    SELECT 1 FROM education.group_teachers gt
                    JOIN education.teachers t ON t.organization_id = gt.organization_id AND t.account_id = gt.account_id
                    WHERE gt.organization_id = l.organization_id AND gt.group_id = l.group_id AND t.person_id = CAST(:person AS uuid)
                ))";
                $lessonParams[':person'] = $personId;
            }
            $lessonSql .= ' ORDER BY l.starts_at ASC LIMIT 5';
            $upcomingLessons = $this->db->createCommand($lessonSql, $lessonParams)->queryAll();

            if ($this->access->tableExists('education.groups')) {
                $groupSql = "SELECT g.id::text, g.name,
                                    (SELECT count(*)::int FROM education.group_students gs WHERE gs.group_id = g.id) AS \"studentCount\"
                             FROM education.groups g
                             WHERE g.organization_id = CAST(:org AS uuid)
                               AND g.archived_at IS NULL";
                $groupParams = [':org' => $orgId];
                if (!$roles['admin']) {
                    $groupSql .= " AND (g.owner_person_id = CAST(:person AS uuid) OR EXISTS (
                        SELECT 1 FROM education.group_teachers gt
                        JOIN education.teachers t ON t.organization_id = gt.organization_id AND t.account_id = gt.account_id
                        WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND t.person_id = CAST(:person AS uuid)
                    ))";
                    $groupParams[':person'] = $personId;
                }
                $groupSql .= ' ORDER BY g.name ASC LIMIT 8';
                $currentGroups = $this->db->createCommand($groupSql, $groupParams)->queryAll();
            }

            if ($context->hasPermission('education.homework.review') && $this->access->tableExists('education.homework_submissions')) {
                $reviewSql = "SELECT s.id::text AS \"submissionId\", a.id::text AS \"assignmentId\",
                                     a.title AS \"assignmentTitle\", s.state, s.updated_at::text AS \"updatedAt\"
                              FROM education.homework_submissions s
                              JOIN education.homework_recipients r ON r.id = s.recipient_id
                              JOIN education.homework_assignments a ON a.id = r.assignment_id
                              WHERE s.organization_id = CAST(:org AS uuid)
                                AND s.state IN ('SUBMITTED', 'RESUBMITTED')
                                AND a.deleted_at IS NULL";
                $reviewParams = [':org' => $orgId];
                if (!$roles['admin']) {
                    $reviewSql .= ' AND a.author_person_id = CAST(:person AS uuid)';
                    $reviewParams[':person'] = $personId;
                }
                $reviewSql .= ' ORDER BY s.updated_at ASC LIMIT 10';
                $pendingReviews = $this->db->createCommand($reviewSql, $reviewParams)->queryAll();
            }

            if ($context->hasPermission('education.activities.manage') && $this->access->tableExists('activity.attempts')) {
                $gradingSql = "SELECT att.id::text AS \"attemptId\",
                                      att.activity_id::text AS \"activityId\",
                                      i.title AS \"activityTitle\",
                                      i.plugin_code AS \"pluginCode\",
                                      att.participant_person_id::text AS \"participantPersonId\",
                                      COALESCE(NULLIF(TRIM(COALESCE(p.display_name, TRIM(COALESCE(p.given_name, '') || ' ' || COALESCE(p.family_name, '')))), ''), '') AS \"studentName\",
                                      att.submitted_at::text AS \"submittedAt\"
                               FROM activity.attempts att
                               JOIN activity.items i ON i.id = att.activity_id
                               LEFT JOIN core.persons p ON p.id = att.participant_person_id
                               WHERE att.organization_id = CAST(:org AS uuid)
                                 AND att.state = 'PENDING_REVIEW'
                                 AND i.deleted_at IS NULL";
                $gradingParams = [':org' => $orgId];
                if (!$roles['admin']) {
                    $gradingSql .= ' AND i.author_person_id = CAST(:person AS uuid)';
                    $gradingParams[':person'] = $personId;
                }
                $gradingSql .= ' ORDER BY att.submitted_at ASC NULLS LAST, att.created_at ASC LIMIT 10';
                $pendingManualGrading = $this->db->createCommand($gradingSql, $gradingParams)->queryAll();
            }
        } elseif ($roles['student']) {
            $upcomingLessons = $this->db->createCommand(
                "SELECT l.id::text, l.title, l.starts_at::text AS \"startsAt\", l.ends_at::text AS \"endsAt\",
                        l.status, g.name AS \"groupName\"
                 FROM education.lessons l
                 JOIN education.groups g ON g.id = l.group_id
                 JOIN education.group_students gs ON gs.group_id = g.id AND gs.organization_id = g.organization_id
                 JOIN education.students st ON st.id = gs.student_id AND st.organization_id = g.organization_id
                 WHERE l.organization_id = CAST(:org AS uuid)
                   AND st.person_id = CAST(:person AS uuid)
                   AND g.archived_at IS NULL
                   AND l.status = 'scheduled'
                   AND l.ends_at >= now()
                 ORDER BY l.starts_at ASC
                 LIMIT 5",
                [':org' => $orgId, ':person' => $personId],
            )->queryAll();

            if ($this->access->tableExists('education.homework_assignments')) {
                $deadlines = $this->db->createCommand(
                    "SELECT a.id::text, a.title, a.due_at::text AS \"dueAt\", a.status,
                            COALESCE(s.state, 'NOT_STARTED') AS \"submissionState\"
                     FROM education.homework_recipients r
                     JOIN education.homework_assignments a ON a.id = r.assignment_id
                     LEFT JOIN education.homework_submissions s ON s.recipient_id = r.id
                     WHERE r.organization_id = CAST(:org AS uuid)
                       AND r.person_id = CAST(:person AS uuid)
                       AND a.status = 'PUBLISHED'
                       AND a.deleted_at IS NULL
                       AND a.archived_at IS NULL
                     ORDER BY a.due_at ASC NULLS LAST
                     LIMIT 10",
                    [':org' => $orgId, ':person' => $personId],
                )->queryAll();
            }
        } elseif ($roles['parent']) {
            $studentFilter = '';
            $parentParams = [':org' => $orgId, ':person' => $personId];
            $verifiedStudentId = null;
            if ($context->studentId !== null) {
                $verified = $this->db->createCommand(
                    "SELECT sg.student_id::text
                     FROM education.parent_accounts pa
                     JOIN education.student_guardians sg
                       ON sg.organization_id = pa.organization_id
                      AND sg.guardian_person_id = pa.guardian_person_id
                     WHERE pa.organization_id = CAST(:org AS uuid)
                       AND pa.guardian_person_id = CAST(:person AS uuid)
                       AND pa.revoked_at IS NULL
                       AND sg.status = 'confirmed'
                       AND sg.student_id = CAST(:student AS uuid)
                     LIMIT 1",
                    [':org' => $orgId, ':person' => $personId, ':student' => $context->studentId],
                )->queryScalar();
                if (is_string($verified) && $verified !== '') {
                    $verifiedStudentId = $verified;
                    $studentFilter = ' AND sg.student_id = CAST(:student AS uuid)';
                    $parentParams[':student'] = $verifiedStudentId;
                } else {
                    // Requested studentId is not a confirmed child of this parent: return empty data.
                    $studentFilter = ' AND 1=0';
                }
            }

            $upcomingLessons = $this->db->createCommand(
                "SELECT DISTINCT l.id::text, l.title, l.starts_at::text AS \"startsAt\", l.ends_at::text AS \"endsAt\",
                        l.status, g.name AS \"groupName\"
                 FROM education.parent_accounts pa
                 JOIN education.student_guardians sg ON sg.organization_id = pa.organization_id AND sg.guardian_person_id = pa.guardian_person_id
                 JOIN education.group_students gs ON gs.student_id = sg.student_id AND gs.organization_id = pa.organization_id
                 JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = pa.organization_id
                 JOIN education.lessons l ON l.group_id = g.id AND l.organization_id = g.organization_id
                 WHERE pa.organization_id = CAST(:org AS uuid)
                   AND pa.guardian_person_id = CAST(:person AS uuid)
                   AND pa.revoked_at IS NULL
                   AND sg.status = 'confirmed'
                   {$studentFilter}
                   AND g.archived_at IS NULL
                   AND l.status = 'scheduled'
                   AND l.ends_at >= now()
                 ORDER BY \"startsAt\" ASC
                 LIMIT 5",
                $parentParams,
            )->queryAll();

            if ($this->access->tableExists('education.homework_assignments')) {
                $deadlines = $this->db->createCommand(
                    "SELECT a.id::text, a.title, a.due_at::text AS \"dueAt\", a.status,
                            r.student_id::text AS \"studentId\",
                            COALESCE(s.state, 'NOT_STARTED') AS \"submissionState\"
                     FROM education.parent_accounts pa
                     JOIN education.student_guardians sg ON sg.organization_id = pa.organization_id AND sg.guardian_person_id = pa.guardian_person_id
                     JOIN education.homework_recipients r ON r.student_id = sg.student_id AND r.organization_id = pa.organization_id
                     JOIN education.homework_assignments a ON a.id = r.assignment_id
                     LEFT JOIN education.homework_submissions s ON s.recipient_id = r.id
                     WHERE pa.organization_id = CAST(:org AS uuid)
                       AND pa.guardian_person_id = CAST(:person AS uuid)
                       AND pa.revoked_at IS NULL
                       AND sg.status = 'confirmed'
                       {$studentFilter}
                       AND a.status = 'PUBLISHED'
                       AND a.deleted_at IS NULL
                       AND a.archived_at IS NULL
                     ORDER BY a.due_at ASC NULLS LAST
                     LIMIT 10",
                    $parentParams,
                )->queryAll();
            }

            if ($this->access->tableExists('activity.assignments')) {
                $upcomingAssessments = $this->db->createCommand(
                    "SELECT DISTINCT aa.id::text, i.id::text AS \"activityId\", i.title, i.plugin_code AS \"pluginCode\",
                            aa.due_at::text AS \"dueAt\", aa.opens_at::text AS \"opensAt\", aa.status
                     FROM education.parent_accounts pa
                     JOIN education.student_guardians sg ON sg.organization_id = pa.organization_id AND sg.guardian_person_id = pa.guardian_person_id
                     JOIN education.students st ON st.id = sg.student_id AND st.organization_id = pa.organization_id
                     JOIN activity.assignments aa ON aa.organization_id = pa.organization_id
                     JOIN activity.items i ON i.id = aa.activity_id AND i.deleted_at IS NULL
                     WHERE pa.organization_id = CAST(:org AS uuid)
                       AND pa.guardian_person_id = CAST(:person AS uuid)
                       AND pa.revoked_at IS NULL
                       AND sg.status = 'confirmed'
                       {$studentFilter}
                       AND aa.status = 'ACTIVE'
                       AND (aa.due_at IS NULL OR aa.due_at >= now())
                       AND (
                           (aa.target_type = 'student' AND aa.target_id = st.id)
                           OR (aa.target_type = 'person' AND aa.target_id = st.person_id)
                           OR (aa.target_type = 'group' AND EXISTS (
                               SELECT 1 FROM education.group_students gs
                               JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = gs.organization_id
                               WHERE gs.student_id = st.id AND gs.group_id = aa.target_id AND g.archived_at IS NULL
                           ))
                       )
                     ORDER BY \"dueAt\" ASC NULLS LAST
                     LIMIT 5",
                    $parentParams,
                )->queryAll();
            }

            if ($this->access->tableExists('activity.attempts')) {
                $activityResults = $this->db->createCommand(
                    "SELECT DISTINCT att.id::text, i.title AS \"activityName\", i.plugin_code AS \"pluginCode\",
                            att.raw_score::float AS \"rawScore\", att.max_score::float AS \"maxScore\",
                            att.percentage::float AS accuracy, att.passed,
                            COALESCE(att.evaluated_at, att.submitted_at)::text AS \"finishedAt\"
                     FROM education.parent_accounts pa
                     JOIN education.student_guardians sg ON sg.organization_id = pa.organization_id AND sg.guardian_person_id = pa.guardian_person_id
                     JOIN education.students st ON st.id = sg.student_id AND st.organization_id = pa.organization_id
                     JOIN activity.attempts att ON att.organization_id = pa.organization_id AND att.participant_person_id = st.person_id
                     JOIN activity.items i ON i.id = att.activity_id AND i.deleted_at IS NULL
                     WHERE pa.organization_id = CAST(:org AS uuid)
                       AND pa.guardian_person_id = CAST(:person AS uuid)
                       AND pa.revoked_at IS NULL
                       AND sg.status = 'confirmed'
                       {$studentFilter}
                       AND att.state IN ('EVALUATED', 'COMPLETED')
                       AND att.results_published_at IS NOT NULL
                     ORDER BY \"finishedAt\" DESC NULLS LAST
                     LIMIT 5",
                    $parentParams,
                )->queryAll();
            }
        }

        $quickActions = [];

        if ($roles['teacher'] || $roles['admin']) {
            $quickActions[] = ['key' => 'education.schedule', 'label' => 'Schedule & Lessons', 'route' => '/calendar', 'module' => 'education'];
            $quickActions[] = ['key' => 'education.lesson.create', 'label' => 'New Lesson', 'route' => '/lessons/new', 'module' => 'education'];
            $quickActions[] = ['key' => 'education.group.create', 'label' => 'New Group', 'route' => '/groups/new', 'module' => 'education'];
            if ($context->hasPermission('education.homework.manage')) {
                $quickActions[] = ['key' => 'education.homework.create', 'label' => 'New Homework', 'route' => '/homework/new', 'module' => 'education'];
            }
            if ($context->hasPermission('education.activities.manage')) {
                $quickActions[] = ['key' => 'education.assessments', 'label' => 'Assessments', 'route' => '/assessments', 'module' => 'education'];
            }
            if ($context->hasPermission('education.messages.use')) {
                $quickActions[] = ['key' => 'education.messages', 'label' => 'Messages', 'route' => '/messages', 'module' => 'education'];
            }
        } elseif ($roles['student']) {
            $quickActions[] = ['key' => 'education.student.homework', 'label' => 'My Homework', 'route' => '/student/homework', 'module' => 'education'];
        } elseif ($roles['parent']) {
            $quickActions[] = ['key' => 'education.parent.children', 'label' => 'Children Progress', 'route' => '/parent', 'module' => 'education'];
        }

        return [
            'summary' => [
                'upcomingLessons' => count($upcomingLessons),
                'currentGroups' => count($currentGroups),
                'educationDeadlines' => count($deadlines),
                'pendingReviews' => count($pendingReviews),
                'pendingManualGrading' => count($pendingManualGrading),
                'upcomingAssessments' => count($upcomingAssessments),
            ],
            'widgets' => [[
                'id' => 'education.overview',
                'module' => 'education',
                'title' => 'Education',
                'data' => [
                    'upcomingLessons' => $upcomingLessons,
                    'currentGroups' => $currentGroups,
                    'deadlines' => $deadlines,
                    'pendingReviews' => $pendingReviews,
                    'pendingManualGrading' => $pendingManualGrading,
                    'upcomingAssessments' => $upcomingAssessments,
                    'activityResults' => $activityResults,
                ],
            ]],
            'quickActions' => $quickActions,
        ];
    }
}
