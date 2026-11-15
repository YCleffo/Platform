<?php

declare(strict_types=1);

namespace App\Education\Application;

/** Existing Activities, private file and notification adapters for Homework. */
interface HomeworkExtensions
{
    /** Return only a verified, server-selected activity attempt, if required. */
    public function validateSubmission(Scope $scope, array $assignment, array $submission): ?string;

    public function assignmentView(Scope $scope, array $assignment): array;

    public function submissionView(Scope $scope, array $assignment, array $submission): array;

    /** Called within the same transaction as the state transition. */
    public function changed(Scope $scope, string $event, array $assignment, ?array $submission = null): void;
}
