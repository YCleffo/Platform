<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;

final class DataSubjectRequestStateMachine
{
    private const TRANSITIONS = [
        'submitted' => ['identity_verification', 'in_review', 'cancelled'],
        'identity_verification' => ['in_review', 'rejected', 'cancelled'],
        'in_review' => ['in_progress', 'rejected', 'cancelled'],
        'in_progress' => ['completed', 'partially_completed'],
        'completed' => [], 'partially_completed' => [], 'rejected' => [], 'cancelled' => [],
    ];

    public function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new DomainException('Invalid data subject request transition.');
        }
    }
}
