<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

final readonly class PersonalDataExportResult
{
    /** @param list<PersonalDataSection> $sections */
    public function __construct(
        public string $schemaVersion,
        public string $generatedAt,
        public string $subjectPersonId,
        public array $sections,
        public string $json,
        public string $sha256,
        public int $sizeBytes,
    ) {}
}
