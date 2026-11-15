<?php

declare(strict_types=1);

namespace App\Education\Application;

/** Compatibility for saved links and clients from installations before migration 016. */
final class ActivityCode
{
    public static function canonical(string $code): string
    {
        return match ($code) {
            'kiberone-typing' => 'platform-typing',
            'kiberone-logic' => 'platform-logic',
            default => $code,
        };
    }
}
