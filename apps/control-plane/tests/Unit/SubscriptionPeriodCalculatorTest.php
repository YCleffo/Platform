<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionPeriodCalculatorTest extends TestCase
{
    #[DataProvider('periods')]
    public function testCalendarPeriodsClampAndPreserveUtc(string $start, string $period, string $expectedEnd): void
    {
        $result = (new SubscriptionPeriodCalculator())->calculate(new \DateTimeImmutable($start), $period);

        self::assertSame((new \DateTimeImmutable($start))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'), $result['start']->format('Y-m-d H:i:sP'));
        self::assertSame($expectedEnd, $result['end']->format('Y-m-d H:i:sP'));
        self::assertSame('UTC', $result['start']->getTimezone()->getName());
        self::assertSame('UTC', $result['end']->getTimezone()->getName());
        self::assertGreaterThan($result['start'], $result['end']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function periods(): iterable
    {
        yield 'normal month' => ['2026-05-15 10:30:00+00:00', 'month', '2026-06-15 10:30:00+00:00'];
        yield 'normal year' => ['2026-05-15 10:30:00+00:00', 'year', '2027-05-15 10:30:00+00:00'];
        yield 'non leap January end' => ['2026-01-31 12:00:00+00:00', 'month', '2026-02-28 12:00:00+00:00'];
        yield 'leap January end' => ['2028-01-31 12:00:00+00:00', 'month', '2028-02-29 12:00:00+00:00'];
        yield 'leap day year' => ['2028-02-29 12:00:00+00:00', 'year', '2029-02-28 12:00:00+00:00'];
        yield 'December rollover' => ['2026-12-20 23:59:59+00:00', 'month', '2027-01-20 23:59:59+00:00'];
        yield 'non UTC input normalized' => ['2026-05-15 15:30:00+05:00', 'month', '2026-06-15 10:30:00+00:00'];
    }
}
