<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Time\Application\ClockInterface;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Auth\Application\AuthenticatedIdentity;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Http\Message\UploadedFileInterface;

final readonly class EducationService
{
    public function __construct(
        private EducationStore $store,
        private EducationAccess $access,
        private ClockInterface $clock,
        private FileStore $files,
        private Mutation $mutations,
        private PermissionService $permissions,
        private OrganizationRepository $organizations,
        private ?CurriculumStore $curriculum = null,
        private ?ActivityResultSink $activityResults = null,
        private ?HomeworkService $homework = null,
        private ?StudentFamily $families = null,
        private ?ActivityHandlerRegistry $activityHandlers = null,
        private ?AchievementService $achievements = null,
        private ?ActivityResultView $activityView = null,
    ) {}

    /**
     * @param array<array-key,mixed> $query
     * @return array<string,mixed>|null
     */
    public function read(string $operation, Scope $scope, array $query, ?string $id = null): ?array
    {
        if (str_starts_with($operation, 'student_') && !in_array($operation, ['student_attendance','student_results','student_progress'], true)) {
            $this->assertActivityStudent($scope);
        }
        $offset = Input::page($query['offset'] ?? null);
        $now = $this->clock->now()->setTimezone(new DateTimeZone($scope->timezone));
        return match ($operation) {
            'header', 'profile' => $this->header($scope, $now),
            'groups' => $this->resourcePage($scope, $this->store->groups($scope, $offset), $offset, 'group'),
            'group' => $this->group($scope, Input::uuid($id)),
            'group_students' => $this->groupStudents($scope, Input::uuid($id), $offset),
            'students' => $this->peoplePage($scope, $this->store->students($scope, $offset), $offset),
            'candidates' => self::page(array_slice($this->access->candidates($scope), $offset, 51), $offset),
            'colleagues' => self::page(array_slice($this->colleagues($scope), $offset, 51), $offset),
            'schedule' => $this->schedule($scope, $query),
            'timeline' => $this->timeline($scope, Input::date($query['date'] ?? null)),
            'next' => $this->nextLesson($scope, $now, false),
            'current' => $this->nextLesson($scope, $now, true),
            'lesson' => $this->lesson($scope, Input::uuid($id)),
            'participants' => $this->participants($scope, Input::uuid($id)),
            'lesson_results' => $this->lessonResults($scope, Input::uuid($id)),
            'materials' => $this->materials($scope, Input::uuid($id), $offset),
            'files' => $this->resourcePage($scope, $this->store->files($scope, null, $offset), $offset, 'file'),
            'attention' => $this->attention($scope, $now),
            'threads' => $this->resourcePage($scope, $this->store->threads($scope, $offset), $offset, 'thread'),
            'thread' => $this->thread($scope, Input::uuid($id)),
            'messages' => $this->messages($scope, Input::uuid($id), $offset),
            'notifications', 'student_notifications' => $this->notifications($scope, $offset),
            'group_journal' => $this->journal($scope, Input::uuid($id), $query),
            'student' => $this->student($scope, Input::uuid($id)),
            'student_attendance' => $this->studentAttendance($scope, Input::uuid($id), $query, $offset),
            'student_results' => $this->studentResults($scope, Input::uuid($id), $offset),
            'student_progress' => $this->studentProgress($scope, Input::uuid($id), $query),
            'activity_assignments' => $this->assignments($scope, $offset),
            'group_activity_results' => $this->groupActivityResults($scope, Input::uuid($id), $query, $offset),
            'student_home' => $this->studentHome($scope),
            'student_schedule' => $this->studentSchedule($scope, $query),
            'student_lessons' => $this->studentLessons($scope, $offset),
            'student_lesson' => $this->studentLesson($scope, Input::uuid($id)),
            'activity_catalog', 'student_activity_catalog' => ['items' => array_map(
                static fn(array $row): array => self::jsonFields($row, ['config', 'metrics']),
                $this->store->activityCatalog(),
            )],
            'student_activity_config' => $this->studentActivityConfig($scope, (string) $id),
            'student_attempt' => $this->attemptView($scope, Input::uuid($id)),
            'student_achievements' => ['items' => $this->achievements?->readForStudent($scope->organizationId, (string) $scope->studentId, fn(string $type, string $resource): bool => $this->access->resourceAllowed($scope->personId, $type, $resource)) ?? []],
            'student_activity_progress' => $this->studentActivityProgress($scope, $query),
            'group_activity_progress' => $this->groupActivityProgress($scope, Input::uuid($id), $query),
            'student_attempt_history' => $this->attemptHistory($scope, $offset),
            'student_typing_history' => $this->typingHistory($scope, $offset),
            default => throw new EducationError(404, 'not_found'),
        };
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    public function write(string $operation, Scope $scope, array $input, ?string $id = null, ?string $studentId = null): array
    {
        if (str_starts_with($operation, 'student_')) {
            $this->assertActivityStudent($scope);
        }
        if ($operation === 'delete_file') {
            self::only($input, []);
            return $this->deleteFile($scope, Input::uuid($id));
        }
        if ($this->curriculum !== null) {
            $originalScope = $scope;
            $scope = match ($operation) {
                'create_lesson', 'enroll_student' => $this->curriculum->delegatedScope($scope, 'group', Input::uuid($input['groupId'] ?? null)),
                'update_group', 'archive_group', 'remove_group_student', 'transfer_student' => $this->curriculum->delegatedScope($scope, 'group', Input::uuid($id)),
                'update_lesson', 'complete_lesson', 'cancel_lesson', 'mark_attendance', 'save_lesson_report', 'save_lesson_result' => $this->curriculum->delegatedScope($scope, 'lesson', Input::uuid($id)),
                'invite_student', 'revoke_student_account' => $this->curriculum->delegatedScope($scope, 'student', Input::uuid($id)),
                'activities_assign' => isset($input['groupId'])
                    ? $this->curriculum->delegatedScope($scope, 'group', Input::uuid($input['groupId']))
                    : (isset($input['studentId']) ? $this->curriculum->delegatedScope($scope, 'student', Input::uuid($input['studentId'])) : $scope),
                default => $scope,
            };
            if ($operation === 'update_lesson' || $operation === 'transfer_student') {
                $target = $this->curriculum->delegatedScope($originalScope, 'group', Input::uuid($input['groupId'] ?? null));
                if ($target->accountId !== $scope->accountId) {
                    throw new EducationError(409, 'group_owner_mismatch');
                }
            }
            if ($operation === 'activities_assign' && isset($input['groupId'], $input['studentId'])) {
                $studentScope = $this->curriculum->delegatedScope($originalScope, 'student', Input::uuid($input['studentId']));
                if ($studentScope->accountId !== $scope->accountId) {
                    throw new EducationError(409, 'group_owner_mismatch');
                }
            }
        }
        return $this->mutations->run($scope, $operation, fn(): array => match ($operation) {
            'create_group' => $this->createGroup($scope, $input),
            'update_group' => $this->updateGroup($scope, Input::uuid($id), $input),
            'archive_group' => $this->archiveGroup($scope, Input::uuid($id)),
            'enroll_student' => $this->enrollStudent($scope, $input),
            'remove_group_student' => $this->removeGroupStudent($scope, Input::uuid($id), Input::uuid($studentId)),
            'transfer_student' => $this->transferStudent($scope, Input::uuid($id), Input::uuid($studentId), $input),
            'create_lesson' => $this->createLesson($scope, $input),
            'update_lesson' => $this->updateLesson($scope, Input::uuid($id), $input),
            'complete_lesson' => $this->completeLesson($scope, Input::uuid($id)),
            'cancel_lesson' => $this->cancelLesson($scope, Input::uuid($id)),
            'save_lesson_report' => $this->saveLessonReport($scope, Input::uuid($id), $input),
            'save_lesson_result' => $this->saveLessonResult($scope, Input::uuid($id), Input::uuid($studentId), $input),
            'invite_student' => $this->inviteStudent($scope, Input::uuid($id)),
            'revoke_student_account' => $this->revokeStudentAccount($scope, Input::uuid($id)),
            'activities_assign' => $this->assignActivity($scope, (string) $id, $input),
            'student_start_attempt' => $this->startAttempt($scope, (string) $id, $input),
            'student_finish_attempt' => $this->finishAttempt($scope, Input::uuid($id), $input),
            'student_save_attempt_state' => $this->saveAttemptState($scope, Input::uuid($id), $input),
            'revoke_activity_attempt' => $this->revokeActivityAttempt($scope, Input::uuid($id), $input),
            'create_event' => $this->createEvent($scope, $input),
            'mark_attendance' => $this->attendance($scope, Input::uuid($id), Input::uuid($studentId), $input),
            'save_profile' => $this->profile($scope, $input),
            'start_thread' => $this->startThread($scope, $input),
            'send_message' => $this->sendMessage($scope, Input::uuid($id), $input),
            'read_thread' => $this->readThread($scope, Input::uuid($id), $input),
            'read_notification' => $this->readNotification($scope, Input::uuid($id)),
            default => throw new EducationError(404, 'not_found'),
        });
    }

    /** @return array<string,mixed> */
    private function header(Scope $scope, DateTimeImmutable $now): array
    {
        $profile = $this->store->profile($scope);
        if ($profile !== null) {
            $this->access->assertResource($scope, 'profile', (string) $profile['id']);
        }
        $avatarId = isset($profile['avatarFileId']) && is_string($profile['avatarFileId']) ? $profile['avatarFileId'] : null;
        if ($avatarId !== null && $this->store->file($scope, $avatarId) === null) {
            $avatarId = null;
        }
        $hour = (int) $now->format('G');
        return ['displayName' => $profile['displayName'] ?? $this->access->personName($scope->personId),
            'avatarFileId' => $avatarId, 'personId' => $scope->personId,
            'date' => $now->format('Y-m-d'), 'now' => $now->format(DATE_ATOM),
            'greeting' => $hour < 6 ? 'night' : ($hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening')),
            'locale' => $scope->locale, 'timezone' => $scope->timezone, 'unreadCount' => $this->store->unreadCount($scope)];
    }

    /**
     * @param array<array-key,mixed> $query
     * @return array<string,mixed>
     */
    private function schedule(Scope $scope, array $query): array
    {
        $from = Input::date($query['from'] ?? null);
        $to = Input::date($query['to'] ?? null);
        [$start, $end] = Input::range($from, $to, $scope->timezone);
        $lessons = $this->store->lessons($scope, $start, $end);
        $events = $this->store->events($scope, $start, $end);
        if (count($lessons) > 500 || count($events) > 500) {
            throw new EducationError(422, 'range_too_large');
        }
        $lessons = array_values(array_filter($lessons, fn(array $row): bool => $this->access->resourceAllowed($scope->personId, 'lesson', (string) $row['id'])
            && $this->access->resourceAllowed($scope->personId, 'group', (string) $row['groupId'])));
        $events = array_values(array_filter($events, fn(array $row): bool => $this->access->resourceAllowed($scope->personId, 'event', (string) $row['id'])));
        return ['from' => $from, 'to' => $to, 'timezone' => $scope->timezone, 'lessons' => $lessons, 'events' => $events];
    }

    /** @return array<string,mixed> */
    private function timeline(Scope $scope, string $date): array
    {
        $schedule = $this->schedule($scope, ['from' => $date, 'to' => $date]);
        /** @var list<array<string,mixed>> $lessons */
        $lessons = $schedule['lessons'];
        /** @var list<array<string,mixed>> $events */
        $events = $schedule['events'];
        $items = [...array_map(static fn(array $lesson): array => [...$lesson, 'kind' => 'lesson'], $lessons), ...$events];
        usort($items, static fn(array $a, array $b): int =>
            (new DateTimeImmutable((string) $a['startsAt'])) <=> (new DateTimeImmutable((string) $b['startsAt']))
            ?: strcmp((string) $a['id'], (string) $b['id']));
        return ['date' => $date, 'timezone' => $scope->timezone, 'items' => $items];
    }

    /** @return array<string,mixed> */
    private function lesson(Scope $scope, string $id): array
    {
        $this->access->assertResource($scope, 'lesson', $id);
        $lesson = $this->store->lesson($scope, $id) ?? throw new EducationError(404, 'not_found');
        $this->access->assertResource($scope, 'group', (string) $lesson['groupId']);
        return $lesson;
    }

    /** @return array<string,mixed> */
    private function group(Scope $scope, string $id): array
    {
        $this->access->assertResource($scope, 'group', $id);
        return $this->store->group($scope, $id) ?? throw new EducationError(404, 'not_found');
    }

    /** @return array<string,mixed> */
    private function groupStudents(Scope $scope, string $id, int $offset): array
    {
        $this->group($scope, $id);
        return $this->peoplePage($scope, $this->store->groupStudents($scope, $id, $offset), $offset);
    }

    /** @param array<string,mixed> $group */
    private static function assertGroupMutable(array $group): void
    {
        if (($group['archivedAt'] ?? null) !== null) {
            throw new EducationError(409, 'group_archived');
        }
    }

    /** @return array<string,mixed>|null */
    private function nextLesson(Scope $scope, DateTimeImmutable $now, bool $current): ?array
    {
        $lesson = $this->store->nextLesson($scope, $now->format(DATE_ATOM), $current);
        return $lesson === null ? null : $this->lesson($scope, (string) $lesson['id']);
    }

    /** @return array<string,mixed> */
    private function participants(Scope $scope, string $id): array
    {
        $this->lesson($scope, $id);
        $items = $this->store->participants($scope, $id);
        if (count($items) > 500) {
            throw new EducationError(422, 'group_too_large');
        }
        return ['items' => $this->namedPeople($scope, $items)];
    }

    /** @return array<string,mixed> */
    private function lessonResults(Scope $scope, string $id): array
    {
        $this->lesson($scope, $id);
        $items = $this->store->lessonResults($scope, $id);
        if (count($items) > 500) {
            throw new EducationError(422, 'group_too_large');
        }
        return ['report' => $this->store->lessonReport($scope, $id), 'items' => $this->namedPeople($scope, $items)];
    }

    /**
     * @param array<array-key,mixed> $query
     * @return array<string,mixed>
     */
    private function journal(Scope $scope, string $id, array $query): array
    {
        $this->group($scope, $id);
        $offset = Input::page($query['offset'] ?? null);
        $studentId = isset($query['studentId']) && $query['studentId'] !== '' ? Input::uuid($query['studentId']) : null;
        if ($studentId !== null && $this->store->student($scope, $studentId) === null) {
            throw new EducationError(404, 'not_found');
        }
        $from = $query['from'] ?? null;
        $to = $query['to'] ?? null;
        if (($from === null) !== ($to === null)) {
            throw new EducationError(422, 'invalid_range');
        }
        [$start, $end] = $from === null
            ? ['1970-01-01T00:00:00+00:00', '3000-01-01T00:00:00+00:00']
            : Input::range((string) $from, (string) $to, $scope->timezone);
        $items = $this->store->journal($scope, $id, $start, $end, $studentId, $offset);
        $page = self::page($items, $offset);
        $items = array_values(array_filter(array_slice($items, 0, 50), fn(array $row): bool =>
            $this->access->resourceAllowed($scope->personId, 'lesson', (string) $row['id'])));
        $page['items'] = $items;
        if ($items === []) {
            return $page;
        }
        $lessonIds = array_map(static fn(array $row): string => (string) $row['id'], $items);
        $reports = [];
        foreach ($this->store->journalReports($scope, $lessonIds) as $report) {
            $reports[(string) $report['lessonId']] = $report;
        }
        $results = [];
        foreach ($this->store->journalResults($scope, $lessonIds, $studentId) as $row) {
            $results[(string) $row['lessonId']][] = $row;
        }
        $page['items'] = array_map(function (array $row) use ($scope, $reports, $results): array {
            $id = (string) $row['id'];
            $entries = [];
            foreach ($results[$id] ?? [] as $entry) {
                $personId = (string) $entry['personId'];
                if (!$this->access->member($personId, $scope->organizationId)
                    || !$this->access->resourceAllowed($personId, 'student', (string) $entry['studentId'])) {
                    continue;
                }
                $name = $this->access->personName($personId);
                if ($name === null) {
                    continue;
                }
                $entries[] = ['studentId' => (string) $entry['studentId'], 'name' => $name,
                    'status' => (string) $entry['status'], 'comment' => $entry['comment'] ?? null];
            }
            return [...$row, 'report' => $reports[$id] ?? null, 'results' => $entries];
        }, $items);
        return $page;
    }

    /** @return array<string,mixed> */
    private function student(Scope $scope, string $id): array
    {
        $this->access->assertResource($scope, 'student', $id);
        $student = $this->store->student($scope, $id) ?? throw new EducationError(404, 'not_found');
        $personId = (string) $student['personId'];
        if (!$this->access->member($personId, $scope->organizationId)) {
            throw new EducationError(404, 'not_found');
        }
        $name = $this->access->personName($personId);
        if ($name === null) {
            throw new EducationError(404, 'not_found');
        }
        return [...$student, 'name' => $name, 'groups' => $this->store->studentGroups($scope, $id),
            'account' => $this->store->studentAccount($scope, $id), 'guardians' => $this->families?->guardians($scope, $id) ?? []];
    }

    /**
     * @param array<array-key,mixed> $query
     * @return array<string,mixed>
     */
    private function studentAttendance(Scope $scope, string $id, array $query, int $offset): array
    {
        $this->student($scope, $id);
        [$from, $to] = Input::range((string) ($query['from'] ?? null), (string) ($query['to'] ?? null), $scope->timezone);
        $items = $this->store->studentAttendance($scope, $id, $from, $to, $offset);
        $page = self::page($items, $offset);
        $page['items'] = array_values(array_filter(array_slice($items, 0, 50), fn(array $row): bool =>
            $this->access->resourceAllowed($scope->personId, 'lesson', (string) $row['lessonId'])));
        $page['from'] = (string) $query['from'];
        $page['to'] = (string) $query['to'];
        return $page;
    }

    /** @return array<string,mixed> */
    private function studentResults(Scope $scope, string $id, int $offset): array
    {
        $this->student($scope, $id);
        $items = $this->store->studentResults($scope, $id, $offset);
        $page = self::page($items, $offset);
        $page['items'] = array_values(array_filter(array_slice($items, 0, 50), fn(array $row): bool =>
            $this->access->resourceAllowed($scope->personId, 'lesson', (string) $row['lessonId'])));
        return $page;
    }

    /**
     * @param array<array-key,mixed> $query
     * @return array<string,mixed>
     */
    private function studentProgress(Scope $scope, string $id, array $query): array
    {
        $this->student($scope, $id);
        [$from, $to] = Input::range((string) ($query['from'] ?? null), (string) ($query['to'] ?? null), $scope->timezone);
        $progress = $this->store->studentProgress($scope, $id, $from, $to);
        $progress['from'] = (string) $query['from'];
        $progress['to'] = (string) $query['to'];
        if ($this->homework !== null) {
            $progress['homework'] = $this->homework->read('teacher_summary', $scope, ['studentId' => $id]);
        }
        return $progress;
    }

    /** @return array<string,mixed> */
    private function materials(Scope $scope, string $id, int $offset): array
    {
        $this->lesson($scope, $id);
        return $this->resourcePage($scope, $this->store->files($scope, $id, $offset), $offset, 'file');
    }

    /** @return array<string,mixed> */
    private function attention(Scope $scope, DateTimeImmutable $now): array
    {
        $date = $now->modify('-1 day')->format('Y-m-d');
        [$from, $to] = Input::range($date, $date, $scope->timezone);
        $items = $this->store->attention($scope, $from, $to);
        if (count($items) > 500) {
            throw new EducationError(422, 'range_too_large');
        }
        return ['date' => $date, 'items' => array_values(array_filter($items, fn(array $row): bool =>
            $this->access->resourceAllowed($scope->personId, 'lesson', (string) $row['id'])))];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function namedPeople(Scope $scope, array $items): array
    {
        $visible = [];
        foreach ($items as $item) {
            $personId = (string) $item['personId'];
            if (!$this->access->member($personId, $scope->organizationId)) {
                continue;
            }
            if (isset($item['id']) && !$this->access->resourceAllowed($personId, 'student', (string) $item['id'])) {
                continue;
            }
            $name = $this->access->personName($personId);
            if ($name !== null) {
                $visible[] = [...$item, 'name' => $name];
            }
        }
        return $visible;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function peoplePage(Scope $scope, array $items, int $offset): array
    {
        $page = self::page($items, $offset);
        $page['items'] = $this->namedPeople($scope, array_slice($items, 0, 50));
        return $page;
    }

    /** @return list<array<string,mixed>> */
    private function colleagues(Scope $scope): array
    {
        $items = $this->store->colleagues($scope);
        if (count($items) > 500) {
            throw new EducationError(422, 'directory_too_large');
        }
        return array_map(static fn(array $row): array => [...$row, 'id' => $row['personId']], $this->namedPeople($scope, $items));
    }

    /** @return array<string,mixed> */
    private function messages(Scope $scope, string $id, int $offset): array
    {
        $this->thread($scope, $id);
        $items = $this->store->messages($scope, $id, $offset);
        foreach ($items as &$message) {
            $sender = (string) $message['senderPersonId'];
            $message['senderName'] = $this->access->member($sender, $scope->organizationId) ? $this->access->personName($sender) : null;
            if ($message['senderName'] === null || !$this->access->resourceAllowed($sender, 'message', (string) $message['id'])) {
                $message['body'] = null;
            }
        }
        unset($message);
        $page = self::page($items, $offset);
        $page['items'] = array_reverse(array_slice($items, 0, 50));
        return $page;
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function createGroup(Scope $scope, array $input): array
    {
        self::only($input, ['name']);
        return $this->store->createGroup($scope, Input::text($input['name'] ?? null, 160));
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function updateGroup(Scope $scope, string $id, array $input): array
    {
        self::only($input, ['name']);
        self::assertGroupMutable($this->group($scope, $id));
        return $this->store->updateGroup($scope, $id, Input::text($input['name'] ?? null, 160));
    }

    /** @return array<string,mixed> */
    private function archiveGroup(Scope $scope, string $id): array
    {
        $this->group($scope, $id);
        return $this->store->archiveGroup($scope, $id);
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function enrollStudent(Scope $scope, array $input): array
    {
        self::only($input, ['personId', 'groupId']);
        $person = Input::uuid($input['personId'] ?? null);
        $group = Input::uuid($input['groupId'] ?? null);
        self::assertGroupMutable($this->group($scope, $group));
        if (!$this->access->member($person, $scope->organizationId)) {
            throw new EducationError(403, 'person_outside_organization');
        }
        return $this->store->enrollStudent($scope, $person, $group);
    }

    /** @return array<string,mixed> */
    private function removeGroupStudent(Scope $scope, string $groupId, string $studentId): array
    {
        self::assertGroupMutable($this->group($scope, $groupId));
        $this->store->removeGroupStudent($scope, $groupId, $studentId);
        return ['updated' => true];
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function transferStudent(Scope $scope, string $groupId, string $studentId, array $input): array
    {
        self::only($input, ['groupId']);
        $target = Input::uuid($input['groupId'] ?? null);
        if ($target === $groupId) {
            throw new EducationError(422, 'same_group');
        }
        self::assertGroupMutable($this->group($scope, $groupId));
        self::assertGroupMutable($this->group($scope, $target));
        $this->store->transferStudent($scope, $groupId, $target, $studentId);
        return ['updated' => true];
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function createLesson(Scope $scope, array $input): array
    {
        self::only($input, ['groupId', 'title', 'startsAt', 'endsAt', 'location', 'curriculumLessonId']);
        $values = self::eventValues($input);
        $values['groupId'] = Input::uuid($input['groupId'] ?? null);
        self::assertGroupMutable($this->group($scope, $values['groupId']));
        $lesson = $this->store->createLesson($scope, $values);
        if (isset($input['curriculumLessonId'])) {
            ($this->curriculum ?? throw new EducationError(503, 'curriculum_unavailable'))
                ->bindLesson($scope, $lesson['id'], Input::uuid($input['curriculumLessonId']));
            return $this->store->lesson($scope, $lesson['id']) ?? throw new EducationError(409, 'write_not_confirmed');
        }
        return $lesson;
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function updateLesson(Scope $scope, string $id, array $input): array
    {
        self::only($input, ['groupId', 'title', 'startsAt', 'endsAt', 'location', 'curriculumLessonId']);
        $this->lesson($scope, $id);
        $values = self::eventValues($input);
        $values['groupId'] = Input::uuid($input['groupId'] ?? null);
        $before = $this->lesson($scope, $id);
        $lesson = $this->store->updateLesson($scope, $id, $values);
        if (array_key_exists('curriculumLessonId', $input) || ($before['groupId'] !== $values['groupId'] && isset($before['curriculumLessonId']))) {
            ($this->curriculum ?? throw new EducationError(503, 'curriculum_unavailable'))
                ->bindLesson($scope, $id, isset($input['curriculumLessonId']) ? Input::uuid($input['curriculumLessonId']) : null);
            return $this->store->lesson($scope, $id) ?? throw new EducationError(409, 'write_not_confirmed');
        }
        return $lesson;
    }

    /** @return array<string,mixed> */
    private function completeLesson(Scope $scope, string $id): array
    {
        $this->lesson($scope, $id);
        return $this->store->completeLesson($scope, $id, $this->clock->now()->format(DATE_ATOM));
    }

    /** @return array<string,mixed> */
    private function cancelLesson(Scope $scope, string $id): array
    {
        $this->lesson($scope, $id);
        return $this->store->cancelLesson($scope, $id);
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function saveLessonReport(Scope $scope, string $id, array $input): array
    {
        self::only($input, ['topic', 'summary', 'teacherComment']);
        $this->lesson($scope, $id);
        return $this->store->saveLessonReport($scope, ['lessonId' => $id,
            'topic' => Input::text($input['topic'] ?? null, 240),
            'summary' => Input::text($input['summary'] ?? null, 4000),
            'teacherComment' => self::optionalText($input['teacherComment'] ?? null, 4000)]);
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function saveLessonResult(Scope $scope, string $id, string $studentId, array $input): array
    {
        self::only($input, ['status', 'comment']);
        $this->lesson($scope, $id);
        $status = Input::text($input['status'] ?? null, 20);
        if (!in_array($status, ['pending', 'mastered', 'developing', 'needs_support'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        return $this->store->saveLessonResult($scope, $id, $studentId, $status, self::optionalText($input['comment'] ?? null, 1000));
    }

    private static function optionalText(mixed $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = Input::text($value, $maximum, false);
        return $text === '' ? null : $text;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $fields
     * @return array<string,mixed>
     */
    private static function jsonFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (is_string($row[$field] ?? null)) {
                $row[$field] = json_decode((string) $row[$field], true) ?? $row[$field];
            }
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function studentHome(Scope $scope): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone($scope->timezone));
        $schedule = $this->store->studentSchedule(
            $scope,
            $now->format(DateTimeInterface::ATOM),
            $now->modify('+31 days')->format(DateTimeInterface::ATOM),
        );
        $next = array_values(array_filter($schedule, static fn(array $row): bool => $row['status'] !== 'cancelled'))[0] ?? null;
        $lessons = $this->store->studentLessons($scope, 0);
        $results = array_values(array_filter(array_slice($lessons, 0, 10), static fn(array $row): bool => $row['resultStatus'] !== 'pending')) ;
        $attempts = array_slice($this->store->studentAttempts($scope, 0), 0, 5);
        return [
            'nextLesson' => $next,
            'groups' => $this->store->studentGroupList($scope),
            'recentResults' => array_slice($results, 0, 3),
            'activities' => $this->store->activityCatalog(),
            'pendingAttempts' => array_values(array_filter($attempts, static fn(array $row): bool => $row['status'] === 'running')),
            'bestTyping' => $this->store->bestTyping($scope),
            'homework' => $this->homework?->read('student_summary', $scope),
        ];
    }

    /**
     * @param array<array-key,mixed> $query
     * @return array<string,mixed>
     */
    private function studentSchedule(Scope $scope, array $query): array
    {
        [$from, $to] = Input::range((string) ($query['from'] ?? null), (string) ($query['to'] ?? null), $scope->timezone);
        return ['from' => (string) $query['from'], 'to' => (string) $query['to'],
            'items' => array_slice($this->store->studentSchedule($scope, $from, $to), 0, 100)];
    }

    /** @return array<string,mixed> */
    private function studentLessons(Scope $scope, int $offset): array
    {
        $items = $this->store->studentLessons($scope, $offset);
        $page = self::page($items, $offset);
        $page['items'] = array_slice($items, 0, 50);
        return $page;
    }

    /** @return array<string,mixed> */
    private function studentLesson(Scope $scope, string $id): array
    {
        $lesson = $this->store->studentLesson($scope, $id) ?? throw new EducationError(404, 'not_found');
        if (!$this->access->resourceAllowed($scope->personId, 'lesson', $id)) {
            throw new EducationError(403, 'processing_restricted');
        }
        $materials = array_values(array_filter(
            $this->store->studentMaterials($scope, $id),
            fn(array $material): bool => $this->access->resourceAllowed($scope->personId, 'file', $material['id']),
        ));
        return [...$lesson, 'materials' => $materials];
    }

    /** @return array<string,mixed> */
    private function studentActivityConfig(Scope $scope, string $code): array
    {
        $definition = $this->store->activityDefinition(Input::text($code, 60));
        if ($definition === null || !in_array($definition['enabled'], [true, 't', 1], true)) {
            throw new EducationError(404, 'not_found');
        }
        if (!$this->permissionsAllowed($scope, (string) $definition['requiredPermission'])) {
            throw new EducationError(403, 'access_denied');
        }
        $assignments = array_values(array_filter(
            $this->store->studentAssignments($scope),
            static fn(array $row): bool => $row['activityId'] === $definition['id'],
        ));
        $assignments = array_map(static fn(array $row): array => self::jsonFields($row, ['config', 'completion']), $assignments);
        return [...self::jsonFields($definition, ['config', 'metrics']), 'assignments' => $assignments];
    }

    private function permissionsAllowed(Scope $scope, string $permission): bool
    {
        return $this->permissions->hasPermission($scope->accountId, $permission);
    }

    /** @return array<string,mixed> */
    private function attemptHistory(Scope $scope, int $offset): array
    {
        $items = $this->store->studentAttempts($scope, $offset);
        $page = self::page($items, $offset);
        $page['items'] = array_slice($items, 0, 50);
        return $page;
    }

    /** @return array<string,mixed> */
    private function typingHistory(Scope $scope, int $offset): array
    {
        $items = $this->store->studentTypingResults($scope, $offset);
        $page = self::page($items, $offset);
        $page['items'] = array_slice($items, 0, 50);
        $page['best'] = $this->store->bestTyping($scope);
        return $page;
    }

    /** @return array<string,mixed> */
    private function inviteStudent(Scope $scope, string $studentId): array
    {
        $this->student($scope, $studentId);
        $code = bin2hex(random_bytes(12));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+72 hours')->format(DateTimeInterface::ATOM);
        $invite = $this->store->createStudentInvite($scope, $studentId, hash('sha256', $code), $expiresAt);
        return [...$invite, 'code' => $code];
    }

    private function revokeStudentAccount(Scope $scope, string $studentId): array
    {
        $this->student($scope, $studentId);
        $this->store->revokeStudentAccount($scope, $studentId);
        return ['studentId' => $studentId, 'revoked' => true];
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function activateInvite(AuthenticatedIdentity $identity, string $personId, array $body): array
    {
        self::only($body, ['code']);
        $code = Input::text($body['code'] ?? null, 64);
        $invite = $this->store->findStudentInvite(hash('sha256', $code));
        if ($invite === null || (string) $invite['personId'] !== $personId) {
            throw new EducationError(404, 'not_found');
        }
        if (!$this->organizations->activeMember($personId, (string) $invite['organizationId'])) {
            throw new EducationError(403, 'access_denied');
        }
        $scope = new Scope(
            (string) $invite['organizationId'],
            $identity->accountId,
            $personId,
            $identity->locale ?? 'ru-RU',
            $identity->timezone ?? 'UTC',
            (string) $invite['studentId'],
        );
        return $this->mutations->run(
            $scope,
            'activate_invite',
            fn(): array => $this->store->activateStudentAccount(
                (string) $invite['id'],
                (string) $invite['studentId'],
                (string) $invite['organizationId'],
                $identity->accountId,
                $personId,
            ),
        );
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function assignActivity(Scope $scope, string $code, array $input): array
    {
        self::only($input, ['groupId', 'studentId', 'config']);
        $definition = $this->store->activityDefinition(Input::text($code, 60));
        if ($definition === null) {
            throw new EducationError(404, 'not_found');
        }
        $groupId = empty($input['groupId']) ? null : Input::uuid($input['groupId']);
        $studentId = empty($input['studentId']) ? null : Input::uuid($input['studentId']);
        if (($groupId === null) === ($studentId === null)) {
            throw new EducationError(422, 'invalid_input');
        }
        if (isset($input['config']) && (!is_array($input['config']) || array_is_list($input['config']) && $input['config'] !== [])) {
            throw new EducationError(422, 'invalid_input');
        }
        $this->handlers()->validateConfig($code, $input['config'] ?? []);
        $config = $input['config'] ?? [];
        $assignment = $this->store->createAssignment($scope, (string) $definition['id'], $groupId, $studentId, $config);
        return [...$assignment, 'activityCode' => $code, 'activityName' => $definition['name']];
    }

    /** @return array<string,mixed> */
    private function assignments(Scope $scope, int $offset): array
    {
        $items = $this->store->assignments($scope, $offset);
        $page = self::page($items, $offset);
        $page['items'] = array_values(array_filter(array_slice($items, 0, 50), function (array $row) use ($scope): bool {
            $row = self::jsonFields($row, ['config', 'completion']);
            if ($row['studentPersonId'] !== null) {
                return $this->access->member((string) $row['studentPersonId'], $scope->organizationId)
                    && $this->access->resourceAllowed((string) $row['studentPersonId'], 'student', (string) $row['studentId']);
            }
            return true;
        }));
        return $page;
    }

    /**
     * @param array<array-key,mixed> $query
     * @return array<string,mixed>
     */
    private function groupActivityResults(Scope $scope, string $groupId, array $query, int $offset): array
    {
        $this->group($scope, $groupId);
        $code = Input::text($query['activity'] ?? null, 60);
        $definition = $this->store->activityDefinition($code) ?? throw new EducationError(404, 'not_found');
        [$from,$to,$start,$end] = $this->activityRange($scope, $query);
        $items = $this->store->groupActivityResults(
            $scope,
            $groupId,
            $code,
            $offset,
            isset($query['from']) || isset($query['to']) ? $start : null,
            isset($query['from']) || isset($query['to']) ? $end : null,
        );
        $page = self::page($items, $offset);
        $page['items'] = array_values(array_filter(array_map(function (array $row) use ($scope): ?array {
            $personId = (string) $row['personId'];
            if (!$this->access->resourceAllowed($personId, 'attempt', (string) $row['id'])) {
                return null;
            }
            if (!$this->access->member($personId, $scope->organizationId)
                || !$this->access->resourceAllowed($personId, 'student', (string) $row['studentId'])) {
                return null;
            }
            $name = $this->access->personName($personId);
            if ($name === null) {
                return null;
            }
            return [...$row, 'name' => $name];
        }, array_slice($items, 0, 50)), static fn(?array $row): bool => $row !== null));
        $page['activity'] = $definition['name'];
        return $page;
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    /** Homework calls this only inside its assignment/recipient state and attempt-limit locks. */
    public function startHomeworkAttempt(Scope $scope, string $code, array $input): array
    {
        $this->assertActivityStudent($scope);
        return $this->mutations->run($scope, 'student_start_attempt', fn(): array => $this->startAttempt($scope, $code, $input, true));
    }

    private function startAttempt(Scope $scope, string $code, array $input, bool $fromHomework = false): array
    {
        ActivityHandlerRegistry::only($input, ['durationSeconds','language','mode','difficulty','minAccuracy','targetWpm','maxAttempts','roundCount','source','assignmentId']);
        $definition = $this->store->activityDefinition(Input::text($code, 60));
        if ($definition === null || !in_array($definition['enabled'], [true,'t',1], true)) {
            throw new EducationError(404, 'not_found');
        }
        if (!$this->permissionsAllowed($scope, (string) $definition['requiredPermission'])) {
            throw new EducationError(403, 'access_denied');
        }
        $configInput = array_diff_key($input, array_flip(['source','assignmentId']));
        $config = $this->handlers()->validateConfig($code, $configInput);
        $source = $input['source'] ?? 'browser';
        if (!in_array($source, ['browser','managed-device','native-app'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        $assignmentId = isset($input['assignmentId']) ? Input::uuid($input['assignmentId']) : null;
        if ($assignmentId !== null) {
            $assigned = array_values(array_filter($this->store->studentAssignments($scope), static fn(array $a): bool => $a['id'] === $assignmentId))[0] ?? null;
            if ($assigned === null || $assigned['activityId'] !== $definition['id']) {
                throw new EducationError(404, 'not_found');
            }
            if ($this->handlers()->validateConfig($code, ActivityHandlerRegistry::json($assigned['config'])) != $config) {
                throw new EducationError(422, 'invalid_input');
            }
            $this->store->guardAssignmentAttempt($scope, $assignmentId, $config['maxAttempts'], $fromHomework);
        }
        $set = $code === 'platform-typing' ? $this->store->exerciseSet($code, $config['language'], $config['mode'], $config['difficulty']) : null;
        $exercise = $this->handlers()->handler($code)->createExercise($config, $set);
        $exercise['configSnapshot'] = $config;
        $snapshotConfig = $code === 'platform-typing' && array_diff(array_keys($configInput), ['durationSeconds','language']) === []
            ? $configInput : $config;
        $attempt = $this->store->createAttempt($scope, (string) $definition['id'], $assignmentId, $source, $exercise['text'] ?? '', $snapshotConfig);
        $this->store->snapshotAttempt($scope, $attempt['id'], (int) $definition['contractVersion'], $exercise);
        return $this->attemptView($scope, $attempt['id']);
    }

    private function finishAttempt(Scope $scope, string $attemptId, array $input): array
    {
        $attempt = $this->authorizedAttempt($scope, $attemptId);
        $evaluation = $this->handlers()->handler($attempt['activityCode'])->evaluate($attempt, $input);
        $result = $this->store->completeActivityAttempt($scope, $attemptId, $evaluation);
        $this->achievements?->recordAttempt($scope, $attemptId);
        $this->activityResults?->finished($scope, $attemptId);
        return $result;
    }

    private function handlers(): ActivityHandlerRegistry
    {
        return $this->activityHandlers ?? new ActivityHandlerRegistry();
    }

    private function assertActivityStudent(Scope $scope): void
    {
        if ($scope->studentId === null) {
            throw new EducationError(403, 'access_denied');
        }
        $student = $this->store->studentByAccount($scope->accountId, $scope->organizationId, $scope->personId);
        if ($student === null || $student['id'] !== $scope->studentId) {
            throw new EducationError(403, 'access_denied');
        }
    }

    private function authorizedAttempt(Scope $scope, string $id): array
    {
        $attempt = $this->store->attempt($scope, $id) ?? throw new EducationError(404, 'not_found');
        if (($attempt['revokedAt'] ?? null) !== null) {
            throw new EducationError(409, 'attempt_revoked');
        }
        $definition = $this->store->activityDefinition((string) $attempt['activityCode']);
        if ($definition === null || !in_array($definition['enabled'], [true,'t',1], true) || !$this->permissionsAllowed($scope, (string) $definition['requiredPermission'])) {
            throw new EducationError(403, 'access_denied');
        }
        $this->access->assertResource($scope, 'attempt', $id);
        return $attempt;
    }

    /** No private answer key, result evidence or account identity enters the running DTO. */
    private function attemptView(Scope $scope, string $id): array
    {
        $attempt = $this->authorizedAttempt($scope, $id);
        $public = array_intersect_key($attempt, array_flip(['id','studentId','activityId','activityCode','assignmentId','status','source','contractVersion','startedAt','finishedAt']));
        $public['config'] = ActivityHandlerRegistry::json($attempt['config']);
        $state = ActivityHandlerRegistry::json($attempt['state'] ?? []);
        $public['state'] = $state === [] ? (object) [] : $state;
        $public['stateVersion'] = (int) ($attempt['stateVersion'] ?? 0);
        $out = ['attempt' => $public,'exercise' => $this->handlers()->handler($attempt['activityCode'])->publicExercise($attempt)];
        if ($attempt['status'] === 'finished') {
            $out['result'] = $this->activityView?->forAttempt($scope->organizationId, $id)
                ?? (isset($attempt['result']) ? ['attemptId' => $id,...ActivityHandlerRegistry::json($attempt['result'])] : null);
        }
        return $out;
    }

    private function saveAttemptState(Scope $scope, string $id, array $input): array
    {
        ActivityHandlerRegistry::only($input, ['version','state']);
        $version = ActivityHandlerRegistry::integer($input['version'] ?? null, 0, 2147483646);
        if (!is_array($input['state'] ?? null)) {
            throw new EducationError(422, 'invalid_input');
        }
        $attempt = $this->authorizedAttempt($scope, $id);
        $state = $this->handlers()->handler($attempt['activityCode'])->validateState($attempt, $input['state']);
        $this->store->saveAttemptState($scope, $id, $version, $state);
        return $this->attemptView($scope, $id);
    }

    private function revokeActivityAttempt(Scope $scope, string $id, array $input): array
    {
        ActivityHandlerRegistry::only($input, []);
        $row = $this->store->revokeActivityAttempt($scope, $id);
        $this->achievements?->reconcileForStudent($scope->organizationId, $row['studentId']);
        return $row;
    }

    private function activityRange(Scope $scope, array $query): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone($scope->timezone));
        $from = Input::date($query['from'] ?? $now->modify('-30 days')->format('Y-m-d'));
        $to = Input::date($query['to'] ?? $now->format('Y-m-d'));
        [$start,$end] = Input::range($from, $to, $scope->timezone);
        $code = isset($query['activity']) ? Input::text($query['activity'], 60) : null;
        if ($code !== null) {
            $this->handlers()->handler($code);
        }
        return [$from,$to,$start,$end,$code];
    }

    private function studentActivityProgress(Scope $scope, array $query): array
    {
        [$from,$to,$start,$end,$code] = $this->activityRange($scope, $query);
        return [...($this->achievements?->progressForStudent($scope->organizationId, (string) $scope->studentId, $start, $end, $code, fn(string $type, string $resource): bool => $this->access->resourceAllowed($scope->personId, $type, $resource)) ?? []),'from' => $from,'to' => $to];
    }

    private function groupActivityProgress(Scope $scope, string $id, array $query): array
    {
        $this->group($scope, $id);
        [$from,$to,$start,$end,$code] = $this->activityRange($scope, $query);
        $items = [];
        $offset = Input::page($query['offset'] ?? null);
        foreach (array_slice($this->store->groupStudents($scope, $id, $offset), 0, 50) as $student) {
            $studentId = (string) $student['id'];
            $personId = (string) $student['personId'];
            if (!$this->access->member($personId, $scope->organizationId) || !$this->access->resourceAllowed($personId, 'student', $studentId)) {
                continue;
            }
            $name = $this->access->personName($personId);
            if ($name === null) {
                continue;
            }
            $items[] = ['studentId' => $studentId,'name' => $name,...($this->achievements?->progressForStudent($scope->organizationId, $studentId, $start, $end, $code, fn(string $type, string $resource): bool => $this->access->resourceAllowed($personId, $type, $resource) && $this->access->resourceAllowed($scope->personId, $type, $resource)) ?? [])];
        }
        return ['items' => $items,'from' => $from,'to' => $to,'offset' => $offset,'limit' => 50];
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function createEvent(Scope $scope, array $input): array
    {
        self::only($input, ['title', 'kind', 'startsAt', 'endsAt', 'location']);
        $values = self::eventValues($input);
        $kind = Input::text($input['kind'] ?? null, 20);
        if (!in_array($kind, ['meeting', 'birthday', 'other'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        return $this->store->createEvent($scope, [...$values, 'kind' => $kind]);
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,string>
     */
    private static function eventValues(array $input): array
    {
        $start = Input::instant($input['startsAt'] ?? null);
        $end = Input::instant($input['endsAt'] ?? null);
        $seconds = (new DateTimeImmutable($end))->getTimestamp() - (new DateTimeImmutable($start))->getTimestamp();
        if ($seconds <= 0 || $seconds > 86400) {
            throw new EducationError(422, 'invalid_time');
        }
        return ['title' => Input::text($input['title'] ?? null, 160), 'startsAt' => $start, 'endsAt' => $end,
            'location' => Input::text($input['location'] ?? '', 240, false)];
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function attendance(Scope $scope, string $id, string $studentId, array $input): array
    {
        self::only($input, ['status']);
        $status = Input::text($input['status'] ?? null, 12);
        $lesson = $this->lesson($scope, $id);
        $visible = $this->participants($scope, $id);
        /** @var list<array<string,mixed>> $participants */
        $participants = $visible['items'];
        if (!in_array($studentId, array_column($participants, 'id'), true)) {
            throw new EducationError(404, 'not_found');
        }
        if (!in_array($status, ['present', 'absent', 'pending'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        if ($lesson['status'] === 'cancelled') {
            throw new EducationError(409, 'lesson_cancelled');
        }
        if (new DateTimeImmutable((string) $lesson['startsAt']) > $this->clock->now()) {
            throw new EducationError(409, 'lesson_not_started');
        }
        $this->store->markAttendance($scope, $id, $studentId, $status);
        return ['updated' => true];
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function profile(Scope $scope, array $input): array
    {
        self::only($input, ['displayName', 'avatarFileId']);
        $profile = $this->store->profile($scope);
        if ($profile !== null) {
            $this->access->assertResource($scope, 'profile', (string) $profile['id']);
        }
        if (!array_key_exists('displayName', $input) || !array_key_exists('avatarFileId', $input)) {
            throw new EducationError(422, 'invalid_input');
        }
        $name = ($input['displayName'] ?? null) === null ? null : Input::text($input['displayName'], 160);
        $avatar = ($input['avatarFileId'] ?? null) === null ? null : Input::uuid($input['avatarFileId']);
        if ($avatar !== null && ($this->store->file($scope, $avatar)['purpose'] ?? null) !== 'avatar') {
            throw new EducationError(422, 'invalid_avatar');
        }
        $this->store->saveProfile($scope, $name, $avatar);
        return $this->header($scope, $this->clock->now()->setTimezone(new DateTimeZone($scope->timezone)));
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function startThread(Scope $scope, array $input): array
    {
        self::only($input, ['recipientPersonId', 'subject', 'body']);
        $recipient = Input::uuid($input['recipientPersonId'] ?? null);
        $allowed = array_column($this->colleagues($scope), 'personId');
        if (!in_array($recipient, $allowed, true)) {
            throw new EducationError(403, 'recipient_unavailable');
        }
        return $this->store->startThread($scope, $recipient, Input::text($input['subject'] ?? null, 160), Input::text($input['body'] ?? null, 5000));
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function sendMessage(Scope $scope, string $id, array $input): array
    {
        self::only($input, ['body']);
        $this->access->assertResource($scope, 'thread', $id);
        $peers = $this->store->threadPeers($scope, $id);
        if ($peers === []) {
            throw new EducationError(404, 'not_found');
        }
        $allowed = array_column($this->colleagues($scope), 'personId');
        foreach ($peers as $peer) {
            if (!in_array($peer, $allowed, true)) {
                throw new EducationError(403, 'recipient_unavailable');
            }
        }
        return $this->store->sendMessage($scope, $id, Input::text($input['body'] ?? null, 5000));
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function readThread(Scope $scope, string $id, array $input): array
    {
        self::only($input, ['lastSeenMessageId']);
        $this->thread($scope, $id);
        $this->store->readThread($scope, $id, Input::uuid($input['lastSeenMessageId'] ?? null));
        return ['updated' => true];
    }
    /** @return array<string,mixed> */
    private function thread(Scope $scope, string $id): array
    {
        $this->access->assertResource($scope, 'thread', $id);
        return $this->store->thread($scope, $id) ?? throw new EducationError(404, 'not_found');
    }

    /** @return array<string,mixed> */
    private function notifications(Scope $scope, int $offset): array
    {
        $items = $this->store->notifications($scope, $offset);
        $page = self::page($items, $offset);
        $page['items'] = array_values(array_filter(array_slice($items, 0, 50), function (array $row) use ($scope): bool {
            if (($row['kind'] ?? 'message') === 'message') {
                return $row['threadId'] !== null
                    && $this->access->resourceAllowed($scope->personId, 'thread', (string) $row['threadId']);
            }
            try {
                $this->homework?->read($scope->studentId === null ? 'teacher_detail' : 'student_detail', $scope, [], (string) $row['resourceId']);
                return $this->homework !== null;
            } catch (EducationError) {
                return false;
            }
        }));
        return $page;
    }

    /** @return array<string,mixed> */
    private function readNotification(Scope $scope, string $id): array
    {
        $this->store->readNotification($scope, $id);
        return ['updated' => true];
    }

    /** @return array<string,mixed> */
    public function upload(Scope $scope, UploadedFileInterface $upload, ?string $lessonId, bool $avatar): array
    {
        $this->assertFileUpload($scope, $lessonId, $avatar);
        return $this->uploadStored($scope, $this->files->store($upload, $avatar), $lessonId, $avatar);
    }

    public function assertFileUpload(Scope $scope, ?string $lessonId, bool $avatar): void
    {
        if ($lessonId !== null) {
            $lesson = $this->lesson($scope, Input::uuid($lessonId));
            if ($lesson['status'] === 'cancelled') {
                throw new EducationError(409, 'lesson_cancelled');
            }
        }
        if ($avatar && $lessonId !== null) {
            throw new EducationError(422, 'invalid_file');
        }
    }

    public function uploadStored(Scope $scope, StoredFile $file, ?string $lessonId, bool $avatar): array
    {
        $this->assertFileUpload($scope, $lessonId, $avatar);
        try {
            return $this->mutations->run($scope, 'upload_file', fn(): array =>
                $this->store->createFile($scope, ['lessonId' => $lessonId, 'name' => $file->name, 'mime' => $file->mime,
                    'purpose' => $avatar ? 'avatar' : 'material', 'sizeBytes' => $file->size, 'sha256' => $file->sha256, 'storageKey' => $file->key]));
        } catch (\Throwable $error) {
            $this->files->remove($file->key);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function deleteFile(Scope $scope, string $id): array
    {
        $staged = null;
        try {
            $result = $this->mutations->run($scope, 'delete_file', function () use ($scope, $id, &$staged): array {
                $this->access->assertResource($scope, 'file', $id);
                $file = $this->store->deleteFile($scope, $id);
                $staged = $this->files->stageRemoval((string) $file['storageKey']);
                return ['id' => (string) $file['id'], 'deleted' => true];
            });
        } catch (\Throwable $error) {
            if ($staged !== null) {
                try {
                    $this->files->restoreRemoval($staged);
                } catch (\Throwable) {
                }
            }
            throw $error;
        }
        try {
            $this->files->purgeRemoval($staged);
        } catch (\Throwable) {
        }
        return $result;
    }

    /** @return array{id:string,lessonId:?string,name:string,mime:string,purpose:string,sizeBytes:int|string,sha256:string,storageKey:string} */
    public function fileForDownload(Scope $scope, string $id): array
    {
        $file = $this->store->file($scope, Input::uuid($id)) ?? throw new EducationError(404, 'not_found');
        $this->access->assertResource($scope, 'file', $id);
        if ($file['lessonId'] !== null) {
            if ($scope->studentId === null) {
                $this->lesson($scope, $file['lessonId']);
            } else {
                $this->access->assertResource($scope, 'lesson', $file['lessonId']);
            }
        }
        return $file;
    }

    /** @return array{stream:resource,name:string,mime:string,size:int,sha256:string} */
    public function download(Scope $scope, string $id): array
    {
        $file = $this->fileForDownload($scope, $id);
        $stream = $this->files->open((string) $file['storageKey'], (int) $file['sizeBytes'], (string) $file['sha256']);
        return ['stream' => $stream, 'name' => (string) $file['name'],
            'mime' => (string) $file['mime'], 'size' => (int) $file['sizeBytes'], 'sha256' => (string) $file['sha256']];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function page(array $items, int $offset): array
    {
        return ['items' => array_slice($items, 0, 50), 'limit' => 50, 'offset' => $offset,
            'nextOffset' => count($items) > 50 ? $offset + 50 : null];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function resourcePage(Scope $scope, array $items, int $offset, string $type): array
    {
        $page = self::page($items, $offset);
        $page['items'] = array_values(array_filter(
            array_slice($items, 0, 50),
            fn(array $row): bool => $this->access->resourceAllowed($scope->personId, $type, (string) $row['id']),
        ));
        return $page;
    }

    /**
     * @param array<array-key,mixed> $input
     * @param list<string> $keys
     */
    private static function only(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys) !== []) {
            throw new EducationError(422, 'invalid_input');
        }
    }
}
