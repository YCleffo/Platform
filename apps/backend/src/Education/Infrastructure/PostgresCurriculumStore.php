<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\CurriculumStore;
use App\Education\Application\EducationError;
use App\Education\Application\Scope;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Curriculum catalogs are organization scoped; group snapshots never follow catalog edits. */
final readonly class PostgresCurriculumStore implements CurriculumStore
{
    public function __construct(private ConnectionInterface $db) {}

    public function programs(Scope $scope, int $offset): array
    {
        return $this->all(
            'SELECT id::text,name,description,version,archived_at::text AS "archivedAt"
            FROM education.programs WHERE organization_id=CAST(:org AS uuid)
            ORDER BY (archived_at IS NOT NULL),created_at,id LIMIT 51 OFFSET :offset',
            [':org' => $scope->organizationId, ':offset' => $offset],
        );
    }

    public function program(Scope $scope, string $id): array
    {
        $program = $this->required(
            'SELECT id::text,name,description,version,archived_at::text AS "archivedAt"
            FROM education.programs WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid)',
            [':org' => $scope->organizationId, ':id' => $id],
        );
        $program['modules'] = $this->all('SELECT id::text,name,description,position,version,archived_at::text AS "archivedAt"
            FROM education.curriculum_modules WHERE organization_id=CAST(:org AS uuid) AND program_id=CAST(:id AS uuid)
            ORDER BY position,id', [':org' => $scope->organizationId, ':id' => $id]);
        $lessons = $this->all(
            'SELECT l.id::text,l.module_id::text AS "moduleId",l.title,l.description,l.position,l.version,
            l.archived_at::text AS "archivedAt" FROM education.curriculum_lessons l JOIN education.curriculum_modules m ON m.id=l.module_id
            WHERE l.organization_id=CAST(:org AS uuid) AND m.program_id=CAST(:id AS uuid) ORDER BY l.position,l.id',
            [':org' => $scope->organizationId, ':id' => $id],
        );
        foreach ($program['modules'] as &$module) {
            $module['lessons'] = array_values(array_filter($lessons, static fn(array $lesson): bool => $lesson['moduleId'] === $module['id']));
        }
        unset($module);
        return $program;
    }

    public function saveProgram(Scope $scope, ?string $id, array $values): array
    {
        $id = $this->saveCatalog($scope, 'program', $id, null, $values);
        return $this->program($scope, $id);
    }

    public function saveModule(Scope $scope, ?string $id, ?string $programId, array $values): array
    {
        if ($programId !== null) {
            $parent = $this->program($scope, $programId);
            if ($parent['archivedAt'] !== null) {
                throw new EducationError(409, 'program_archived');
            }
        }
        $id = $this->saveCatalog($scope, 'module', $id, $programId, $values);
        return $this->module($scope, $id);
    }

    public function module(Scope $scope, string $id): array
    {
        $module = $this->required('SELECT id::text,program_id::text AS "programId",name,description,position,version,
            archived_at::text AS "archivedAt" FROM education.curriculum_modules
            WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid)', [':org' => $scope->organizationId, ':id' => $id]);
        $module['lessons'] = $this->all('SELECT id::text,title,description,position,version,archived_at::text AS "archivedAt"
            FROM education.curriculum_lessons WHERE organization_id=CAST(:org AS uuid) AND module_id=CAST(:id AS uuid)
            ORDER BY position,id', [':org' => $scope->organizationId, ':id' => $id]);
        return $module;
    }

    public function saveCurriculumLesson(Scope $scope, ?string $id, ?string $moduleId, array $values): array
    {
        if ($moduleId !== null) {
            $module = $this->module($scope, $moduleId);
            if ($module['archivedAt'] !== null || $this->program($scope, $module['programId'])['archivedAt'] !== null) {
                throw new EducationError(409, 'program_archived');
            }
        }
        $id = $this->saveCatalog($scope, 'lesson', $id, $moduleId, $values);
        return $this->curriculumLesson($scope, $id);
    }

    public function curriculumLesson(Scope $scope, string $id): array
    {
        return $this->required('SELECT id::text,module_id::text AS "moduleId",title,description,position,version,
            archived_at::text AS "archivedAt" FROM education.curriculum_lessons
            WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid)', [':org' => $scope->organizationId, ':id' => $id]);
    }

    private function saveCatalog(Scope $scope, string $type, ?string $id, ?string $parentId, array $values): string
    {
        [$table, $name, $parent] = match ($type) {
            'program' => ['programs', 'name', null], 'module' => ['curriculum_modules', 'name', 'program_id'],
            'lesson' => ['curriculum_lessons', 'title', 'module_id'],
        };
        return $this->db->transaction(function () use ($scope, $type, $table, $name, $parent, $id, $parentId, $values): string {
            // All writers lock the same root before any tree row. Assignment snapshots
            // therefore observe a complete catalog revision under READ COMMITTED too.
            $rootSql = match ($type) {
                'program' => $id === null ? null : 'SELECT id::text FROM education.programs WHERE id=CAST(:id AS uuid)',
                'module' => $id === null
                    ? 'SELECT id::text FROM education.programs WHERE id=CAST(:id AS uuid)'
                    : 'SELECT p.id::text FROM education.programs p JOIN education.curriculum_modules m ON m.program_id=p.id WHERE m.id=CAST(:id AS uuid)',
                'lesson' => $id === null
                    ? 'SELECT p.id::text FROM education.programs p JOIN education.curriculum_modules m ON m.program_id=p.id WHERE m.id=CAST(:id AS uuid)'
                    : 'SELECT p.id::text FROM education.programs p JOIN education.curriculum_modules m ON m.program_id=p.id
                       JOIN education.curriculum_lessons l ON l.module_id=m.id WHERE l.id=CAST(:id AS uuid)',
            };
            if ($rootSql !== null) {
                $alias = $type === 'program' || ($type === 'module' && $id === null) ? '' : 'p.';
                $this->required(
                    $rootSql . ' AND ' . $alias . 'organization_id=CAST(:org AS uuid) FOR UPDATE' . ($alias === '' ? '' : ' OF p'),
                    [':org' => $scope->organizationId, ':id' => $id ?? $parentId],
                );
            }
            $params = [':org' => $scope->organizationId, ':name' => $values[$name], ':description' => $values['description']];
            if ($parent !== null) {
                $params[':position'] = $values['position'];
            }
            if ($id === null) {
                $columns = "organization_id,$name,description";
                $sqlValues = 'CAST(:org AS uuid),:name,:description';
                if ($parent !== null) {
                    $columns .= ",$parent,position";
                    $sqlValues .= ',CAST(:parent AS uuid),:position';
                    $params[':parent'] = $parentId;
                } else {
                    $columns .= ',created_by_account_id';
                    $sqlValues .= ',CAST(:actor AS uuid)';
                    $params[':actor'] = $scope->accountId;
                }
                return $this->required("INSERT INTO education.$table($columns) VALUES($sqlValues) RETURNING id::text", $params)['id'];
            }
            $before = $this->required(
                "SELECT to_jsonb(c)::text AS payload,version FROM education.$table c
                WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid) FOR UPDATE",
                [':org' => $scope->organizationId, ':id' => $id],
            );
            if (isset($values['version']) && (int) $before['version'] !== $values['version']) {
                throw new EducationError(409, 'catalog_version_conflict');
            }
            $this->db->createCommand(
                'INSERT INTO education.curriculum_revisions(organization_id,entity_type,entity_id,version,payload,revised_by_account_id)
                VALUES(CAST(:org AS uuid),:type,CAST(:id AS uuid),:version,CAST(:payload AS jsonb),CAST(:actor AS uuid))',
                [':org' => $scope->organizationId, ':type' => $type, ':id' => $id, ':version' => $before['version'],
                    ':payload' => $before['payload'], ':actor' => $scope->accountId],
            )->execute();
            $params[':id'] = $id;
            $set = "$name=:name,description=:description,version=version+1,updated_at=now()";
            if ($parent !== null) {
                $set .= ',position=:position';
            }
            if (array_key_exists('archived', $values)) {
                $set .= $values['archived'] ? ',archived_at=COALESCE(archived_at,now())' : ',archived_at=NULL';
            }
            $this->required("UPDATE education.$table SET $set WHERE organization_id=CAST(:org AS uuid)
                AND id=CAST(:id AS uuid) RETURNING id::text", $params);
            return $id;
        });
    }

    public function groupPrograms(Scope $scope, string $groupId): array
    {
        $this->assertGroup($scope, $groupId);
        return array_map(self::assignment(...), $this->all(
            'SELECT id::text,program_id::text AS "programId",program_version AS "programVersion",
            snapshot::text,assigned_at::text AS "assignedAt",ended_at::text AS "endedAt" FROM education.group_programs
            WHERE organization_id=CAST(:org AS uuid) AND group_id=CAST(:id AS uuid) ORDER BY assigned_at DESC,id DESC',
            [':org' => $scope->organizationId, ':id' => $groupId],
        ));
    }

    public function assignProgram(Scope $scope, string $groupId, string $programId): array
    {
        return $this->db->transaction(function () use ($scope, $groupId, $programId): array {
            $group = $this->assertGroup($scope, $groupId, true);
            if ($group['archivedAt'] !== null) {
                throw new EducationError(409, 'group_archived');
            }
            // Serialize catalog edits with snapshot creation; tree is read in a repeatable statement snapshot below.
            $this->required(
                'SELECT id::text FROM education.programs WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid) FOR UPDATE',
                [':org' => $scope->organizationId, ':id' => $programId],
            );
            $program = $this->program($scope, $programId);
            if ($program['archivedAt'] !== null) {
                throw new EducationError(409, 'program_archived');
            }
            $program['modules'] = array_values(array_filter($program['modules'], static fn(array $m): bool => $m['archivedAt'] === null));
            $count = 0;
            foreach ($program['modules'] as &$module) {
                $module['lessons'] = array_values(array_filter($module['lessons'], static fn(array $l): bool => $l['archivedAt'] === null));
                $count += count($module['lessons']);
            }
            unset($module);
            if ($count === 0) {
                throw new EducationError(409, 'program_has_no_lessons');
            }
            $active = $this->all(
                'SELECT id::text,program_id::text AS "programId",snapshot::text FROM education.group_programs
                WHERE organization_id=CAST(:org AS uuid) AND group_id=CAST(:id AS uuid) AND ended_at IS NULL FOR UPDATE',
                [':org' => $scope->organizationId, ':id' => $groupId],
            );
            if ($active !== [] && $active[0]['programId'] === $programId
                && json_decode($active[0]['snapshot'], true, flags: JSON_THROW_ON_ERROR) === $program) {
                return $this->groupPrograms($scope, $groupId)[0];
            }
            $this->db->createCommand(
                'UPDATE education.group_programs SET ended_at=now()
                WHERE organization_id=CAST(:org AS uuid) AND group_id=CAST(:id AS uuid) AND ended_at IS NULL',
                [':org' => $scope->organizationId, ':id' => $groupId],
            )->execute();
            $row = $this->required(
                'INSERT INTO education.group_programs(organization_id,group_id,program_id,program_version,snapshot,assigned_by_account_id)
                VALUES(CAST(:org AS uuid),CAST(:group AS uuid),CAST(:program AS uuid),:version,CAST(:snapshot AS jsonb),CAST(:actor AS uuid))
                RETURNING id::text,program_id::text AS "programId",program_version AS "programVersion",snapshot::text,
                assigned_at::text AS "assignedAt",ended_at::text AS "endedAt"',
                [':org' => $scope->organizationId, ':group' => $groupId, ':program' => $programId, ':version' => $program['version'],
                    ':snapshot' => json_encode($program, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ':actor' => $scope->accountId],
            );
            return self::assignment($row);
        });
    }

    public function bindLesson(Scope $scope, string $lessonId, ?string $curriculumLessonId): void
    {
        $this->db->transaction(function () use ($scope, $lessonId, $curriculumLessonId): void {
            $lesson = $this->required(
                'SELECT l.id::text,l.group_id::text AS "groupId",l.status,l.curriculum_lesson_id::text AS "curriculumLessonId"
                FROM education.lessons l WHERE l.organization_id=CAST(:org AS uuid) AND l.id=CAST(:id AS uuid)
                AND education.can_teach_group(l.organization_id,CAST(:actor AS uuid),l.group_id) FOR UPDATE',
                [':org' => $scope->organizationId, ':actor' => $scope->accountId, ':id' => $lessonId],
            );
            if ($lesson['status'] !== 'scheduled') {
                if ($lesson['curriculumLessonId'] === $curriculumLessonId) {
                    return;
                }
                throw new EducationError(409, 'lesson_locked');
            }
            if ($curriculumLessonId === null) {
                $this->db->createCommand('UPDATE education.lessons SET group_program_id=NULL,curriculum_lesson_id=NULL,curriculum_snapshot=NULL
                    WHERE id=CAST(:id AS uuid)', [':id' => $lessonId])->execute();
                return;
            }
            $active = array_values(array_filter($this->groupPrograms($scope, $lesson['groupId']), static fn(array $a): bool => $a['endedAt'] === null));
            foreach ($active as $assignment) {
                foreach ($assignment['modules'] as $module) {
                    foreach ($module['lessons'] as $entry) {
                        if ($entry['id'] !== $curriculumLessonId) {
                            continue;
                        }
                        $this->db->createCommand(
                            'UPDATE education.lessons SET group_program_id=CAST(:assignment AS uuid),
                    curriculum_lesson_id=CAST(:curriculum AS uuid),curriculum_snapshot=CAST(:snapshot AS jsonb) WHERE id=CAST(:id AS uuid)',
                            [':assignment' => $assignment['id'], ':curriculum' => $curriculumLessonId, ':id' => $lessonId,
                                ':snapshot' => json_encode([...$entry, 'moduleId' => $module['id'], 'moduleName' => $module['name'],
                                    'programName' => $assignment['name']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                        )->execute();
                        return;
                    }
                }
            }
            throw new EducationError(422, 'curriculum_lesson_not_assigned');
        });
    }

    public function progress(Scope $scope, ?string $groupId, ?string $studentId): array
    {
        if ($groupId !== null) {
            $this->assertGroup($scope, $groupId);
        }
        $params = [':org' => $scope->organizationId, ':actor' => $scope->accountId];
        $where = 'gp.organization_id=CAST(:org AS uuid) AND education.can_teach_group(gp.organization_id,CAST(:actor AS uuid),gp.group_id)';
        if ($scope->studentId !== null) {
            if ($studentId !== $scope->studentId || $groupId !== null) {
                throw new EducationError(403, 'access_denied');
            }
            unset($params[':actor']);
            $where = 'gp.organization_id=CAST(:org AS uuid)';
        }
        if ($groupId !== null) {
            $where .= ' AND gp.group_id=CAST(:group AS uuid)';
            $params[':group'] = $groupId;
        }
        if ($studentId !== null) {
            $params[':student'] = $studentId;
            // Include historical attendance even if a student has left the current roster.
            $where .= ' AND (EXISTS(SELECT 1 FROM education.group_students gs WHERE gs.group_id=gp.group_id AND gs.student_id=CAST(:student AS uuid))
                OR EXISTS(SELECT 1 FROM education.lessons l JOIN education.attendance a ON a.lesson_id=l.id
                    WHERE l.group_program_id=gp.id AND a.student_id=CAST(:student AS uuid)))';
        }
        $assignments = $this->all('SELECT gp.id::text,gp.group_id::text AS "groupId",gp.program_id::text AS "programId",gp.snapshot::text,
            gp.ended_at::text AS "endedAt",gp.assigned_at::text AS "assignedAt" FROM education.group_programs gp WHERE ' . $where . ' ORDER BY gp.assigned_at,gp.id', $params);
        $programs = [];
        foreach ($assignments as $row) {
            $assignment = self::assignment($row);
            $resultParams = [':id' => $assignment['id'], ':org' => $scope->organizationId];
            $resultFilter = '';
            if ($studentId !== null) {
                $resultParams[':student'] = $studentId;
                $resultFilter = ' AND r.student_id=CAST(:student AS uuid)';
            }
            $facts = $this->all('SELECT l.curriculum_lesson_id::text AS id,
                bool_or(l.status=\'completed\') AS completed,
                bool_or(l.status=\'completed\' AND EXISTS(SELECT 1 FROM education.lesson_results r
                    WHERE r.lesson_id=l.id AND r.status=\'mastered\'' . $resultFilter . ')) AS mastered
                FROM education.lessons l WHERE l.organization_id=CAST(:org AS uuid) AND l.group_program_id=CAST(:id AS uuid)
                GROUP BY l.curriculum_lesson_id', $resultParams);
            $completed = [];
            $mastered = [];
            foreach ($facts as $fact) {
                if (self::truth($fact['completed'])) {
                    $completed[$fact['id']] = true;
                }
                if (self::truth($fact['mastered'])) {
                    $mastered[$fact['id']] = true;
                }
            }
            $total = 0;
            $done = 0;
            $masteredCount = 0;
            $modules = [];
            foreach ($assignment['modules'] as $module) {
                $ids = array_column($module['lessons'], 'id');
                $moduleDone = count(array_filter($ids, static fn(string $id): bool => isset(($studentId === null ? $completed : $mastered)[$id])));
                $total += count($ids);
                $done += $moduleDone;
                $masteredCount += count(array_filter($ids, static fn(string $id): bool => isset($mastered[$id])));
                $modules[] = ['id' => $module['id'], 'name' => $module['name'], 'totalLessons' => count($ids),
                    'completedLessons' => $moduleDone, 'percent' => count($ids) === 0 ? 0 : (int) round(100 * $moduleDone / count($ids)),
                    'status' => $moduleDone === 0 ? 'not_started' : ($moduleDone === count($ids) ? 'completed' : 'in_progress')];
            }
            $programs[] = ['assignmentId' => $assignment['id'], 'programId' => $assignment['programId'], 'groupId' => $assignment['groupId'],
                'name' => $assignment['name'], 'endedAt' => $assignment['endedAt'], 'totalLessons' => $total, 'completedLessons' => $done,
                'masteredLessons' => $masteredCount, 'percent' => $total === 0 ? 0 : (int) round(100 * $done / $total), 'modules' => $modules,
                'status' => $done === 0 ? 'not_started' : ($done === $total ? 'completed' : 'in_progress')];
        }
        return ['programs' => $programs];
    }

    public function teachers(Scope $scope, string $groupId): array
    {
        $group = $this->assertGroup($scope, $groupId);
        return ['ownerAccountId' => $group['ownerAccountId'], 'canAssign' => $group['ownerAccountId'] === $scope->accountId,
            'items' => $this->all(
                'SELECT t.account_id::text AS "accountId",t.person_id::text AS "personId",
                (EXISTS(SELECT 1 FROM education.group_teachers gt WHERE gt.group_id=CAST(:id AS uuid) AND gt.account_id=t.account_id)
                OR t.account_id=CAST(:owner AS uuid)) AS assigned FROM education.teachers t
                WHERE t.organization_id=CAST(:org AS uuid) ORDER BY t.created_at,t.id',
                [':org' => $scope->organizationId, ':id' => $groupId, ':owner' => $group['ownerAccountId']],
            )];
    }

    public function setTeachers(Scope $scope, string $groupId, array $accountIds): array
    {
        return $this->db->transaction(function () use ($scope, $groupId, $accountIds): array {
            $group = $this->assertGroup($scope, $groupId, true);
            if ($group['ownerAccountId'] !== $scope->accountId) {
                throw new EducationError(403, 'group_owner_required');
            }
            if ($group['archivedAt'] !== null) {
                throw new EducationError(409, 'group_archived');
            }
            $accountIds = array_values(array_unique([...$accountIds, $scope->accountId]));
            foreach ($accountIds as $accountId) {
                $this->required(
                    'SELECT id::text FROM education.teachers WHERE organization_id=CAST(:org AS uuid) AND account_id=CAST(:id AS uuid)',
                    [':org' => $scope->organizationId, ':id' => $accountId],
                );
            }
            $this->db->createCommand(
                'DELETE FROM education.group_teachers WHERE organization_id=CAST(:org AS uuid) AND group_id=CAST(:id AS uuid)',
                [':org' => $scope->organizationId, ':id' => $groupId],
            )->execute();
            foreach ($accountIds as $accountId) {
                $this->db->createCommand(
                    'INSERT INTO education.group_teachers(group_id,organization_id,account_id,assigned_by_account_id)
                VALUES(CAST(:group AS uuid),CAST(:org AS uuid),CAST(:account AS uuid),CAST(:actor AS uuid))',
                    [':org' => $scope->organizationId, ':group' => $groupId, ':account' => $accountId, ':actor' => $scope->accountId],
                )->execute();
            }
            return $this->teachers($scope, $groupId);
        });
    }

    private function assertGroup(Scope $scope, string $groupId, bool $lock = false): array
    {
        return $this->required(
            'SELECT id::text,owner_account_id::text AS "ownerAccountId",archived_at::text AS "archivedAt"
            FROM education.groups WHERE organization_id=CAST(:org AS uuid) AND id=CAST(:id AS uuid)
            AND education.can_teach_group(organization_id,CAST(:actor AS uuid),id)' . ($lock ? ' FOR UPDATE' : ''),
            [':org' => $scope->organizationId, ':actor' => $scope->accountId, ':id' => $groupId],
        );
    }

    public function delegatedScope(Scope $scope, string $type, string $id): Scope
    {
        $sql = match ($type) {
            'group' => 'SELECT owner_account_id::text AS owner FROM education.groups
                WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid)
                AND education.can_teach_group(organization_id,CAST(:actor AS uuid),id)',
            'lesson' => 'SELECT owner_account_id::text AS owner FROM education.lessons
                WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid)
                AND education.can_teach_group(organization_id,CAST(:actor AS uuid),group_id)',
            'student' => 'SELECT s.owner_account_id::text AS owner FROM education.students s
                WHERE s.id=CAST(:id AS uuid) AND s.organization_id=CAST(:org AS uuid)
                AND (s.owner_account_id=CAST(:actor AS uuid) OR EXISTS(SELECT 1 FROM education.group_students gs
                WHERE gs.student_id=s.id AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id)))',
            default => throw new EducationError(422, 'invalid_input'),
        };
        $row = $this->required($sql, [':org' => $scope->organizationId, ':actor' => $scope->actor(), ':id' => $id]);
        return new Scope(
            $scope->organizationId,
            $row['owner'],
            $scope->personId,
            $scope->locale,
            $scope->timezone,
            $scope->studentId,
            $scope->actor(),
        );
    }

    private static function assignment(array $row): array
    {
        $snapshot = json_decode($row['snapshot'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['snapshot']);
        return [...$row, 'name' => $snapshot['name'], 'description' => $snapshot['description'], 'modules' => $snapshot['modules']];
    }

    private static function truth(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }

    private function all(string $sql, array $params): array
    {
        return array_map(self::normalize(...), $this->db->createCommand($sql, $params)->queryAll());
    }
    private function required(string $sql, array $params): array
    {
        $row = $this->db->createCommand($sql, $params)->queryOne();
        return is_array($row) ? self::normalize($row) : throw new EducationError(404, 'not_found');
    }

    private static function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if (in_array($key, ['version', 'programVersion', 'position'], true)) {
                $row[$key] = (int) $value;
            }
            if (str_ends_with($key, 'At') && is_string($value)) {
                $row[$key] = (new \DateTimeImmutable($value))->format(DATE_ATOM);
            }
        }
        return $row;
    }
}
