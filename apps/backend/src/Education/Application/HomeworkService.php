<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Rbac\Application\PermissionService;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

/** One transaction owns each lifecycle transition, history row and extension effect. */
final readonly class HomeworkService
{
    public function __construct(
        private HomeworkStore $store,
        private EducationStore $education,
        private EducationAccess $access,
        private Mutation $mutations,
        private PermissionService $permissions,
        private ClockInterface $clock,
        private HomeworkExtensions $extensions,
        private ActivityHandlerRegistry $activityHandlers,
    ) {}

    public function read(string $operation, Scope $scope, array $query = [], ?string $id = null): array
    {
        $this->authorize($scope, str_starts_with($operation, 'student_'), false);
        $now = $this->now();
        $filters = self::filters($query);
        $offset = Input::page(isset($query['offset']) ? (string) $query['offset'] : null);
        return match ($operation) {
            'teacher_list' => $this->teacherList($scope, $filters, $offset, $now),
            'teacher_detail' => $this->teacherDetail($scope, Input::uuid($id), $now),
            'teacher_history' => $this->history($scope, Input::uuid($id), $offset, $now),
            'teacher_submissions' => $this->recipientList($scope, Input::uuid($id), $offset, $now),
            'teacher_submission_detail', 'submission_detail' => $this->teacherSubmission($scope, Input::uuid($id), $now),
            'teacher_summary' => [...$this->store->summary($scope, $filters, $now), 'now' => $now],
            'student_list' => $this->studentList($scope, $filters, $offset, $now),
            'student_detail' => $this->studentDetail($scope, Input::uuid($id), $now),
            'student_summary' => [...$this->store->summary($scope, [], $now), 'now' => $now],
            default => throw new EducationError(404, 'not_found'),
        };
    }

    public function write(string $operation, Scope $scope, array $input = [], ?string $id = null): array
    {
        $this->authorize($scope, in_array($operation, ['save_draft', 'submit'], true), $operation === 'review');
        return $this->store->transaction(function () use ($operation, $scope, $input, $id): array {
            // A persisted exact replay bypasses both mutation/audit and extension side effects.
            if (in_array($operation, ['submit', 'review'], true)) {
                $key = Input::uuid($input['idempotencyKey'] ?? null);
                $resource = Input::uuid($id);
                if ($operation === 'submit') {
                    $this->store->assignment($scope, $resource, true);
                    $this->store->studentRecipient($scope, $resource, true);
                } else {
                    $recipient = $this->store->submissionRecipient($scope, $resource);
                    $this->store->teacherAssignment($scope, $recipient['assignmentId'], true);
                    $this->store->recipient($scope, $recipient['id'], true);
                }
                $replay = $this->store->replay($scope, $operation, $key, $resource, $input);
                if ($replay !== null) {
                    return $replay;
                }
            }
            return $this->mutations->run($scope, 'homework_' . $operation, function () use ($operation, $scope, $input, $id): array {
                $now = $this->now();
                $result = match ($operation) {
                    'create' => $this->create($scope, $input, $now),
                    'update' => $this->update($scope, Input::uuid($id), $input, $now),
                    'publish', 'close', 'archive' => $this->transition($scope, Input::uuid($id), $input, $operation, $now),
                    'add_recipients' => $this->addRecipients($scope, Input::uuid($id), $input, $now),
                    'save_draft' => $this->saveDraft($scope, Input::uuid($id), $input, $now),
                    'submit' => $this->submit($scope, Input::uuid($id), $input, $now),
                    'review' => $this->review($scope, Input::uuid($id), $input, $now),
                    default => throw new EducationError(404, 'not_found'),
                };
                if (in_array($operation, ['submit', 'review'], true)) {
                    $this->store->remember($scope, $operation, $input['idempotencyKey'], (string) $id, $input, $result);
                }
                return $result;
            });
        });
    }

    private function authorize(Scope $scope, bool $student, bool $review): void
    {
        $permission = $student ? 'education.student.use' : ($review ? 'education.homework.review' : 'education.homework.manage');
        if (!$this->permissions->hasPermission($scope->actor(), 'education.access')
            || !$this->permissions->hasPermission($scope->actor(), $permission)
            || !$this->access->member($scope->personId, $scope->organizationId)) {
            throw new EducationError(403, 'access_denied');
        }
        if ($student) {
            $actual = $this->education->studentByAccount($scope->actor(), $scope->organizationId, $scope->personId);
            if ($scope->studentId === null || $actual === null || $actual['id'] !== $scope->studentId) {
                throw new EducationError(403, 'access_denied');
            }
        } elseif (!$this->education->teacherExists($scope)) {
            throw new EducationError(403, 'access_denied');
        }
    }

    private function teacherDetail(Scope $scope, string $id, string $now): array
    {
        $assignment = $this->store->teacherAssignment($scope, $id);
        $this->access->assertResource($scope, 'homework', $id);
        return ['assignment' => $this->extensions->assignmentView($scope, $assignment),
            'counts' => $this->store->summary($scope, [], $now, $id), 'now' => $now];
    }

    private function studentDetail(Scope $scope, string $id, string $now): array
    {
        $recipient = $this->store->studentRecipient($scope, $id);
        $assignment = $this->store->assignment($scope, $id);
        $this->access->assertResource($scope, 'homework', $id);
        return $this->detail($scope, $assignment, $recipient, $now);
    }

    private function teacherSubmission(Scope $scope, string $id, string $now): array
    {
        $recipient = $this->store->submissionRecipient($scope, $id);
        $assignment = $this->store->teacherAssignment($scope, $recipient['assignmentId']);
        $this->access->assertResource($scope, 'homework', $assignment['id']);
        $this->access->assertResource($scope, 'student', $recipient['studentId']);
        if (!$this->access->member($recipient['personId'], $scope->organizationId)) {
            throw new EducationError(404, 'not_found');
        }
        return $this->detail($scope, $assignment, $recipient, $now);
    }

    private function detail(Scope $scope, array $assignment, array $recipient, string $now): array
    {
        return ['assignment' => $this->extensions->assignmentView($scope, $assignment), 'recipient' => $recipient,
            'submission' => $this->extensions->submissionView($scope, $assignment, $this->store->submission($scope, $recipient)), 'now' => $now];
    }

    private function teacherList(Scope $scope, array $filters, int $offset, string $now): array
    {
        $items = $this->store->assignments($scope, $filters, $offset, $now);
        return [...self::page($items, $offset), 'now' => $now];
    }

    private function studentList(Scope $scope, array $filters, int $offset, string $now): array
    {
        return [...self::page($this->store->studentAssignments($scope, $filters, $offset, $now), $offset),
            'counts' => $this->store->summary($scope, [], $now), 'now' => $now];
    }

    private function history(Scope $scope, string $id, int $offset, string $now): array
    {
        $this->store->teacherAssignment($scope, $id);
        $this->access->assertResource($scope, 'homework', $id);
        return [...self::page($this->store->history($scope, $id, $offset), $offset), 'now' => $now];
    }

    private function recipientList(Scope $scope, string $id, int $offset, string $now): array
    {
        $this->store->teacherAssignment($scope, $id);
        $this->access->assertResource($scope, 'homework', $id);
        $items = $this->store->recipients($scope, $id, $offset);
        foreach ($items as &$item) {
            $item['name'] = $this->access->personName($item['personId']);
        }
        unset($item);
        return [...self::page($items, $offset), 'counts' => $this->store->summary($scope, [], $now, $id), 'now' => $now];
    }

    private function create(Scope $scope, array $input, string $now): array
    {
        self::only($input, [...self::FIELDS, 'studentIds']);
        $values = $this->values($scope, $input);
        $students = $this->students($scope, $input['studentIds'] ?? []);
        $id = $this->store->createAssignment($scope, $values, $now);
        $this->store->addRecipients($scope, $id, $students, $now);
        $this->store->revision($scope, $this->store->assignment($scope, $id), 'created', $now);
        return $this->teacherDetail($scope, $id, $now);
    }

    private function update(Scope $scope, string $id, array $input, string $now): array
    {
        self::only($input, [...self::FIELDS, 'studentIds', 'version']);
        $before = $this->store->teacherAssignment($scope, $id, true);
        self::version($before, $input);
        if (!in_array($before['status'], ['DRAFT', 'PUBLISHED'], true)) {
            throw new EducationError(409, 'assignment_not_editable');
        }
        if ($before['status'] === 'PUBLISHED') {
            if (isset($input['maxAttempts']) && $input['maxAttempts'] < $before['maxAttempts']) {
                throw new EducationError(409, 'max_attempts_cannot_decrease');
            }
            foreach (['groupId', 'lessonId', 'type', 'activityCode', 'activityConfig', 'attemptPolicy', 'studentIds'] as $field) {
                if (!array_key_exists($field, $input)) {
                    continue;
                }
                $same = $field === 'activityConfig' ? (array) $input[$field] == (array) $before[$field] : $input[$field] === ($before[$field] ?? null);
                if (!$same) {
                    throw new EducationError(409, 'published_binding_immutable');
                }
            }
        }
        $values = $this->values($scope, [...$before, ...$input]);
        $this->store->updateAssignment($scope, $id, $values);
        if (isset($input['studentIds']) && $before['status'] === 'DRAFT') {
            $this->store->replaceDraftRecipients($scope, $id, $this->students($scope, $input['studentIds']), $now);
        }
        $after = $this->store->assignment($scope, $id);
        $this->store->revision($scope, $after, 'updated', $now);
        if ($before['status'] === 'PUBLISHED' && $before['dueAt'] !== $after['dueAt']) {
            $this->extensions->changed($scope, 'deadline_changed', $after);
        }
        return $this->teacherDetail($scope, $id, $now);
    }

    private function transition(Scope $scope, string $id, array $input, string $operation, string $now): array
    {
        self::only($input, ['version']);
        $assignment = $this->store->teacherAssignment($scope, $id, true);
        self::version($assignment, $input);
        $allowed = match ($operation) {
            'publish' => ['DRAFT'], 'close' => ['PUBLISHED'], 'archive' => ['CLOSED'],
        };
        if (!in_array($assignment['status'], $allowed, true)) {
            throw new EducationError(409, 'invalid_assignment_transition');
        }
        if ($operation === 'publish') {
            if ($assignment['groupId'] !== null) {
                $members = array_values(array_filter(
                    $this->store->groupRecipients($scope, $assignment['groupId']),
                    fn(array $student): bool => $this->access->member($student['personId'], $scope->organizationId)
                        && $this->access->resourceAllowed($scope->personId, 'student', $student['id']),
                ));
                $this->store->addRecipients($scope, $id, $members, $now);
            }
            if ($this->store->summary($scope, [], $now, $id)['assigned'] === 0) {
                throw new EducationError(422, 'recipients_required');
            }
        }
        $this->store->setStatus($scope, $id, match ($operation) {
            'publish' => 'PUBLISHED', 'close' => 'CLOSED', 'archive' => 'ARCHIVED',
        }, $now);
        $after = $this->store->assignment($scope, $id);
        $this->store->revision($scope, $after, $operation, $now);
        if ($operation === 'publish') {
            $this->extensions->changed($scope, 'published', $after);
        }
        return $this->teacherDetail($scope, $id, $now);
    }

    private function addRecipients(Scope $scope, string $id, array $input, string $now): array
    {
        self::only($input, ['version', 'studentIds']);
        $assignment = $this->store->teacherAssignment($scope, $id, true);
        self::version($assignment, $input);
        if (!in_array($assignment['status'], ['DRAFT', 'PUBLISHED'], true)) {
            throw new EducationError(409, 'assignment_not_editable');
        }
        $students = $this->students($scope, $input['studentIds'] ?? null);
        if ($students === []) {
            throw new EducationError(422, 'recipients_required');
        }
        $this->store->addRecipients($scope, $id, $students, $now);
        $this->store->bumpAssignment($scope, $id);
        $after = $this->store->assignment($scope, $id);
        $this->store->revision($scope, $after, 'recipients_added', $now);
        if ($after['status'] === 'PUBLISHED') {
            $this->extensions->changed($scope, 'recipient_added', $after);
        }
        return $this->teacherDetail($scope, $id, $now);
    }

    private function saveDraft(Scope $scope, string $id, array $input, string $now): array
    {
        self::only($input, ['version', 'text']);
        $assignment = $this->store->assignment($scope, $id, true);
        $recipient = $this->store->studentRecipient($scope, $id, true);
        $submission = $this->store->submission($scope, $recipient, true);
        self::version($submission, $input);
        self::editable($assignment, $submission);
        $this->store->saveDraft($scope, $recipient, $submission, Input::text($input['text'] ?? '', 20000, false), $now);
        return $this->studentDetail($scope, $id, $now);
    }

    private function submit(Scope $scope, string $id, array $input, string $now): array
    {
        self::only($input, ['version', 'idempotencyKey']);
        $assignment = $this->store->assignment($scope, $id, true);
        $recipient = $this->store->studentRecipient($scope, $id, true);
        $submission = $this->store->submission($scope, $recipient, true);
        self::version($submission, $input);
        self::editable($assignment, $submission);
        $late = $assignment['dueAt'] !== null && new DateTimeImmutable($now) > new DateTimeImmutable($assignment['dueAt']);
        if ($late && !$assignment['allowLate']) {
            throw new EducationError(409, 'deadline_passed');
        }
        $attempt = $this->extensions->validateSubmission($scope, $assignment, $this->extensions->submissionView($scope, $assignment, $submission));
        if ($submission['id'] === null) {
            $this->store->saveDraft($scope, $recipient, $submission, '', $now);
            $submission = $this->store->submission($scope, $recipient, true);
        }
        $this->store->submit($scope, $assignment, $submission, $attempt, $input['idempotencyKey'], $now, $late);
        $persisted = $this->store->submission($scope, $recipient);
        $this->extensions->changed($scope, 'submitted', $assignment, $persisted);
        return $this->studentDetail($scope, $id, $now);
    }

    private function review(Scope $scope, string $id, array $input, string $now): array
    {
        self::only($input, ['version', 'idempotencyKey', 'decision', 'resultStatus', 'comment']);
        $recipient = $this->store->submissionRecipient($scope, $id);
        $assignment = $this->store->teacherAssignment($scope, $recipient['assignmentId'], true);
        $this->access->assertResource($scope, 'student', $recipient['studentId']);
        if (!$this->access->member($recipient['personId'], $scope->organizationId)) {
            throw new EducationError(404, 'not_found');
        }
        $this->store->recipient($scope, $recipient['id'], true);
        $submission = $this->store->submission($scope, $recipient, true);
        self::version($submission, $input);
        if (!in_array($submission['state'], ['SUBMITTED', 'RESUBMITTED'], true)) {
            throw new EducationError(409, 'submission_not_reviewable');
        }
        $decision = $input['decision'] ?? null;
        $result = $input['resultStatus'] ?? null;
        if (!in_array($decision, ['accepted', 'returned'], true) || ($result !== null && !in_array($result, ['mastered', 'developing', 'needs_support'], true))
            || ($decision === 'returned' && $result !== null)) {
            throw new EducationError(422, 'invalid_review');
        }
        $values = ['decision' => $decision, 'resultStatus' => $result, 'comment' => Input::text($input['comment'] ?? '', 5000, false), 'idempotencyKey' => $input['idempotencyKey']];
        if ($decision === 'returned' && $values['comment'] === '') {
            throw new EducationError(422, 'return_comment_required');
        }
        $this->store->review($scope, $submission, $values, $now);
        $this->extensions->changed($scope, $decision === 'returned' ? 'returned' : 'reviewed', $assignment, $this->store->submission($scope, $recipient));
        return $this->teacherSubmission($scope, $id, $now);
    }

    private const FIELDS = ['title', 'description', 'instructions', 'type', 'groupId', 'lessonId', 'dueAt', 'allowLate', 'activityCode', 'activityConfig', 'maxAttempts', 'attemptPolicy'];

    private function values(Scope $scope, array $input): array
    {
        $type = $input['type'] ?? 'text';
        if (!in_array($type, ['text', 'files', 'typing', 'activity'], true)) {
            throw new EducationError(422, 'invalid_homework_type');
        }
        $group = isset($input['groupId']) && $input['groupId'] !== '' ? Input::uuid($input['groupId']) : null;
        $lesson = isset($input['lessonId']) && $input['lessonId'] !== '' ? Input::uuid($input['lessonId']) : null;
        if ($group !== null) {
            $this->access->assertResource($scope, 'group', $group);
            $actual = $this->education->group($scope, $group) ?? throw new EducationError(404, 'not_found');
            if ($actual['archivedAt'] !== null) {
                throw new EducationError(409, 'group_archived');
            }
        }
        if ($lesson !== null) {
            $this->access->assertResource($scope, 'lesson', $lesson);
            $actual = $this->education->lesson($scope, $lesson) ?? throw new EducationError(404, 'not_found');
            if ($group === null || $actual['groupId'] !== $group) {
                throw new EducationError(422, 'lesson_group_mismatch');
            }
        }
        $allowLate = $input['allowLate'] ?? false;
        $max = $input['maxAttempts'] ?? 3;
        $policy = $input['attemptPolicy'] ?? 'latest';
        if (!is_bool($allowLate) || !is_int($max) || $max < 1 || $max > 20 || !in_array($policy, ['first', 'latest', 'best'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        $activityCode = null;
        $config = [];
        if (in_array($type, ['typing', 'activity'], true)) {
            $activityCode = $type === 'typing' ? 'platform-typing' : ActivityCode::canonical(Input::text($input['activityCode'] ?? null, 60));
            $config = $input['activityConfig'] ?? ($activityCode === 'platform-logic'
                ? ['language' => 'ru', 'difficulty' => 1, 'roundCount' => 5]
                : ['durationSeconds' => 30, 'language' => 'ru']);
            if (!is_array($config) || array_is_list($config)) {
                throw new EducationError(422, 'invalid_activity_config');
            }
            $this->activityHandlers->validateConfig($activityCode, $config);
        }
        return ['title' => Input::text($input['title'] ?? null, 160), 'description' => Input::text($input['description'] ?? '', 8000, false),
            'instructions' => Input::text($input['instructions'] ?? '', 20000, false), 'type' => $type, 'groupId' => $group, 'lessonId' => $lesson,
            'dueAt' => isset($input['dueAt']) && $input['dueAt'] !== '' ? Input::instant($input['dueAt']) : null, 'allowLate' => $allowLate,
            'activityCode' => $activityCode, 'activityConfig' => $config, 'maxAttempts' => $max, 'attemptPolicy' => $policy];
    }

    private function students(Scope $scope, mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 1000) {
            throw new EducationError(422, 'invalid_recipients');
        }
        $students = [];
        foreach (array_unique($ids) as $id) {
            $id = Input::uuid($id);
            $student = $this->education->student($scope, $id) ?? throw new EducationError(404, 'not_found');
            $this->access->assertResource($scope, 'student', $id);
            if (!$this->access->member($student['personId'], $scope->organizationId)) {
                throw new EducationError(404, 'not_found');
            }
            $students[] = ['id' => $id, 'personId' => $student['personId']];
        }
        return $students;
    }

    private static function editable(array $assignment, array $submission): void
    {
        if ($assignment['status'] !== 'PUBLISHED') {
            throw new EducationError(409, 'assignment_closed');
        }
        if (!in_array($submission['state'], ['NOT_STARTED', 'IN_PROGRESS', 'RETURNED'], true)) {
            throw new EducationError(409, 'submission_not_editable');
        }
    }

    private static function version(array $record, array $input): void
    {
        if (!isset($input['version']) || !is_int($input['version']) || $input['version'] < 0) {
            throw new EducationError(422, 'version_required');
        }
        if ($record['version'] !== $input['version']) {
            throw new EducationError(409, 'version_conflict');
        }
    }

    private static function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new EducationError(422, 'unknown_field');
        }
    }

    private static function filters(array $query): array
    {
        $result = [];
        foreach (['studentId','groupId'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $result[$key] = Input::uuid($query[$key]);
            }
        }
        foreach (['search','status','state','sort'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $result[$key] = Input::text($query[$key], $key === 'search' ? 160 : 30);
            }
        }
        if (isset($result['status']) && !in_array($result['status'], ['DRAFT','PUBLISHED','CLOSED','ARCHIVED'], true)) {
            throw new EducationError(422, 'invalid_filter');
        }
        if (isset($result['state']) && !in_array($result['state'], ['NOT_STARTED','IN_PROGRESS','SUBMITTED','RESUBMITTED','RETURNED','REVIEWED'], true)) {
            throw new EducationError(422, 'invalid_filter');
        }
        if (isset($result['sort']) && !in_array($result['sort'], ['dueAt','assignedAt','createdAt','deadline','newest','oldest','reviewedAt'], true)) {
            throw new EducationError(422, 'invalid_sort');
        }
        if (isset($query['overdue'])) {
            if (!in_array($query['overdue'], [true,false,'true','false','1','0'], true)) {
                throw new EducationError(422, 'invalid_filter');
            }
            $result['overdue'] = in_array($query['overdue'], [true,'true','1'], true);
        }
        return $result;
    }

    private static function page(array $items, int $offset): array
    {
        return ['items' => array_slice($items, 0, 50), 'limit' => 50, 'offset' => $offset, 'nextOffset' => count($items) > 50 ? $offset + 50 : null];
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
