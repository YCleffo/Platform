<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DateTimeImmutable;

final readonly class DataSubjectRequestDeadlineView
{
    public function __construct(
        public string $code,
        public DateTimeImmutable $triggeredAt,
        public string $calendarType,
        public DateTimeImmutable $dueAt,
        public ?DateTimeImmutable $extendedDueAt,
        public ?DateTimeImmutable $satisfiedAt,
        public bool $overdue,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'triggeredAt' => $this->triggeredAt->format(DATE_ATOM),
            'calendarType' => $this->calendarType,
            'dueAt' => $this->dueAt->format(DATE_ATOM),
            'extendedDueAt' => $this->extendedDueAt?->format(DATE_ATOM),
            'effectiveDueAt' => ($this->extendedDueAt ?? $this->dueAt)->format(DATE_ATOM),
            'satisfiedAt' => $this->satisfiedAt?->format(DATE_ATOM),
            'overdue' => $this->overdue,
        ];
    }
}
