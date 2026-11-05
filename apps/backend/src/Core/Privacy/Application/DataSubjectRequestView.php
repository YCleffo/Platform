<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DateTimeImmutable;

final readonly class DataSubjectRequestView
{
    /** @param list<DataSubjectRequestDeadlineView> $deadlines */
    public function __construct(
        public string $id,
        public string $subjectPersonId,
        public string $requestType,
        public string $status,
        public DateTimeImmutable $submittedAt,
        public ?DateTimeImmutable $completedAt,
        public ?string $resolutionCode,
        public array $deadlines,
        public ?string $representationId = null,
        public ?string $intakeChannel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subjectPersonId' => $this->subjectPersonId,
            'requestType' => $this->requestType,
            'status' => $this->status,
            'submittedAt' => $this->submittedAt->format(DATE_ATOM),
            'completedAt' => $this->completedAt?->format(DATE_ATOM),
            'resolutionCode' => $this->resolutionCode,
            'representationId' => $this->representationId,
            'intakeChannel' => $this->intakeChannel,
            'deadlines' => array_map(static fn(DataSubjectRequestDeadlineView $deadline): array => $deadline->toArray(), $this->deadlines),
        ];
    }
}
