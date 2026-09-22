<?php

declare(strict_types=1);

namespace App\Core\Time\Application;

use App\Core\Internationalization\Domain\Locale;
use App\Core\Time\Domain\TimeZone;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use RuntimeException;

final readonly class DateTimeFormatter
{
    public function rfc3339Utc(DateTimeInterface | string $value): string
    {
        return $this->immutable($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    public function inTimeZone(DateTimeInterface | string $value, TimeZone $timezone): DateTimeImmutable
    {
        return $this->immutable($value)->setTimezone($timezone->native());
    }

    public function display(
        DateTimeInterface | string $value,
        TimeZone $timezone,
        Locale $locale,
        ?string $pattern = null,
    ): string {
        $formatter = new IntlDateFormatter(
            $locale->tag,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::MEDIUM,
            $timezone->identifier,
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );
        $result = $formatter->format($this->immutable($value));
        if ($result === false) {
            throw new RuntimeException('Date and time could not be formatted.');
        }

        return $result;
    }

    private function immutable(DateTimeInterface | string $value): DateTimeImmutable
    {
        return is_string($value)
            ? new DateTimeImmutable($value)
            : DateTimeImmutable::createFromInterface($value);
    }
}
