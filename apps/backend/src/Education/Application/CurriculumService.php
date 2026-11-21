<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Person\Application\CreatePerson;
use App\Core\Person\Application\CreatePersonCommand;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Shared\Domain\ValueNormalizer;
use App\Core\Time\Application\ClockInterface;

final readonly class CurriculumService
{
    public function __construct(
        private CurriculumStore $store,
        private EducationStore $education,
        private EducationAccess $access,
        private Mutation $mutations,
        private CreatePerson $persons,
        private OrganizationRepository $organizations,
        private PermissionService $permissions,
        private ClockInterface $clock,
    ) {}

    public function read(string $operation, Scope $scope, string|null $id, array $query = []): array
    {
        if ($id !== null) {
            Input::uuid($id);
        }
        return match ($operation) {
            'programs' => $this->programs($scope, Input::page($query['offset'] ?? null)),
            'program' => $this->store->program($scope, Input::uuid($id)),
            'curriculum_module' => $this->store->module($scope, Input::uuid($id)),
            'curriculum_lesson' => $this->store->curriculumLesson($scope, Input::uuid($id)),
            'group_programs' => ['items' => $this->store->groupPrograms($scope, Input::uuid($id))],
            'group_progress' => $this->store->progress($scope, Input::uuid($id), null),
            'student_curriculum_progress' => $this->studentProgress($scope, Input::uuid($id)),
            'own_curriculum_progress' => $this->store->progress($scope, null, $scope->studentId ?? throw new EducationError(403, 'access_denied')),
            'group_teachers' => $this->teachers($scope, Input::uuid($id)),
            default => throw new EducationError(404, 'not_found'),
        };
    }

    public function write(string $operation, Scope $scope, array $input, ?string $id = null): array
    {
        return $this->mutations->run($scope, $operation, fn(): array => match ($operation) {
            'create_program' => $this->store->saveProgram($scope, null, self::catalog($input, false, false)),
            'update_program' => $this->store->saveProgram($scope, Input::uuid($id), self::catalog($input, false, false)),
            'create_curriculum_module' => $this->store->saveModule($scope, null, Input::uuid($id), self::catalog($input, true, false)),
            'update_curriculum_module' => $this->store->saveModule($scope, Input::uuid($id), null, self::catalog($input, true, false)),
            'create_curriculum_lesson' => $this->store->saveCurriculumLesson($scope, null, Input::uuid($id), self::catalog($input, true, true)),
            'update_curriculum_lesson' => $this->store->saveCurriculumLesson($scope, Input::uuid($id), null, self::catalog($input, true, true)),
            'assign_program' => $this->assign($scope, Input::uuid($id), $input),
            'set_group_teachers' => $this->setTeachers($scope, Input::uuid($id), $input),
            'create_student' => $this->createStudent($scope, $input),
            default => throw new EducationError(404, 'not_found'),
        });
    }

    private function programs(Scope $scope, int $offset): array
    {
        $items = $this->store->programs($scope, $offset);
        return ['items' => array_slice($items, 0, 50), 'limit' => 50, 'offset' => $offset,
            'nextOffset' => count($items) > 50 ? $offset + 50 : null];
    }

    private function studentProgress(Scope $scope, string $id): array
    {
        $student = $this->education->student($scope, $id) ?? throw new EducationError(404, 'not_found');
        $this->access->assertResource($scope, 'student', $id);
        if (!$this->access->member($student['personId'], $scope->organizationId)) {
            throw new EducationError(404, 'not_found');
        }
        return $this->store->progress($scope, null, $id);
    }

    private function assign(Scope $scope, string $groupId, array $input): array
    {
        self::only($input, ['programId']);
        $this->access->assertResource($scope, 'group', $groupId);
        return $this->store->assignProgram($scope, $groupId, Input::uuid($input['programId'] ?? null));
    }

    private function teachers(Scope $scope, string $groupId): array
    {
        $result = $this->store->teachers($scope, $groupId);
        $result['items'] = array_values(array_filter($result['items'], fn(array $row): bool =>
            $this->access->member($row['personId'], $scope->organizationId)));
        $result['items'] = array_map(fn(array $row): array => [...$row,
            'assigned' => in_array($row['assigned'], [true, 1, '1', 't'], true),
            'name' => $this->access->personName($row['personId'])], $result['items']);
        return $result;
    }

    private function setTeachers(Scope $scope, string $groupId, array $input): array
    {
        self::only($input, ['accountIds']);
        $ids = $input['accountIds'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 50) {
            throw new EducationError(422, 'invalid_input');
        }
        foreach ($ids as $accountId) {
            Input::uuid($accountId);
        }
        $available = $this->teachers($scope, $groupId);
        $availableIds = array_column($available['items'], 'accountId');
        foreach ($ids as $accountId) {
            if (!in_array($accountId, $availableIds, true)) {
                throw new EducationError(403, 'teacher_outside_organization');
            }
        }
        $this->store->setTeachers($scope, $groupId, $ids);
        return $this->teachers($scope, $groupId);
    }

    /** Explicit Education permission delegates a bounded Core person creation use case. */
    private function createStudent(Scope $scope, array $input): array
    {
        self::only($input, ['givenName', 'familyName', 'middleName', 'displayName', 'birthDate', 'groupId']);
        if (!$this->permissions->hasPermission($scope->accountId, 'education.students.create')) {
            throw new EducationError(403, 'access_denied');
        }
        $groupId = Input::uuid($input['groupId'] ?? null);
        $this->access->assertResource($scope, 'group', $groupId);
        $group = $this->education->group($scope, $groupId) ?? throw new EducationError(404, 'not_found');
        if ($group['archivedAt'] !== null) {
            throw new EducationError(409, 'group_archived');
        }
        try {
            $given = ValueNormalizer::normalizePersonName(Input::text($input['givenName'] ?? null, 120), 120);
            $family = ValueNormalizer::normalizePersonName(Input::text($input['familyName'] ?? null, 120), 120);
            $middle = !isset($input['middleName']) || $input['middleName'] === '' ? null
                : ValueNormalizer::normalizePersonName(Input::text($input['middleName'], 120), 120);
            $display = !isset($input['displayName']) || $input['displayName'] === '' ? null
                : ValueNormalizer::normalizeDisplayName(Input::text($input['displayName'], 255), 255);
        } catch (\InvalidArgumentException) {
            throw new EducationError(422, 'invalid_person_name');
        }
        $birth = !isset($input['birthDate']) || $input['birthDate'] === '' ? null : Input::date($input['birthDate']);
        if ($birth !== null && ($birth < '1900-01-01' || $birth > $this->clock->now()->format('Y-m-d'))) {
            throw new EducationError(422, 'invalid_birth_date');
        }
        $person = $this->persons->execute(new CreatePersonCommand($given, $family, $middle, $display, $birth));
        if ($this->organizations->addMember($scope->organizationId, $person->id) === null) {
            throw new EducationError(409, 'membership_not_created');
        }
        $student = $this->education->enrollStudent($this->store->delegatedScope($scope, 'group', $groupId), $person->id, $groupId);
        return [...$student, 'name' => $person->displayName ?: trim($person->givenName . ' ' . $person->familyName)];
    }

    public static function catalog(array $input, bool $ordered, bool $lesson): array
    {
        $name = $lesson ? 'title' : 'name';
        self::only($input, [$name, 'description', 'position', 'archived', 'version']);
        $values = [$name => Input::text($input[$name] ?? null, 160),
            'description' => Input::text($input['description'] ?? '', 8000, false)];
        if ($ordered) {
            $position = $input['position'] ?? 0;
            if (!is_int($position) || $position < 0 || $position > 10000) {
                throw new EducationError(422, 'invalid_position');
            }
            $values['position'] = $position;
        }
        if (array_key_exists('archived', $input)) {
            if (!is_bool($input['archived'])) {
                throw new EducationError(422, 'invalid_input');
            }
            $values['archived'] = $input['archived'];
        }
        if (array_key_exists('version', $input)) {
            if (!is_int($input['version']) || $input['version'] < 1) {
                throw new EducationError(422, 'invalid_input');
            }
            $values['version'] = $input['version'];
        }
        return $values;
    }

    private static function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new EducationError(422, 'invalid_input');
        }
    }
}
