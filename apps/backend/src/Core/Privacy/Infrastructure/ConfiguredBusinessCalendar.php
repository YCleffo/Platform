<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\BusinessCalendarCoverageException;
use App\Core\Privacy\Application\BusinessCalendarInterface;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ConfiguredBusinessCalendar implements BusinessCalendarInterface
{
    /** @param list<int> $weekends @param list<string> $nonWorkingDates @param list<string> $workingOverrideDates */
    public function __construct(
        private string $coverageFrom,
        private string $coverageUntil,
        private array $weekends = [6, 7],
        private array $nonWorkingDates = [],
        private array $workingOverrideDates = [],
        private ?string $sourceReference = null,
        private ?string $verifiedAt = null,
    ) {
        if ($coverageFrom > $coverageUntil || $weekends === [] || array_diff($weekends, range(1, 7)) !== []
            || count($weekends) !== count(array_unique($weekends))) {
            throw new InvalidArgumentException('Invalid business calendar configuration.');
        }
        foreach ([$coverageFrom, $coverageUntil, ...$nonWorkingDates, ...$workingOverrideDates] as $date) {
            if (!is_string($date) || !self::validDate($date)) {
                throw new InvalidArgumentException('Invalid business calendar date.');
            }
        }
        if (array_intersect($nonWorkingDates, $workingOverrideDates) !== []) {
            throw new InvalidArgumentException('Conflicting business calendar override.');
        }
    }

    public function coversCurrentPeriod(DateTimeImmutable $now): bool
    {
        try {
            $verified = $this->verifiedAt === null ? null : new DateTimeImmutable($this->verifiedAt);
            return $this->coverageFrom <= $now->format('Y-m-d')
                && $this->coverageUntil >= $now->modify('+31 days')->format('Y-m-d')
                && is_string($this->sourceReference) && trim($this->sourceReference) !== ''
                && $verified !== null && $verified <= $now;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isWorkingDay(DateTimeImmutable $date): bool
    {
        $key = $date->format('Y-m-d');
        if ($key < $this->coverageFrom || $key > $this->coverageUntil) {
            throw new BusinessCalendarCoverageException('Business calendar coverage is unavailable.');
        }
        if (in_array($key, $this->workingOverrideDates, true)) {
            return true;
        }
        return !in_array($key, $this->nonWorkingDates, true)
            && !in_array((int) $date->format('N'), $this->weekends, true);
    }

    public function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable
    {
        if ($days < 0) {
            throw new InvalidArgumentException('Working days cannot be negative.');
        }
        $this->isWorkingDay($from);
        $date = $from;
        while ($days > 0) {
            $date = $date->modify('+1 day');
            if ($this->isWorkingDay($date)) {
                --$days;
            }
        }
        return $date;
    }

    private static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
