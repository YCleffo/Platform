<?php

declare(strict_types=1);

namespace ControlPlane\Usage\Domain;

final class Quota
{
    public static function nonNegativeBigInt(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new \DomainException('Usage quantity must be nonnegative.');
            }

            return $value;
        }
        if (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            throw new \DomainException('Usage quantity is not a nonnegative integer.');
        }
        $canonical = ltrim($value, '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($canonical) > strlen($maximum) || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) > 0)) {
            throw new \DomainException('Usage quantity exceeds the supported bigint range.');
        }

        return (int) $canonical;
    }

    public static function add(int $current, int $delta): int
    {
        if ($current < 0) {
            throw new \LogicException('Stored usage quantity is invalid.');
        }
        if ($delta > 0 && $current > PHP_INT_MAX - $delta) {
            throw new \DomainException('Usage delta exceeds the supported bigint range.');
        }
        if ($delta < -$current) {
            throw new \DomainException('Usage quantity cannot become negative.');
        }

        return $current + $delta;
    }
}
