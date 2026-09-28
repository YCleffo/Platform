<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DateTimeImmutable;

interface BusinessCalendarInterface
{
    public function isWorkingDay(DateTimeImmutable $date): bool;

    public function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable;
}
