<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;

final readonly class ErasureCaseStateMachine
{
    private const TRANSITIONS = [
        'planned' => ['in_progress', 'blocked', 'failed'],
        'in_progress' => ['partially_completed', 'completed', 'blocked', 'failed'],
        'partially_completed' => ['in_progress', 'completed', 'blocked', 'failed'],
        'blocked' => ['in_progress', 'partially_completed', 'completed', 'failed'],
        'failed' => ['in_progress', 'partially_completed', 'completed', 'blocked'],
        'completed' => [],
    ];

    public function assertTransition(string $from, string $to): void
    {
        if ($from !== $to && !in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new DomainException('Invalid erasure case transition.');
        }
    }
}
