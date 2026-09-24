<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Domain;

final class Money
{
    public static function nonNegativeBigInt(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new \DomainException('Money amount must be nonnegative.');
            }

            return $value;
        }
        if (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            throw new \DomainException('Money amount is not a nonnegative integer.');
        }
        $canonical = ltrim($value, '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($canonical) > strlen($maximum) || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) > 0)) {
            throw new \DomainException('Money amount exceeds the supported bigint range.');
        }

        return (int) $canonical;
    }

    public static function multiply(int $unitAmount, int $quantity): int
    {
        if ($unitAmount < 0 || $quantity <= 0) {
            throw new \DomainException('Invoice line amount or quantity is invalid.');
        }
        if ($unitAmount !== 0 && $unitAmount > intdiv(PHP_INT_MAX, $quantity)) {
            throw new \DomainException('Invoice line total exceeds the supported bigint range.');
        }

        return $unitAmount * $quantity;
    }

    public static function add(int $total, int $lineTotal): int
    {
        if ($total < 0 || $lineTotal < 0 || $total > PHP_INT_MAX - $lineTotal) {
            throw new \DomainException('Invoice total exceeds the supported bigint range.');
        }

        return $total + $lineTotal;
    }
}
