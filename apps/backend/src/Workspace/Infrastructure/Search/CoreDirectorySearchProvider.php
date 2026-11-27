<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure\Search;

use App\Workspace\Application\SearchProviderInterface;
use App\Workspace\Application\SearchQuery;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class CoreDirectorySearchProvider implements SearchProviderInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
    ) {}

    public function categories(): array
    {
        return ['people', 'organizations'];
    }

    public function moduleCode(): ?string
    {
        return null;
    }

    public function isAvailable(SearchQuery $query): bool
    {
        return $query->category === null || in_array($query->category, ['people', 'organizations'], true);
    }

    public function search(SearchQuery $query): array
    {
        $items = [];
        $totals = ['people' => 0, 'organizations' => 0];

        if ($query->category === null || $query->category === 'people') {
            $peopleRes = $this->searchPeople($query);
            $items = array_merge($items, $peopleRes['items']);
            $totals['people'] = $peopleRes['total'];
        }

        if ($query->category === null || $query->category === 'organizations') {
            $orgRes = $this->searchOrganizations($query);
            $items = array_merge($items, $orgRes['items']);
            $totals['organizations'] = $orgRes['total'];
        }

        return ['items' => $items, 'totalsByCategory' => $totals];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function searchPeople(SearchQuery $query): array
    {
        $orgsArray = SearchSqlHelper::uuidArrayLiteral($query->permittedOrgIds);
        $nameExpr = "COALESCE(p.display_name, '') || ' ' || p.given_name || ' ' || p.family_name";
        $matchExpr = SearchSqlHelper::matchExpr($nameExpr);
        $scoreExpr = SearchSqlHelper::scoreExpr($nameExpr);

        $canReadAllMembers = $query->isOrgAdmin
            || $query->hasPermission('core.persons.read')
            || $query->hasPermission('core.organization_memberships.read');

        $aclJoin = '';
        $aclWhere = '';
        $params = [
            ':orgs' => $orgsArray,
            ':q' => $query->query,
            ':likeQ' => $query->likePattern,
            ':limit' => $query->fetchLimit(),
        ];

        if (!$canReadAllMembers) {
            $params[':person'] = $query->personId;
            if ($query->isModuleEnabled('education') && $this->access->tableExists('education.teachers')) {
                $params[':account'] = $query->accountId;
                $aclWhere = " AND (
                    p.id = CAST(:person AS uuid)
                    OR EXISTS (
                        -- Teacher sees colleagues, students in their groups, and confirmed parents of those students
                        SELECT 1 FROM education.teachers t
                        WHERE t.organization_id = om.organization_id
                          AND t.person_id = CAST(:person AS uuid)
                          AND (
                              EXISTS (SELECT 1 FROM education.teachers t2 WHERE t2.organization_id = om.organization_id AND t2.person_id = p.id)
                              OR EXISTS (
                                  SELECT 1 FROM education.groups g
                                  JOIN education.group_students gs ON gs.group_id = g.id AND gs.organization_id = g.organization_id
                                  JOIN education.students st ON st.id = gs.student_id AND st.organization_id = g.organization_id
                                  WHERE g.organization_id = om.organization_id
                                    AND st.person_id = p.id
                                    AND (g.owner_person_id = CAST(:person AS uuid) OR EXISTS (
                                        SELECT 1 FROM education.group_teachers gt
                                        WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND gt.account_id = t.account_id
                                    ))
                              )
                              OR EXISTS (
                                  SELECT 1 FROM education.groups g
                                  JOIN education.group_students gs ON gs.group_id = g.id AND gs.organization_id = g.organization_id
                                  JOIN education.student_guardians sg ON sg.student_id = gs.student_id AND sg.organization_id = g.organization_id AND sg.status = 'confirmed'
                                  WHERE g.organization_id = om.organization_id
                                    AND sg.guardian_person_id = p.id
                                    AND (g.owner_person_id = CAST(:person AS uuid) OR EXISTS (
                                        SELECT 1 FROM education.group_teachers gt
                                        WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND gt.account_id = t.account_id
                                    ))
                              )
                          )
                    )
                    OR EXISTS (
                        -- Student sees teachers of their active groups
                        SELECT 1 FROM education.students st
                        JOIN education.group_students gs ON gs.student_id = st.id AND gs.organization_id = st.organization_id
                        JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = st.organization_id AND g.archived_at IS NULL
                        JOIN education.teachers t ON t.organization_id = g.organization_id
                        WHERE st.organization_id = om.organization_id
                          AND st.person_id = CAST(:person AS uuid)
                          AND t.person_id = p.id
                          AND (t.person_id = g.owner_person_id OR EXISTS (
                              SELECT 1 FROM education.group_teachers gt
                              WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND gt.account_id = t.account_id
                          ))
                    )
                    OR EXISTS (
                        -- Confirmed parent sees teachers of their confirmed children's active groups
                        SELECT 1 FROM education.parent_accounts pa
                        JOIN education.student_guardians sg ON sg.organization_id = pa.organization_id AND sg.guardian_person_id = pa.guardian_person_id AND sg.status = 'confirmed'
                        JOIN education.group_students gs ON gs.student_id = sg.student_id AND gs.organization_id = pa.organization_id
                        JOIN education.groups g ON g.id = gs.group_id AND g.organization_id = pa.organization_id AND g.archived_at IS NULL
                        JOIN education.teachers t ON t.organization_id = g.organization_id
                        WHERE pa.organization_id = om.organization_id
                          AND pa.guardian_person_id = CAST(:person AS uuid)
                          AND pa.account_id = CAST(:account AS uuid)
                          AND pa.revoked_at IS NULL
                          AND t.person_id = p.id
                          AND (t.person_id = g.owner_person_id OR EXISTS (
                              SELECT 1 FROM education.group_teachers gt
                              WHERE gt.organization_id = g.organization_id AND gt.group_id = g.id AND gt.account_id = t.account_id
                          ))
                    )
                )";
            } else {
                $aclWhere = ' AND p.id = CAST(:person AS uuid)';
            }
        }

        $baseFrom = "FROM core.persons p
                     JOIN core.organization_memberships om
                       ON om.person_id = p.id
                      AND om.status = 'active'
                      AND om.organization_id = ANY(CAST(:orgs AS uuid[]))
                     JOIN core.organizations o ON o.id = om.organization_id AND o.status = 'active'
                     {$aclJoin}
                     WHERE p.status = 'active'
                       {$aclWhere}
                       AND {$matchExpr}";

        $countParams = $params;
        unset($countParams[':limit']);
        $total = (int) ($this->db->createCommand("SELECT count(DISTINCT (p.id, om.organization_id)) {$baseFrom}", $countParams)->queryScalar() ?: 0);

        $rows = $this->db->createCommand(
            "SELECT DISTINCT ON (p.id, om.organization_id)
                    p.id::text AS \"personId\",
                    om.organization_id::text AS \"organizationId\",
                    o.name AS \"organizationName\",
                    COALESCE(NULLIF(p.display_name, ''), CONCAT_WS(' ', p.given_name, p.family_name)) AS \"fullName\",
                    p.given_name AS \"givenName\",
                    p.family_name AS \"familyName\",
                    p.updated_at::text AS \"updatedAt\",
                    {$scoreExpr} AS score
             {$baseFrom}
             ORDER BY p.id, om.organization_id, score DESC
             LIMIT :limit",
            $params,
        )->queryAll();

        $items = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $fullName = trim((string) ($r['fullName'] ?? ''));
            $items[] = SearchSqlHelper::item(
                entityType: 'core.person',
                entityId: (string) $r['personId'],
                category: 'people',
                title: $fullName,
                subtitle: (string) ($r['organizationName'] ?? 'Member'),
                snippet: trim((string) $r['givenName'] . ' ' . (string) $r['familyName']),
                route: '/people/' . (string) $r['personId'],
                module: 'core',
                organizationId: (string) $r['organizationId'],
                updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                score: (float) ($r['score'] ?? 0.0),
            );
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function searchOrganizations(SearchQuery $query): array
    {
        $expr = "o.name || ' ' || o.code";
        $matchExpr = SearchSqlHelper::matchExpr($expr);
        $scoreExpr = SearchSqlHelper::scoreExpr($expr);

        $params = [
            ':q' => $query->query,
            ':likeQ' => $query->likePattern,
            ':limit' => $query->fetchLimit(),
        ];

        if ($query->isSuperadmin && $query->scope === 'global') {
            $where = "o.status = 'active' AND {$matchExpr}";
        } else {
            $where = "o.status = 'active' AND o.id = ANY(CAST(:orgs AS uuid[])) AND {$matchExpr}";
            $params[':orgs'] = SearchSqlHelper::uuidArrayLiteral($query->permittedOrgIds);
        }

        $countParams = $params;
        unset($countParams[':limit']);
        $total = (int) ($this->db->createCommand("SELECT count(*) FROM core.organizations o WHERE {$where}", $countParams)->queryScalar() ?: 0);

        $rows = $this->db->createCommand(
            "SELECT o.id::text, o.code, o.name, o.updated_at::text AS \"updatedAt\", {$scoreExpr} AS score
             FROM core.organizations o
             WHERE {$where}
             ORDER BY score DESC, o.updated_at DESC
             LIMIT :limit",
            $params,
        )->queryAll();

        $items = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $items[] = SearchSqlHelper::item(
                entityType: 'core.organization',
                entityId: (string) $r['id'],
                category: 'organizations',
                title: (string) $r['name'],
                subtitle: (string) $r['code'],
                snippet: (string) $r['name'],
                route: '/organizations/' . (string) $r['id'],
                module: 'core',
                organizationId: (string) $r['id'],
                updatedAt: isset($r['updatedAt']) ? (string) $r['updatedAt'] : null,
                score: (float) ($r['score'] ?? 0.0),
            );
        }

        return ['items' => $items, 'total' => $total];
    }
}
