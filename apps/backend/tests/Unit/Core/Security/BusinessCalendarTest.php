<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\BusinessCalendarCoverageException;
use App\Core\Privacy\Infrastructure\ConfiguredBusinessCalendar;
use Codeception\Test\Unit;
use DateTimeImmutable;

final class BusinessCalendarTest extends Unit
{
    public function testOverridesAndCrossYear(): void
    {
        $calendar = new ConfiguredBusinessCalendar('2026-12-01', '2027-01-31', [6, 7],
            ['2026-12-31', '2027-01-01'], ['2027-01-02']);
        self::assertFalse($calendar->isWorkingDay(new DateTimeImmutable('2026-12-31T12:00:00Z')));
        self::assertTrue($calendar->isWorkingDay(new DateTimeImmutable('2027-01-02T12:00:00Z')));
        self::assertSame('2027-01-04', $calendar->addWorkingDays(new DateTimeImmutable('2026-12-30T12:00:00Z'), 2)->format('Y-m-d'));
    }

    public function testCoverageFailsClosed(): void
    {
        $calendar = new ConfiguredBusinessCalendar('2026-01-01', '2026-01-02');
        $this->expectException(BusinessCalendarCoverageException::class);
        $calendar->addWorkingDays(new DateTimeImmutable('2026-01-02T12:00:00Z'), 1);
    }
}
