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
            'platform-math-sprint' => new MathSprintActivityHandler(),
            default => throw new EducationError(422, 'activity_unsupported'),
        };
    }

    /** @return list<array{code:string,title:string,description:string,version:string}> */
    public function plugins(): array
    {
        $handlers = [
            new TypingActivityHandler(),
            new LogicActivityHandler(),
            new MathSprintActivityHandler(),
        ];
        $list = [];
        foreach ($handlers as $h) {
            if ($h instanceof \App\Education\Activity\ActivityPluginInterface) {
                $list[] = [
                    'code' => $h->code(),
                    'title' => $h->title(),
                    'description' => $h->description(),
                    'version' => $h->version(),
                ];
            }
        }
        return $list;
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
