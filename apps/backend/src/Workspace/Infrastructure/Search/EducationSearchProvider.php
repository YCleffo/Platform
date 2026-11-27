<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Search;

use App\Workspace\Application\SearchProviderInterface;
use App\Workspace\Application\SearchQuery;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class EducationSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function categories(): array
    {
        return ['education'];
    }

    public function moduleCode(): ?string
    {
        return 'education';
    }

    public function isAvailable(SearchQuery $query): bool
    {
        if ($query->category !== null && $query->category !== 'education') {
            return false;
        }
        return $query->hasPermission('education.access')
            && $this->access->tableExists('education.groups');
    }

    public function search(SearchQuery $query): array
    {
        $orgsArray = SearchSqlHelper::uuidArrayLiteral($query->permittedOrgIds);
        $limit = $query->fetchLimit();
        $isAdmin = $query->isOrgAdmin;

        $baseParams = [
            ':orgs' => $orgsArray,
            ':person' => $query->personId,
            ':account' => $query->accountId,
            ':q' => $query->query,
            ':likeQ' => $query->likePattern,
        ];

        $teacherOrAdminCheck = static function (string $orgCol): string {
            return "(
                EXISTS (SELECT 1 FROM education.teachers t WHERE t.organization_id = {$orgCol} AND t.person_id = CAST(:person AS uuid))
            )";
        };

        $items = [];
        $total = 0;

        // 1. Groups
        $groupMatch = SearchSqlHelper::matchExpr('g.name');
        $groupScore = SearchSqlHelper::scoreExpr('g.name');
        $groupAcl = $isAdmin ? 'true' : "(
            g.owner_person_id = CAST(:person AS uuid)
            OR EXISTS (
                SELECT 1 FROM education.group_teachers gt
                JOIN education.teachers t ON t.organization_id = gt.organization_id AND t.account_id = gt.account_id
                WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND t.person_id = CAST(:person AS uuid)
            )
            OR (g.archived_at IS NULL AND EXISTS (
                SELECT 1 FROM education.group_students gs
                JOIN education.students st ON st.id = gs.student_id AND st.organization_id = gs.organization_id
                WHERE gs.group_id = g.id AND gs.organization_id = g.organization_id AND st.person_id = CAST(:person AS uuid)
            ))
            OR (g.archived_at IS NULL AND EXISTS (
                SELECT 1 FROM education.group_students gs
                JOIN education.student_guardians sg ON sg.student_id = gs.student_id AND sg.organization_id = gs.organization_id AND sg.status = 'confirmed'
                JOIN education.parent_accounts pa ON pa.organization_id = sg.organization_id AND pa.guardian_person_id = sg.guardian_person_id AND pa.revoked_at IS NULL
                WHERE gs.group_id = g.id AND gs.organization_id = g.organization_id
                  AND pa.guardian_person_id = CAST(:person AS uuid) AND pa.account_id = CAST(:account AS uuid)
            ))
        )";

        $total += (int) ($this->db->createCommand(
            "SELECT count(*) FROM education.groups g
             WHERE g.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$groupAcl} AND {$groupMatch}",
            $baseParams,
        )->queryScalar() ?: 0);

        $groupRows = $this->db->createCommand(
            "SELECT g.id::text, g.organization_id::text AS \"organizationId\", g.name, g.created_at::text AS \"updatedAt\", {$groupScore} AS score
             FROM education.groups g
             WHERE g.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$groupAcl} AND {$groupMatch}
             ORDER BY score DESC, g.created_at DESC
             LIMIT :limit",
            [...$baseParams, ':limit' => $limit],
        )->queryAll();

        /** @var array<string, mixed> $r */
        foreach ($groupRows as $r) {
            $items[] = SearchSqlHelper::item(
                entityType: 'education.group',
                entityId: (string) $r['id'],
                category: 'education',
                title: (string) $r['name'],
                subtitle: 'Group',
                snippet: (string) $r['name'],
                route: '/education/groups/' . (string) $r['id'],
                module: 'education',
                organizationId: (string) $r['organizationId'],
                updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                score: (float) ($r['score'] ?? 0.0),
            );
        }

        // 2. Students
        $stuExpr = "COALESCE(p.display_name, '') || ' ' || p.given_name || ' ' || p.family_name";
        $stuMatch = SearchSqlHelper::matchExpr($stuExpr);
        $stuScore = SearchSqlHelper::scoreExpr($stuExpr);
        $stuAcl = $isAdmin ? 'true' : "(
            st.person_id = CAST(:person AS uuid)
            OR (st.owner_account_id = CAST(:account AS uuid) AND {$teacherOrAdminCheck('st.organization_id')})
            OR EXISTS (
                SELECT 1 FROM education.group_students gs
                JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = gs.organization_id
                WHERE gs.student_id = st.id AND gs.organization_id = st.organization_id
                  AND (g.owner_person_id = CAST(:person AS uuid) OR EXISTS (
                      SELECT 1 FROM education.group_teachers gt
                      JOIN education.teachers t ON t.organization_id = gt.organization_id AND t.account_id = gt.account_id
                      WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND t.person_id = CAST(:person AS uuid)
                  ))
            )
            OR EXISTS (
                SELECT 1 FROM education.student_guardians sg
                JOIN education.parent_accounts pa ON pa.organization_id = sg.organization_id AND pa.guardian_person_id = sg.guardian_person_id AND pa.revoked_at IS NULL
                WHERE sg.student_id = st.id AND sg.organization_id = st.organization_id AND sg.status = 'confirmed'
                  AND pa.guardian_person_id = CAST(:person AS uuid) AND pa.account_id = CAST(:account AS uuid)
            )
        )";

        $total += (int) ($this->db->createCommand(
            "SELECT count(*) FROM education.students st
             JOIN core.persons p ON p.id = st.person_id
             WHERE st.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$stuAcl} AND {$stuMatch}",
            $baseParams,
        )->queryScalar() ?: 0);

        $stuRows = $this->db->createCommand(
            "SELECT st.id::text, st.organization_id::text AS \"organizationId\",
                    COALESCE(NULLIF(p.display_name, ''), CONCAT_WS(' ', p.given_name, p.family_name)) AS name,
                    st.created_at::text AS \"updatedAt\", {$stuScore} AS score
             FROM education.students st
             JOIN core.persons p ON p.id = st.person_id
             WHERE st.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$stuAcl} AND {$stuMatch}
             ORDER BY score DESC, st.created_at DESC
             LIMIT :limit",
            [...$baseParams, ':limit' => $limit],
        )->queryAll();

        foreach ($stuRows as $r) {
            $items[] = SearchSqlHelper::item(
                entityType: 'education.student',
                entityId: (string) $r['id'],
                category: 'education',
                title: (string) $r['name'],
                subtitle: 'Student',
                snippet: (string) $r['name'],
                route: '/education/students/' . (string) $r['id'],
                module: 'education',
                organizationId: (string) $r['organizationId'],
                updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                score: (float) ($r['score'] ?? 0.0),
            );
        }

        // 3. Teachers
        $teaAcl = $isAdmin ? 'true' : "(
            {$teacherOrAdminCheck('t.organization_id')}
            OR EXISTS (
                SELECT 1 FROM education.students st
                JOIN education.group_students gs ON gs.student_id = st.id AND gs.organization_id = st.organization_id
                JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = st.organization_id AND g.archived_at IS NULL
                WHERE st.organization_id = t.organization_id AND st.person_id = CAST(:person AS uuid)
                  AND (t.person_id = g.owner_person_id OR EXISTS (
                      SELECT 1 FROM education.group_teachers gt
                      WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND gt.account_id = t.account_id
                  ))
            )
            OR EXISTS (
                SELECT 1 FROM education.parent_accounts pa
                JOIN education.student_guardians sg ON sg.organization_id = pa.organization_id AND sg.guardian_person_id = pa.guardian_person_id AND sg.status = 'confirmed'
                JOIN education.group_students gs ON gs.student_id = sg.student_id AND gs.organization_id = pa.organization_id
                JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = pa.organization_id AND g.archived_at IS NULL
                WHERE pa.organization_id = t.organization_id AND pa.guardian_person_id = CAST(:person AS uuid)
                  AND pa.account_id = CAST(:account AS uuid) AND pa.revoked_at IS NULL
                  AND (t.person_id = g.owner_person_id OR EXISTS (
                      SELECT 1 FROM education.group_teachers gt
                      WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND gt.account_id = t.account_id
                  ))
            )
        )";

        $total += (int) ($this->db->createCommand(
            "SELECT count(*) FROM education.teachers t
             JOIN core.persons p ON p.id = t.person_id
             WHERE t.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$teaAcl} AND {$stuMatch}",
            $baseParams,
        )->queryScalar() ?: 0);

        $teaRows = $this->db->createCommand(
            "SELECT t.id::text, t.organization_id::text AS \"organizationId\",
                    COALESCE(NULLIF(p.display_name, ''), CONCAT_WS(' ', p.given_name, p.family_name)) AS name,
                    t.created_at::text AS \"updatedAt\", {$stuScore} AS score
             FROM education.teachers t
             JOIN core.persons p ON p.id = t.person_id
             WHERE t.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$teaAcl} AND {$stuMatch}
             ORDER BY score DESC, t.created_at DESC
             LIMIT :limit",
            [...$baseParams, ':limit' => $limit],
        )->queryAll();

        foreach ($teaRows as $r) {
            $items[] = SearchSqlHelper::item(
                entityType: 'education.teacher',
                entityId: (string) $r['id'],
                category: 'education',
                title: (string) $r['name'],
                subtitle: 'Teacher',
                snippet: (string) $r['name'],
                route: '/education/teachers/' . (string) $r['id'],
                module: 'education',
                organizationId: (string) $r['organizationId'],
                updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                score: (float) ($r['score'] ?? 0.0),
            );
        }

        // 4. Lessons
        $lessonExpr = "l.title || ' ' || COALESCE(l.location, '')";
        $lessonMatch = SearchSqlHelper::matchExpr($lessonExpr);
        $lessonScore = SearchSqlHelper::scoreExpr($lessonExpr);
        $lessonAcl = $isAdmin ? 'true' : "(
            l.owner_person_id = CAST(:person AS uuid)
            OR EXISTS (
                SELECT 1 FROM education.group_teachers gt
                JOIN education.teachers t ON t.organization_id = gt.organization_id AND t.account_id = gt.account_id
                WHERE gt.organization_id = l.organization_id AND gt.group_id = l.group_id AND t.person_id = CAST(:person AS uuid)
            )
            OR EXISTS (
                SELECT 1 FROM education.group_students gs
                JOIN education.students st ON st.id = gs.student_id AND st.organization_id = gs.organization_id
                JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = gs.organization_id AND g.archived_at IS NULL
                WHERE gs.group_id = l.group_id AND gs.organization_id = l.organization_id AND st.person_id = CAST(:person AS uuid)
            )
            OR EXISTS (
                SELECT 1 FROM education.group_students gs
                JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = gs.organization_id AND g.archived_at IS NULL
                JOIN education.student_guardians sg ON sg.student_id = gs.student_id AND sg.organization_id = gs.organization_id AND sg.status = 'confirmed'
                JOIN education.parent_accounts pa ON pa.organization_id = sg.organization_id AND pa.guardian_person_id = sg.guardian_person_id AND pa.revoked_at IS NULL
                WHERE gs.group_id = l.group_id AND gs.organization_id = l.organization_id
                  AND pa.guardian_person_id = CAST(:person AS uuid) AND pa.account_id = CAST(:account AS uuid)
            )
        )";

        $total += (int) ($this->db->createCommand(
            "SELECT count(*) FROM education.lessons l
             WHERE l.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$lessonAcl} AND {$lessonMatch}",
            $baseParams,
        )->queryScalar() ?: 0);

        $lessonRows = $this->db->createCommand(
            "SELECT l.id::text, l.organization_id::text AS \"organizationId\", l.title, l.location, l.status,
                    l.starts_at::text AS \"startsAt\", l.created_at::text AS \"updatedAt\", {$lessonScore} AS score
             FROM education.lessons l
             WHERE l.organization_id = ANY(CAST(:orgs AS uuid[])) AND {$lessonAcl} AND {$lessonMatch}
             ORDER BY score DESC, l.starts_at DESC
             LIMIT :limit",
            [...$baseParams, ':limit' => $limit],
        )->queryAll();

        foreach ($lessonRows as $r) {
            $items[] = SearchSqlHelper::item(
                entityType: 'education.lesson',
                entityId: (string) $r['id'],
                category: 'education',
                title: (string) $r['title'],
                subtitle: sprintf('Lesson · %s', (string) $r['status']),
                snippet: (string) ($r['location'] !== '' ? $r['location'] : $r['startsAt']),
                route: '/education/lessons/' . (string) $r['id'],
                module: 'education',
                organizationId: (string) $r['organizationId'],
                updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                score: (float) ($r['score'] ?? 0.0),
            );
        }

        // 5. Homework assignments
        if ($this->access->tableExists('education.homework_assignments')) {
            $hwExpr = "h.title || ' ' || COALESCE(h.description, '')";
            $hwMatch = SearchSqlHelper::matchExpr($hwExpr);
            $hwScore = SearchSqlHelper::scoreExpr($hwExpr);
            $hwAcl = $isAdmin ? 'true' : "(
                h.author_person_id = CAST(:person AS uuid)
                OR (h.group_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM education.group_teachers gt
                    JOIN education.teachers t ON t.organization_id = gt.organization_id AND t.account_id = gt.account_id
                    WHERE gt.organization_id = h.organization_id AND gt.group_id = h.group_id AND t.person_id = CAST(:person AS uuid)
                ))
                OR (h.status = 'PUBLISHED' AND EXISTS (
                    SELECT 1 FROM education.homework_recipients hr
                    WHERE hr.assignment_id = h.id AND hr.organization_id = h.organization_id AND hr.person_id = CAST(:person AS uuid)
                ))
                OR (h.status = 'PUBLISHED' AND EXISTS (
                    SELECT 1 FROM education.homework_recipients hr
                    JOIN education.student_guardians sg ON sg.student_id = hr.student_id AND sg.organization_id = hr.organization_id AND sg.status = 'confirmed'
                    JOIN education.parent_accounts pa ON pa.organization_id = sg.organization_id AND pa.guardian_person_id = sg.guardian_person_id AND pa.revoked_at IS NULL
                    WHERE hr.assignment_id = h.id AND hr.organization_id = h.organization_id
                      AND pa.guardian_person_id = CAST(:person AS uuid) AND pa.account_id = CAST(:account AS uuid)
                ))
            )";

            $total += (int) ($this->db->createCommand(
                "SELECT count(*) FROM education.homework_assignments h
                 WHERE h.organization_id = ANY(CAST(:orgs AS uuid[]))
                   AND h.deleted_at IS NULL AND h.archived_at IS NULL
                   AND {$hwAcl} AND {$hwMatch}",
                $baseParams,
            )->queryScalar() ?: 0);

            $hwRows = $this->db->createCommand(
                "SELECT h.id::text, h.organization_id::text AS \"organizationId\", h.title, h.description,
                        h.status, h.type, h.created_at::text AS \"updatedAt\", {$hwScore} AS score
                 FROM education.homework_assignments h
                 WHERE h.organization_id = ANY(CAST(:orgs AS uuid[]))
                   AND h.deleted_at IS NULL AND h.archived_at IS NULL
                   AND {$hwAcl} AND {$hwMatch}
                 ORDER BY score DESC, h.created_at DESC
                 LIMIT :limit",
                [...$baseParams, ':limit' => $limit],
            )->queryAll();

            foreach ($hwRows as $r) {
                $items[] = SearchSqlHelper::item(
                    entityType: 'education.homework',
                    entityId: (string) $r['id'],
                    category: 'education',
                    title: (string) $r['title'],
                    subtitle: sprintf('Homework · %s (%s)', (string) $r['type'], (string) $r['status']),
                    snippet: SearchSqlHelper::snippet((string) ($r['description'] !== '' ? $r['description'] : $r['title']), $query->query),
                    route: '/education/homework/' . (string) $r['id'],
                    module: 'education',
                    organizationId: (string) $r['organizationId'],
                    updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                    score: (float) ($r['score'] ?? 0.0),
                );
            }
        }

        return ['items' => $items, 'totalsByCategory' => ['education' => $total]];
    }
}
