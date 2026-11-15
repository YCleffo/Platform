<?php

declare(strict_types=1);

namespace App\Education\Application;

/** Observes a result already validated and persisted by the existing engine. */
interface ActivityResultSink
{
    public function finished(Scope $scope, string $attemptId): void;
}
