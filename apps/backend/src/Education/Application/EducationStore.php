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
    /** @return array<string,mixed> */
    public function updateGroup(Scope $scope, string $id, string $name): array;
    /** @return array<string,mixed> */
    public function archiveGroup(Scope $scope, string $id): array;
    /** @return list<array<string,mixed>> */
    public function groupStudents(Scope $scope, string $groupId, int $offset): array;
    /** @return list<array<string,mixed>> */
    public function students(Scope $scope, int $offset): array;
    /** @return array<string,mixed> */
    public function enrollStudent(Scope $scope, string $personId, string $groupId): array;
    public function removeGroupStudent(Scope $scope, string $groupId, string $studentId): void;
    public function transferStudent(Scope $scope, string $sourceGroupId, string $targetGroupId, string $studentId): void;
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
    /**
     * @param array<string,string> $values
     * @return array<string,mixed>
     */
    public function updateLesson(Scope $scope, string $id, array $values): array;
    /** @return array<string,mixed> */
    public function completeLesson(Scope $scope, string $id, string $now): array;
    /** @return array<string,mixed> */
    public function cancelLesson(Scope $scope, string $id): array;
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
    /** @return array{id:string,lessonId:?string,name:string,mime:string,purpose:string,sizeBytes:int|string,sha256:string,storageKey:string}|null */
    public function file(Scope $scope, string $id): ?array;
    /**
     * @param array<string,string|int|null> $values
     * @return array<string,mixed>
     */
    public function createFile(Scope $scope, array $values): array;
    /** @return array<string,mixed> */
    public function deleteFile(Scope $scope, string $id): array;
    /** @return array<string,mixed>|null */
    public function profile(Scope $scope): ?array;
    public function saveProfile(Scope $scope, ?string $displayName, ?string $avatarFileId): void;
    /** @return list<array<string,mixed>> */
    public function colleagues(Scope $scope): array;
    /** @return list<array<string,mixed>> */
    public function allowedRecipients(Scope $scope): array;
    /** @return list<array<string,mixed>> */
    public function threads(Scope $scope, int $offset): array;
    /** @return array<string,mixed>|null */
    public function thread(Scope $scope, string $id): ?array;
    /** @return list<string> */
    public function threadPeers(Scope $scope, string $id): array;
    /** @return list<array<string,mixed>> */
    public function messages(Scope $scope, string $threadId, int $offset): array;
    /** @return array<string,mixed> */
    public function startThread(Scope $scope, string $recipientPersonId, string $subject, string $body, ?string $contextType = null, ?string $contextId = null): array;
    /** @return array<string,mixed> */
    public function sendMessage(Scope $scope, string $threadId, ?string $body, ?string $clientKey = null, string $kind = 'text', ?string $mediaAssetId = null, ?string $replyToId = null, array $metadata = []): array;
    /** @return array{reacted:bool,reactions:list<array{emoji:string,count:int,reacted:bool}>} */
    public function toggleReaction(Scope $scope, string $threadId, string $messageId, string $emoji): array;
    public function readThread(Scope $scope, string $threadId, string $lastSeenMessageId): void;
    /** @return list<array<string,mixed>> */
    public function notifications(Scope $scope, int $offset): array;
    public function unreadCount(Scope $scope): int;
    public function readNotification(Scope $scope, string $id): void;
    /** @return list<array<string,mixed>> */
    public function attention(Scope $scope, string $from, string $to): array;
    /** @return array<string,mixed>|null */
    public function lessonReport(Scope $scope, string $lessonId): ?array;
    /**
     * @param array{lessonId:string,topic:string,summary:string,teacherComment:string|null} $values
     * @return array<string,mixed>
     */
    public function saveLessonReport(Scope $scope, array $values): array;
    /** @return list<array<string,mixed>> */
    public function lessonResults(Scope $scope, string $lessonId): array;
    /**
     * @return array<string,mixed>
     */
    public function saveLessonResult(Scope $scope, string $lessonId, string $studentId, string $status, ?string $comment): array;
    /** @return list<array<string,mixed>> */
    public function lessonResultRevisions(Scope $scope, string $lessonId, string $studentId): array;
    /** @return list<array<string,mixed>> */
    public function journal(Scope $scope, string $groupId, string $from, string $to, ?string $studentId, int $offset): array;
    /**
     * @param list<string> $lessonIds
     * @return list<array<string,mixed>>
     */
    public function journalReports(Scope $scope, array $lessonIds): array;
    /**
     * @param list<string> $lessonIds
     * @return list<array<string,mixed>>
     */
    public function journalResults(Scope $scope, array $lessonIds, ?string $studentId): array;
    /** @return array<string,mixed>|null */
    public function student(Scope $scope, string $id): ?array;
    /** @return list<array{id:string,name:string,archivedAt:string|null}> */
    public function studentGroups(Scope $scope, string $studentId): array;
    /** @return list<array{id:string,name:string,archivedAt:string|null}> */
    public function studentGroupList(Scope $scope): array;
    /** @return list<array{id:string,name:string,mime:string,sizeBytes:int|string,createdAt:string}> */
    public function studentMaterials(Scope $scope, string $lessonId): array;
    /** @return list<array<string,mixed>> */
    public function studentAttendance(Scope $scope, string $studentId, string $from, string $to, int $offset): array;
    /** @return list<array<string,mixed>> */
    public function studentResults(Scope $scope, string $studentId, int $offset): array;
    /** @return array<string,mixed> */
    public function studentProgress(Scope $scope, string $studentId, string $from, string $to): array;
    /** @return list<string> */
    public function studentOrganizations(string $accountId): array;
    /** @return array{id:string,personId:string}|null */
    public function studentByAccount(string $accountId, string $organizationId, string $personId): ?array;
    /** @return array<string,mixed>|null */
    public function studentAccount(Scope $scope, string $studentId): ?array;
    /** @return array<string,mixed> */
    public function createStudentInvite(Scope $scope, string $studentId, string $codeHash, string $expiresAt): array;
    /** @return array<string,mixed>|null */
    public function findStudentInvite(string $codeHash): ?array;
    /**
     * @return array<string,mixed>
     */
    public function activateStudentAccount(string $inviteId, string $studentId, string $organizationId, string $accountId, string $personId): array;
    public function revokeStudentAccount(Scope $scope, string $studentId): void;
    /** @return list<array<string,mixed>> */
    public function studentSchedule(Scope $scope, string $from, string $to): array;
    /** @return list<array<string,mixed>> */
    public function studentLessons(Scope $scope, int $offset): array;
    /** @return array<string,mixed>|null */
    public function studentLesson(Scope $scope, string $lessonId): ?array;
    /** @return list<array<string,mixed>> */
    public function activityCatalog(): array;
    /** @return array<string,mixed>|null */
    public function activityDefinition(string $code): ?array;
    /** @return array<string,mixed>|null */
    public function activityDefinitionById(string $id): ?array;
    /** @return list<array<string,mixed>> */
    public function studentAssignments(Scope $scope): array;
    /**
     * @return array<string,mixed>
     */
    public function createAttempt(Scope $scope, string $activityId, ?string $assignmentId, string $source, string $exercise, array $config): array;
    /** @return array<string,mixed>|null */
    public function attempt(Scope $scope, string $attemptId): ?array;
    /** @return array<string,mixed> */
    public function finishAttempt(Scope $scope, string $attemptId, string $typed, int $durationSeconds, array $metrics, array $payload): array;
    public function guardAssignmentAttempt(Scope $scope, string $assignmentId, int $maxAttempts, bool $fromHomework): void;
    public function exerciseSet(string $code, string $language, string $mode, int $difficulty): ?array;
    public function snapshotAttempt(Scope $scope, string $attemptId, int $version, array $exercise): void;
    public function saveAttemptState(Scope $scope, string $attemptId, int $version, array $state): void;
    public function completeActivityAttempt(Scope $scope, string $attemptId, array $evaluation): array;
    public function revokeActivityAttempt(Scope $scope, string $attemptId): array;
    /** @return list<array<string,mixed>> */
    public function studentAttempts(Scope $scope, int $offset): array;
    /** @return list<array<string,mixed>> */
    public function studentTypingResults(Scope $scope, int $offset): array;
    /** @return array<string,mixed>|null */
    public function bestTyping(Scope $scope): ?array;
    /**
     * @return array<string,mixed>
     */
    public function createAssignment(Scope $scope, string $activityId, ?string $groupId, ?string $studentId, array $config): array;
    /** @return list<array<string,mixed>> */
    public function assignments(Scope $scope, int $offset): array;
    /** @return list<array<string,mixed>> */
    public function groupActivityResults(Scope $scope, string $groupId, string $activityCode, int $offset, ?string $from = null, ?string $to = null): array;
}
