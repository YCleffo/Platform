<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use App\Core\Privacy\Infrastructure\ConfiguredBusinessCalendar;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DomainException;

final class PrivacyDeadlinePolicyTest extends Unit
{
    public function testWorkingCalendarAndHoursDiffer(): void
    {
        $policy = new PrivacyDeadlinePolicy(new ConfiguredBusinessCalendar('2026-01-01', '2026-12-31', [6, 7], ['2026-09-28']));
        $trigger = new DateTimeImmutable('2026-09-25T10:00:00Z');
        self::assertSame('2026-10-12', $policy->dueAt('working_days', 10, $trigger)->format('Y-m-d'));
        self::assertSame('2026-10-05', $policy->dueAt('calendar_days', 10, $trigger)->format('Y-m-d'));
        self::assertSame('2026-09-25T13:00:00+00:00', $policy->dueAt('hours', 3, $trigger)->format(DATE_ATOM));
        $due = $policy->dueAt('working_days', 10, $trigger);
        self::assertSame('2026-10-19', $policy->extendedDueAt('access_response', $due)->format('Y-m-d'));
        self::assertTrue($policy->isOverdue($due->modify('+1 second'), $due, null));
        self::assertFalse($policy->isOverdue($due->modify('+1 second'), $due, $due));
    }

    public function testUnsupportedExtensionRejected(): void
    {
        $policy = new PrivacyDeadlinePolicy(new ConfiguredBusinessCalendar('2026-01-01', '2026-12-31'));
        $this->expectException(DomainException::class);
        $policy->extendedDueAt('confirmed_correction', new DateTimeImmutable('2026-09-25T10:00:00Z'));
    }
}
