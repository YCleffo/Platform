<?php

declare(strict_types=1);

namespace App\Education\Application;

final class ActivityHandlerRegistry
{
    public function handler(string $code): ActivityHandler
    {
        return match ($code) {
            'platform-typing' => new TypingActivityHandler(),
            'platform-logic' => new LogicActivityHandler(),
            default => throw new EducationError(422, 'activity_unsupported'),
        };
    }

    /** Public configuration contains no server-only exercise/answer fields. */
    public function validateConfig(string $code, array $config): array
    {
        return $this->handler($code)->validateConfig($config);
    }

    public static function only(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys) !== []) {
            throw new EducationError(422, 'invalid_input');
        }
    }

    public static function integer(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new EducationError(422, 'invalid_input');
        }
        return $value;
    }

    public static function json(mixed $value): array
    {
        return is_array($value) ? $value : (is_string($value) ? (json_decode($value, true, flags: JSON_THROW_ON_ERROR) ?: []) : []);
    }
}
