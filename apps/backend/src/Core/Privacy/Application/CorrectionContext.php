<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

final readonly class CorrectionContext
{
    public function __construct(public string $requestId, public string $subjectPersonId) {}
}
