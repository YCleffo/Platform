<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Education\Activity\ActivityPluginInterface;

final class ActivityHandlerRegistry
{
    /** @var array<string, ActivityHandler> */
    private array $handlers = [];

    /**
     * @param array<string, ActivityHandler> $handlers
     */
    public function __construct(array $handlers = [])
    {
        $this->handlers = [
            'platform-typing' => new TypingActivityHandler(),
            'platform-logic' => new LogicActivityHandler(),
            'platform-math-sprint' => new MathSprintActivityHandler(),
            'platform-assessment' => new AssessmentActivityHandler(),
        ];
        foreach ($handlers as $code => $handler) {
            $this->register((string) $code, $handler);
        }
    }

    public function register(string $code, ActivityHandler $handler): self
    {
        $this->handlers[$code] = $handler;
        return $this;
    }

    public function has(string $code): bool
    {
        return isset($this->handlers[$code]);
    }

    public function handler(string $code): ActivityHandler
    {
        return $this->handlers[$code] ?? throw new EducationError(422, 'activity_unsupported');
    }

    /** @return list<array{code:string,title:string,description:string,version:string,schema?:array,defaultConfig?:array}> */
    public function plugins(): array
    {
        $list = [];
        foreach ($this->handlers as $code => $h) {
            if ($h instanceof ActivityPluginInterface) {
                $list[] = [
                    'code' => $h->code(),
                    'title' => $h->title(),
                    'description' => $h->description(),
                    'version' => $h->version(),
                    'schema' => $h->configSchema(),
                    'defaultConfig' => $h->defaultConfig(),
                ];
            } else {
                $list[] = [
                    'code' => $code,
                    'title' => $code,
                    'description' => '',
                    'version' => '1.0.0',
                ];
            }
        }
        return $list;
    }

    public function schema(string $code): array
    {
        $handler = $this->handler($code);
        return $handler instanceof ActivityPluginInterface ? $handler->configSchema() : [];
    }

    public function defaultConfig(string $code): array
    {
        $handler = $this->handler($code);
        return $handler instanceof ActivityPluginInterface ? $handler->defaultConfig() : [];
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
