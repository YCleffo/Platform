<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Domain;

/** Deterministic minor-unit proration for one recurring service period. */
final readonly class ProrationCalculator
{
    public const RULE_VERSION = 1;
    public const ROUNDING_RULE = 'half_up_each_component';

    /**
     * @return array{calculation_rule_version: int, rounding_rule: string, period_start: string, period_end: string,
     *     change_at: string, old_price_minor: int, new_price_minor: int, old_quantity: int, new_quantity: int,
     *     credit_minor: int, charge_minor: int, net_minor: int, currency: string}
     */
    public function calculate(
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        \DateTimeImmutable $changeAt,
        int $oldPriceMinor,
        int $newPriceMinor,
        int $oldQuantity,
        int $newQuantity,
        string $currency,
    ): array {
        if ($oldPriceMinor < 0 || $newPriceMinor < 0 || $oldQuantity <= 0 || $newQuantity <= 0
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new \DomainException('Invalid proration money or quantity.');
        }
        $start = $this->micros($periodStart);
        $end = $this->micros($periodEnd);
        $change = $this->micros($changeAt);
        if ($end <= $start || $change < $start || $change > $end) {
            throw new \DomainException('Proration change must lie within a valid period.');
        }
        $duration = $end - $start;
        // Current catalog supports monthly/yearly periods. Keep intermediate
        // products bounded on every supported 64-bit PHP runtime.
        if ($duration > 400 * 86400 * 1_000_000) {
            throw new \DomainException('Proration period exceeds one billing year.');
        }
        $remaining = $end - $change;
        $credit = $this->portion(Money::multiply($oldPriceMinor, $oldQuantity), $remaining, $duration);
        $charge = $this->portion(Money::multiply($newPriceMinor, $newQuantity), $remaining, $duration);
        $utc = new \DateTimeZone('UTC');

        return [
            'calculation_rule_version' => self::RULE_VERSION,
            'rounding_rule' => self::ROUNDING_RULE,
            'period_start' => $periodStart->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'period_end' => $periodEnd->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'change_at' => $changeAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'old_price_minor' => $oldPriceMinor,
            'new_price_minor' => $newPriceMinor,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'credit_minor' => $credit,
            'charge_minor' => $charge,
            'net_minor' => $charge - $credit,
            'currency' => $currency,
        ];
    }

    private function micros(\DateTimeImmutable $instant): int
    {
        $seconds = (int) $instant->format('U');
        if ($seconds < 0 || $seconds > intdiv(PHP_INT_MAX - 999_999, 1_000_000)) {
            throw new \DomainException('Proration instant is outside the supported range.');
        }

        return $seconds * 1_000_000 + (int) $instant->format('u');
    }

    /** Round amount * remaining / duration half up without overflowing or using float. */
    private function portion(int $amount, int $remaining, int $duration): int
    {
        if ($amount === 0 || $remaining === 0) {
            return 0;
        }
        $whole = intdiv($amount, $duration) * $remaining;
        $remainder = $amount % $duration;
        $fraction = 0;
        $modulus = 0;
        for ($bit = PHP_INT_SIZE * 8 - 2; $bit >= 0; --$bit) {
            $fraction *= 2;
            $modulus *= 2;
            if (($remaining & (1 << $bit)) !== 0) {
                $modulus += $remainder;
            }
            while ($modulus >= $duration) {
                $modulus -= $duration;
                ++$fraction;
            }
        }
        $rounded = Money::add($whole, $fraction);

        return $modulus >= $duration - $modulus ? Money::add($rounded, 1) : $rounded;
    }
}
