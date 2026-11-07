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
    ) {}

    /**
     * @param array<array-key,mixed> $query
     * @return array<string,mixed>|null
     */
    public function read(string $operation, Scope $scope, array $query, ?string $id = null): ?array
    {
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
            'notifications' => $this->notifications($scope, $offset),
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
            'student_activity_catalog' => ['items' => array_map(
                static fn(array $row): array => self::jsonFields($row, ['config', 'metrics']),
                $this->store->activityCatalog(),
            )],
            'student_activity_config' => $this->studentActivityConfig($scope, (string) $id),
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
        if ($operation === 'delete_file') {
            self::only($input, []);
            return $this->deleteFile($scope, Input::uuid($id));
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
            'account' => $this->store->studentAccount($scope, $id)];
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
        self::only($input, ['groupId', 'title', 'startsAt', 'endsAt', 'location']);
        $values = self::eventValues($input);
        $values['groupId'] = Input::uuid($input['groupId'] ?? null);
        self::assertGroupMutable($this->group($scope, $values['groupId']));
        return $this->store->createLesson($scope, $values);
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function updateLesson(Scope $scope, string $id, array $input): array
    {
        self::only($input, ['groupId', 'title', 'startsAt', 'endsAt', 'location']);
        $this->lesson($scope, $id);
        $values = self::eventValues($input);
        $values['groupId'] = Input::uuid($input['groupId'] ?? null);
        return $this->store->updateLesson($scope, $id, $values);
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
        $now = (new DateTimeImmutable('now', new DateTimeZone($scope->timezone)));
        $schedule = $this->store->studentSchedule($scope, $now->format(DateTimeInterface::ATOM),
            $now->modify('+31 days')->format(DateTimeInterface::ATOM));
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
        return [...$lesson, 'materials' => $this->store->studentMaterials($scope, $id)];
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
        $assignments = array_values(array_filter($this->store->studentAssignments($scope),
            static fn(array $row): bool => $row['activityId'] === $definition['id']));
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
        $scope = new Scope((string) $invite['organizationId'], $identity->accountId, $personId,
            $identity->locale ?? 'ru-RU', $identity->timezone ?? 'UTC', (string) $invite['studentId']);
        return $this->mutations->run($scope, 'activate_invite',
            fn(): array => $this->store->activateStudentAccount((string) $invite['id'], (string) $invite['studentId'],
                (string) $invite['organizationId'], $identity->accountId, $personId));
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
        $config = is_array($input['config'] ?? null) ? $input['config'] : [];
        if (array_diff(array_keys($config), ['durationSeconds', 'language']) !== []) {
            throw new EducationError(422, 'invalid_input');
        }
        if (isset($config['durationSeconds']) && !in_array($config['durationSeconds'], [15, 30, 60], true)) {
            throw new EducationError(422, 'invalid_input');
        }
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
        $items = $this->store->groupActivityResults($scope, $groupId, $code, $offset);
        $page = self::page($items, $offset);
        $page['items'] = array_values(array_filter(array_map(function (array $row) use ($scope): ?array {
            $personId = (string) $row['personId'];
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
    private function startAttempt(Scope $scope, string $code, array $input): array
    {
        self::only($input, ['durationSeconds', 'language', 'source']);
        $definition = $this->store->activityDefinition(Input::text($code, 60));
        if ($definition === null || !in_array($definition['enabled'], [true, 't', 1], true)) {
            throw new EducationError(404, 'not_found');
        }
        if (!$this->permissionsAllowed($scope, (string) $definition['requiredPermission'])) {
            throw new EducationError(403, 'access_denied');
        }
        $duration = (int) ($input['durationSeconds'] ?? 0);
        $language = (string) ($input['language'] ?? '');
        if (!in_array($duration, [15, 30, 60], true) || !in_array($language, ['ru', 'en'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        $source = (string) ($input['source'] ?? 'browser');
        if (!in_array($source, ['browser', 'managed-device', 'native-app'], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        $exercise = $this->typingExercise($language, $duration);
        $config = ['durationSeconds' => $duration, 'language' => $language];
        $attempt = $this->store->createAttempt($scope, (string) $definition['id'], null, $source, $exercise, $config);
        return ['attempt' => [...$attempt, 'exercise' => null], 'exercise' => ['text' => $exercise,
            'durationSeconds' => $duration, 'language' => $language]];
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function finishAttempt(Scope $scope, string $attemptId, array $input): array
    {
        self::only($input, ['typed', 'durationSeconds']);
        $attempt = $this->store->attempt($scope, $attemptId) ?? throw new EducationError(404, 'not_found');
        $config = is_array($attempt['config'] ?? null) ? $attempt['config']
            : (json_decode((string) ($attempt['config'] ?? ''), true) ?: []);
        $duration = (int) ($input['durationSeconds'] ?? 0);
        if ($duration !== (int) ($config['durationSeconds'] ?? 0)) {
            throw new EducationError(422, 'invalid_input');
        }
        $typed = (string) ($input['typed'] ?? '');
        $maximum = (int) ceil(500 * 5 * $duration / 60);
        if (strlen($typed) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $typed) === 1) {
            throw new EducationError(422, 'invalid_input');
        }
        $exercise = (string) ($attempt['exercise'] ?? '');
        [$correct, $wrong] = [0, 0];
        $length = min(strlen($exercise), strlen($typed));
        for ($i = 0; $i < $length; $i++) {
            if ($typed[$i] === $exercise[$i]) {
                $correct++;
            } else {
                $wrong++;
            }
        }
        $keystrokes = strlen($typed);
        $errors = $wrong + max(0, $keystrokes - strlen($exercise));
        $minutes = $duration / 60;
        $wpm = round($correct / 5 / $minutes, 1);
        $accuracy = $keystrokes > 0 ? round($correct / $keystrokes * 100, 2) : 0.0;
        $metrics = ['language' => (string) ($config['language'] ?? 'en'), 'keystrokes' => $keystrokes,
            'correct' => $correct, 'errors' => $errors, 'wpm' => $wpm, 'accuracy' => $accuracy];
        return $this->store->finishAttempt($scope, $attemptId, $typed, $duration, $metrics, [
            'typedLength' => $keystrokes, 'exerciseLength' => strlen($exercise), 'durationSeconds' => $duration,
        ]);
    }

    private function typingExercise(string $language, int $duration): string
    {
        $words = self::TYPING_WORDS[$language];
        $count = match ($duration) {
            15 => 25, 30 => 50, default => 90,
        };
        $randomizer = new \Random\Randomizer();
        $picked = $randomizer->shuffleArray($words);
        while (count($picked) < $count) {
            $picked[] = $words[$randomizer->getInt(0, count($words) - 1)];
        }
        return implode(' ', array_slice($picked, 0, $count));
    }

    private const TYPING_WORDS = [
        'en' => ['apple', 'brave', 'cloud', 'dream', 'eagle', 'flame', 'grape', 'house', 'ivory', 'jolly', 'koala', 'lemon',
            'mango', 'noble', 'ocean', 'piano', 'queen', 'river', 'stone', 'tiger', 'ultra', 'vivid', 'water', 'yacht',
            'zebra', 'angle', 'baker', 'candy', 'delta', 'ember', 'frost', 'giant', 'honey', 'input', 'jumbo', 'karma',
            'lunar', 'magic', 'north', 'orbit', 'plush', 'quiet', 'radar', 'silver', 'tower', 'union', 'valor', 'wheat',
            'xenon', 'yield', 'zesty', 'acorn', 'bloom', 'crane', 'daisy', 'elbow', 'fable', 'globe', 'haste', 'irony'],
        'ru' => ['арбуз', 'берег', 'ветер', 'город', 'дерево', 'енот', 'жираф', 'замок', 'иголка', 'йогурт', 'книга', 'лампа',
            'море', 'небо', 'огонь', 'песня', 'радуга', 'солнце', 'трава', 'улица', 'фонарь', 'хлеб', 'цветок', 'чайка',
            'школа', 'щавель', 'экран', 'юность', 'яблоко', 'автор', 'банан', 'вагон', 'глина', 'дом', 'ежик', 'жакет',
            'звезда', 'искра', 'капля', 'луна', 'мел', 'нитка', 'окно', 'парус', 'роса', 'снег', 'тропа', 'узор', 'флаг',
            'холм', 'цирк', 'час', 'шарф', 'щека', 'этаж', 'юмор', 'якорь', 'альбом', 'бизон', 'врата'],
    ];

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
        $page['items'] = array_values(array_filter(array_slice($items, 0, 50), fn(array $row): bool =>
            $this->access->resourceAllowed($scope->personId, 'thread', (string) $row['threadId'])));
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
        if ($lessonId !== null) {
            $lesson = $this->lesson($scope, Input::uuid($lessonId));
            if ($lesson['status'] === 'cancelled') {
                throw new EducationError(409, 'lesson_cancelled');
            }
        }
        if ($avatar && $lessonId !== null) {
            throw new EducationError(422, 'invalid_file');
        }
        $file = $this->files->store($upload, $avatar);
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

    /** @return array{path:string,name:string,mime:string,size:int,sha256:string} */
    public function download(Scope $scope, string $id): array
    {
        $file = $this->store->file($scope, Input::uuid($id)) ?? throw new EducationError(404, 'not_found');
        $this->access->assertResource($scope, 'file', $id);
        $lessonId = $file['lessonId'] ?? null;
        if (is_string($lessonId)) {
            $this->lesson($scope, $lessonId);
        }
        $path = $this->files->path((string) $file['storageKey']);
        if (filesize($path) !== (int) $file['sizeBytes'] || hash_file('sha256', $path) !== $file['sha256']) {
            throw new EducationError(503, 'file_integrity_failed');
        }
        return ['path' => $path, 'name' => (string) $file['name'],
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
