<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Billing\Domain\ProrationCalculator;
use PHPUnit\Framework\TestCase;

final class ProrationCalculatorTest extends TestCase
{
    public function testMidPeriodUpgradeAndBoundaryAmountsAreReproducible(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $end = new \DateTimeImmutable('2026-01-31T00:00:00Z');
        $change = new \DateTimeImmutable('2026-01-16T00:00:00Z');
        $calculator = new ProrationCalculator();
        $result = $calculator->calculate($start, $end, $change, 100000, 180000, 1, 1, 'RUB');

        self::assertSame(1, $result['calculation_rule_version']);
        self::assertSame('half_up_each_component', $result['rounding_rule']);
        self::assertSame(50000, $result['credit_minor']);
        self::assertSame(90000, $result['charge_minor']);
        self::assertSame(40000, $result['net_minor']);
        self::assertSame($result, $calculator->calculate($start, $end, $change, 100000, 180000, 1, 1, 'RUB'));

        $atStart = $calculator->calculate($start, $end, $start, 100000, 180000, 1, 1, 'RUB');
        self::assertSame(80000, $atStart['net_minor']);
        $atEnd = $calculator->calculate($start, $end, $end, 100000, 180000, 1, 1, 'RUB');
        self::assertSame(0, $atEnd['net_minor']);
    }

    public function testRoundHalfUpEachSideAndPreserveNegativeNet(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $end = new \DateTimeImmutable('2026-01-01T00:00:00.000002Z');
        $mid = new \DateTimeImmutable('2026-01-01T00:00:00.000001Z');
        $result = (new ProrationCalculator())->calculate($start, $end, $mid, 3, 1, 1, 1, 'RUB');

        self::assertSame(2, $result['credit_minor']);
        self::assertSame(1, $result['charge_minor']);
        self::assertSame(-1, $result['net_minor']);
    }

    public function testLargeMoneyDoesNotUseOverflowingIntermediateProduct(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $end = new \DateTimeImmutable('2027-01-01T00:00:00Z');
        $half = new \DateTimeImmutable('2026-07-02T12:00:00Z');
        $result = (new ProrationCalculator())->calculate($start, $end, $half, PHP_INT_MAX, 0, 1, 1, 'RUB');

        self::assertSame(intdiv(PHP_INT_MAX, 2) + 1, $result['credit_minor']);
        self::assertSame(-$result['credit_minor'], $result['net_minor']);
    }

    public function testInvalidPeriodAndOverflowFailClosed(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $end = new \DateTimeImmutable('2026-02-01T00:00:00Z');
        $calculator = new ProrationCalculator();

        foreach ([
            [$end, $start, $start, 1, 2, 1, 1, 'RUB'],
            [$start, $end, new \DateTimeImmutable('2025-12-31T23:59:59Z'), 1, 2, 1, 1, 'RUB'],
            [$start, $end, $start, PHP_INT_MAX, 2, 2, 1, 'RUB'],
            [$start, $end, $start, 1, 2, 1, 1, 'rub'],
            [$start, $end, new \DateTimeImmutable('2300-01-01T00:00:00Z'), 1, 2, 1, 1, 'RUB'],
        ] as $input) {
            try {
                $calculator->calculate(...$input);
                self::fail('Invalid proration input was accepted.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
