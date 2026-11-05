<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Api\Shared\Uuid;
use DateTimeImmutable;
use DateTimeZone;

final class Input
{
    public static function text(mixed $value, int $maximum, bool $required = true): string
    {
        if (!is_string($value) || mb_strlen($value) > $maximum || ($required && trim($value) === '')
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value) === 1) {
            throw new EducationError(422, 'invalid_input');
        }
        return trim($value);
    }

    public static function uuid(mixed $value): string
    {
        if (!is_string($value) || !Uuid::isValid($value)) {
            throw new EducationError(422, 'invalid_id');
        }
        return strtolower($value);
    }

    public static function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new EducationError(422, 'invalid_date');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new EducationError(422, 'invalid_date');
        }
        return $value;
    }

    public static function instant(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D', $value) !== 1) {
            throw new EducationError(422, 'invalid_time');
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new EducationError(422, 'invalid_time');
        }
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new EducationError(422, 'invalid_time');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param non-empty-string $timezone
     * @return array{0:string,1:string} Exclusive end; calendar arithmetic preserves DST boundaries.
     */
    public static function range(string $from, string $to, string $timezone): array
    {
        self::date($from);
        self::date($to);
        $zone = new DateTimeZone($timezone);
        $start = new DateTimeImmutable($from . ' 00:00:00', $zone);
        $last = new DateTimeImmutable($to . ' 00:00:00', $zone);
        if ($last < $start || $start->diff($last)->days > 30) {
            throw new EducationError(422, 'invalid_range');
        }
        return [$start->format(DATE_ATOM), $last->modify('+1 day')->format(DATE_ATOM)];
    }

    public static function page(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (!is_string($value) || preg_match('/^\d{1,7}$/D', $value) !== 1) {
            throw new EducationError(422, 'invalid_page');
        }
        return (int) $value;
    }
}
