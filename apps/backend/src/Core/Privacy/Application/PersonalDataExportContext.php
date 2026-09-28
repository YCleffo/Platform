<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DateTimeImmutable;

final readonly class PersonalDataExportContext
{
    public function __construct(
        public string $subjectPersonId,
        public string $requestId,
        public string $requestedByPersonId,
        public string $requestedByAccountId,
        public ?string $representationId,
        public DateTimeImmutable $generatedAt,
        public ?string $locale = null,
    ) {}
}
