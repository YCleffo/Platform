<?php

declare(strict_types=1);

namespace App\Core\Settings\Infrastructure;

final class PostgresJsonValue
{
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<array-key, mixed> */
    public static function decodeObject(string $json): array
    {
        $value = self::decode($json);
        return is_array($value) ? $value : [];
    }
}
