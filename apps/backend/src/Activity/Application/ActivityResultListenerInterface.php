<?php

declare(strict_types=1);

namespace App\Activity\Application;

/**
 * Domain listener for Universal Activity Engine evaluation and publication lifecycle events.
 * Implementations (such as Education's journal adapter) must be idempotent on repeated delivery.
 */
interface ActivityResultListenerInterface
{
    /**
     * @param array<string, mixed> $attemptData
     */
    public function onAttemptEvaluated(string $organizationId, string $attemptId, array $attemptData): void;

    /**
     * @param array<string, mixed> $attemptData
     */
    public function onAttemptResultsPublished(
        string $organizationId,
        string $attemptId,
        array $attemptData,
        bool $published,
    ): void;
}
