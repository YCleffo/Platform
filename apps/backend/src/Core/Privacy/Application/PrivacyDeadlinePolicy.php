<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DateTimeImmutable;
use DomainException;

final readonly class PrivacyDeadlinePolicy
{
    public function __construct(private BusinessCalendarInterface $calendar) {}

    /** @return array{calendarType:string,amount:int,extensionDays:int} */
    public function definition(string $code): array
    {
        return match ($code) {
            'access_response', 'processing_information_response', 'processing_termination' =>
                ['calendarType' => 'working_days', 'amount' => 10, 'extensionDays' => 5],
            'confirmed_correction' => ['calendarType' => 'working_days', 'amount' => 7, 'extensionDays' => 0],
            'unlawful_processing_stop' => ['calendarType' => 'working_days', 'amount' => 3, 'extensionDays' => 0],
            'unlawful_data_destruction' => ['calendarType' => 'working_days', 'amount' => 10, 'extensionDays' => 0],
            default => throw new DomainException('Unknown privacy deadline.'),
        };
    }

    public function dueAt(string $calendarType, int $amount, DateTimeImmutable $triggeredAt): DateTimeImmutable
    {
        if ($amount < 0) {
            throw new DomainException('Invalid deadline duration.');
        }
        return match ($calendarType) {
            'working_days' => $this->calendar->addWorkingDays($triggeredAt, $amount),
            'calendar_days' => $triggeredAt->modify('+' . $amount . ' days'),
            'hours' => $triggeredAt->modify('+' . $amount . ' hours'),
            default => throw new DomainException('Unknown calendar type.'),
        };
    }

    public function extendedDueAt(string $deadlineCode, DateTimeImmutable $originalDueAt): DateTimeImmutable
    {
        $definition = $this->definition($deadlineCode);
        if ($definition['extensionDays'] === 0) {
            throw new DomainException('Extension is unavailable.');
        }
        return $this->calendar->addWorkingDays($originalDueAt, $definition['extensionDays']);
    }

    public function isOverdue(DateTimeImmutable $now, DateTimeImmutable $dueAt, ?DateTimeImmutable $satisfiedAt): bool
    {
        return $satisfiedAt === null && $now > $dueAt;
    }
}
