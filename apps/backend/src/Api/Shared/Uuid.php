<?php

declare(strict_types=1);

namespace App\Api\Shared;

final class Uuid
{
    public static function isValid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
            $value,
        ) === 1;
    }
}
