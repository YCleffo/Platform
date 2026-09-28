<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

final class PrivacyRequestInput
{
    /** @param mixed $input @param list<string> $keys @psalm-assert-if-true array<string, mixed> $input */
    public static function keys(mixed $input, array $keys): bool
    {
        return is_array($input) && array_is_list($input) === false && count($input) <= count($keys)
            && array_diff(array_keys($input), $keys) === [];
    }

    public static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $value) === 1;
    }

    public static function code(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 120
            && preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $value) === 1;
    }

    public static function timestamp(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 40
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1
            && strtotime($value) !== false;
    }
}
