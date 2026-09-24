<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

final readonly class SubscriptionPeriodCalculator
{
    /** @return array{start: \DateTimeImmutable, end: \DateTimeImmutable} */
    public function calculate(\DateTimeImmutable $activationInstant, string $billingPeriod): array
    {
        $start = $activationInstant->setTimezone(new \DateTimeZone('UTC'));
        $year = (int) $start->format('Y');
        $month = (int) $start->format('n');
        $day = (int) $start->format('j');

        if ($billingPeriod === 'month') {
            ++$month;
            if ($month === 13) {
                $month = 1;
                ++$year;
            }
        } elseif ($billingPeriod === 'year') {
            ++$year;
        } else {
            throw new \DomainException('Unsupported subscription billing period.');
        }

        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))->format('t');
        $end = $start->setDate($year, $month, min($day, $lastDay));

        return ['start' => $start, 'end' => $end];
    }
}
