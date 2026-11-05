<?php

declare(strict_types=1);

namespace App\Education\Application;

/** The module owns these records; all SQL belongs to its infrastructure adapter. */
interface EducationStore
{
    /** @return list<string> */
    public function teacherOrganizations(string $accountId, string $personId): array;
    public function teacherExists(Scope $scope): bool;
    public function grantTeacher(string $organizationId, string $accountId, string $personId): void;
    /** @return list<array<string,mixed>> */
    public function groups(Scope $scope, int $offset): array;
    /** @return array<string,mixed>|null */
    public function group(Scope $scope, string $id): ?array;
    /** @return array<string,mixed> */
    public function createGroup(Scope $scope, string $name): array;
    /** @return list<array<string,mixed>> */
    public function students(Scope $scope, int $offset): array;
    /** @return array<string,mixed> */
    public function enrollStudent(Scope $scope, string $personId, string $groupId): array;
    /** @return list<array<string,mixed>> */
    public function lessons(Scope $scope, string $from, string $to): array;
    /** @return array<string,mixed>|null */
    public function lesson(Scope $scope, string $id): ?array;
    /** @return array<string,mixed>|null */
    public function nextLesson(Scope $scope, string $now, bool $currentOnly): ?array;
    /**
     * @param array<string,string> $values
     * @return array<string,mixed>
     */
    public function createLesson(Scope $scope, array $values): array;
    /** @return list<array<string,mixed>> */
    public function participants(Scope $scope, string $lessonId): array;
    public function markAttendance(Scope $scope, string $lessonId, string $studentId, string $status): void;
    /** @return list<array<string,mixed>> */
    public function events(Scope $scope, string $from, string $to): array;
    /**
     * @param array<string,string> $values
     * @return array<string,mixed>
     */
    public function createEvent(Scope $scope, array $values): array;
    /** @return list<array<string,mixed>> */
    public function files(Scope $scope, ?string $lessonId, int $offset): array;
    /** @return array<string,mixed>|null */
    public function file(Scope $scope, string $id): ?array;
    /**
     * @param array<string,string|int|null> $values
     * @return array<string,mixed>
     */
    public function createFile(Scope $scope, array $values): array;
    /** @return array<string,mixed>|null */
    public function profile(Scope $scope): ?array;
    public function saveProfile(Scope $scope, ?string $displayName, ?string $avatarFileId): void;
    /** @return list<array<string,mixed>> */
    public function colleagues(Scope $scope): array;
    /** @return list<array<string,mixed>> */
    public function threads(Scope $scope, int $offset): array;
    /** @return array<string,mixed>|null */
    public function thread(Scope $scope, string $id): ?array;
    /** @return list<string> */
    public function threadPeers(Scope $scope, string $id): array;
    /** @return list<array<string,mixed>> */
    public function messages(Scope $scope, string $threadId, int $offset): array;
    /** @return array<string,mixed> */
    public function startThread(Scope $scope, string $recipientPersonId, string $subject, string $body): array;
    /** @return array<string,mixed> */
    public function sendMessage(Scope $scope, string $threadId, string $body): array;
    public function readThread(Scope $scope, string $threadId, string $lastSeenMessageId): void;
    /** @return list<array<string,mixed>> */
    public function notifications(Scope $scope, int $offset): array;
    public function unreadCount(Scope $scope): int;
    public function readNotification(Scope $scope, string $id): void;
    /** @return list<array<string,mixed>> */
    public function attention(Scope $scope, string $from, string $to): array;
}
