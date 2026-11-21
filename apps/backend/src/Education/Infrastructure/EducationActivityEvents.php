<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\ActivityResultSink;
use App\Education\Application\HomeworkAttemptLinker;
use App\Education\Application\Scope;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Runs inside the existing finish mutation. Unique event keys make HTTP replays harmless. */
final readonly class EducationActivityEvents implements ActivityResultSink
{
    public function __construct(
        private ConnectionInterface $db,
        private HomeworkAttemptLinker $homework,
        private ParentNotifications $parents,
        private ActivityAwardNotifications $awards,
    ) {}

    public function finished(Scope $scope, string $attemptId): void
    {
        $this->homework->finished($scope, $attemptId);
        $this->parents->activity($scope, (string) $scope->studentId, $attemptId);
        $this->awards->notify($scope, (string) $scope->studentId);
    }

}
