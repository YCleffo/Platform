<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

final readonly class DataSubjectRequestFilter
{
    public function __construct(
        public ?string $status = null,
        public ?string $requestType = null,
        public ?string $subjectPersonId = null,
        public ?bool $overdue = null,
    ) {}
}
