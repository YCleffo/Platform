<?php

declare(strict_types=1);

namespace App\Education\Application;

interface HomeworkStore
{
    public function transaction(callable $action): array;
    public function assignment(Scope $scope, string $id, bool $lock = false): array;
    public function teacherAssignment(Scope $scope, string $id, bool $lock = false): array;
    public function studentRecipient(Scope $scope, string $assignmentId, bool $lock = false): array;
    public function recipient(Scope $scope, string $id, bool $lock = false): array;
    public function submissionRecipient(Scope $scope, string $submissionId): array;
    public function submission(Scope $scope, array $recipient, bool $lock = false): array;
    public function assignments(Scope $scope, array $filters, int $offset, string $now): array;
    public function studentAssignments(Scope $scope, array $filters, int $offset, string $now): array;
    public function recipients(Scope $scope, string $assignmentId, int $offset): array;
    public function summary(Scope $scope, array $filters, string $now, ?string $assignmentId = null): array;
    public function createAssignment(Scope $scope, array $values, string $now): string;
    public function updateAssignment(Scope $scope, string $id, array $values): void;
    public function setStatus(Scope $scope, string $id, string $status, string $now): void;
    public function addRecipients(Scope $scope, string $id, array $students, string $now): void;
    public function groupRecipients(Scope $scope, string $groupId): array;
    public function bumpAssignment(Scope $scope, string $id): void;
    public function revision(Scope $scope, array $assignment, string $action, string $now): void;
    public function history(Scope $scope, string $id, int $offset): array;
    public function saveDraft(Scope $scope, array $recipient, array $submission, string $text, string $now): void;
    public function submit(Scope $scope, array $assignment, array $submission, ?string $attemptId, string $key, string $now, bool $late): void;
    public function review(Scope $scope, array $submission, array $values, string $now): void;
    public function replay(Scope $scope, string $operation, string $key, string $id, array $request): ?array;
    public function remember(Scope $scope, string $operation, string $key, string $id, array $request, array $response): void;
    public function replaceDraftRecipients(Scope $scope, string $id, array $students, string $now): void;
}
