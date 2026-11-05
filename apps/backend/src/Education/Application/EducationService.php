<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
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
            'students' => $this->peoplePage($scope, $this->store->students($scope, $offset), $offset),
            'candidates' => self::page(array_slice($this->access->candidates($scope), $offset, 51), $offset),
            'colleagues' => self::page(array_slice($this->colleagues($scope), $offset, 51), $offset),
            'schedule' => $this->schedule($scope, $query),
            'timeline' => $this->timeline($scope, Input::date($query['date'] ?? null)),
            'next' => $this->nextLesson($scope, $now, false),
            'current' => $this->nextLesson($scope, $now, true),
            'lesson' => $this->lesson($scope, Input::uuid($id)),
            'participants' => $this->participants($scope, Input::uuid($id)),
            'materials' => $this->materials($scope, Input::uuid($id), $offset),
            'files' => $this->resourcePage($scope, $this->store->files($scope, null, $offset), $offset, 'file'),
            'attention' => $this->attention($scope, $now),
            'threads' => $this->resourcePage($scope, $this->store->threads($scope, $offset), $offset, 'thread'),
            'thread' => $this->thread($scope, Input::uuid($id)),
            'messages' => $this->messages($scope, Input::uuid($id), $offset),
            'notifications' => $this->notifications($scope, $offset),
            default => throw new EducationError(404, 'not_found'),
        };
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    public function write(string $operation, Scope $scope, array $input, ?string $id = null, ?string $studentId = null): array
    {
        return $this->mutations->run($scope, $operation, fn(): array => match ($operation) {
            'create_group' => $this->createGroup($scope, $input),
            'enroll_student' => $this->enrollStudent($scope, $input),
            'create_lesson' => $this->createLesson($scope, $input),
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
    private function enrollStudent(Scope $scope, array $input): array
    {
        self::only($input, ['personId', 'groupId']);
        $person = Input::uuid($input['personId'] ?? null);
        $group = Input::uuid($input['groupId'] ?? null);
        $this->group($scope, $group);
        if (!$this->access->member($person, $scope->organizationId)) {
            throw new EducationError(403, 'person_outside_organization');
        }
        return $this->store->enrollStudent($scope, $person, $group);
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
        $this->group($scope, $values['groupId']);
        return $this->store->createLesson($scope, $values);
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
        if ($lesson['status'] === 'cancelled' || new DateTimeImmutable((string) $lesson['startsAt']) > $this->clock->now()) {
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
            $this->lesson($scope, Input::uuid($lessonId));
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
