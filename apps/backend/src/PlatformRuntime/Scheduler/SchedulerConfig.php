<?php

declare(strict_types=1);

namespace App\PlatformRuntime\Scheduler;

final class SchedulerConfig
{
    public static function integer(string $name, int $default, int $min = 1, int $max = 1000): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') { return $default; }
        if (!preg_match('/\A[1-9][0-9]*\z/D', $raw) || strlen($raw) > 9
            || (int) $raw < $min || (int) $raw > $max) {
            throw new \InvalidArgumentException('Invalid scheduler setting: ' . $name);
        }
        return (int) $raw;
    }

    public static function enabled(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') { return $default; }
        if (!in_array($raw, ['true', 'false'], true)) {
            throw new \InvalidArgumentException('Invalid scheduler setting: ' . $name);
        }
        return $raw === 'true';
    }
}
