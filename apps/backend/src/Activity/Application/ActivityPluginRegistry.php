<?php

declare(strict_types=1);

namespace App\Activity\Application;

use App\Activity\Infrastructure\Plugins\AssessmentActivityPlugin;
use App\Activity\Infrastructure\Plugins\LogicActivityPlugin;
use App\Activity\Infrastructure\Plugins\MathSprintActivityPlugin;
use App\Activity\Infrastructure\Plugins\MemoryActivityPlugin;
use App\Activity\Infrastructure\Plugins\TypingActivityPlugin;
use App\Shared\Application\HttpError;

final class ActivityPluginRegistry
{
    /** @var array<string, ActivityPluginInterface> */
    private array $plugins = [];

    public function __construct()
    {
        $this->register(new AssessmentActivityPlugin());
        $this->register(new TypingActivityPlugin());
        $this->register(new MathSprintActivityPlugin());
        $this->register(new LogicActivityPlugin());
        $this->register(new MemoryActivityPlugin());
    }

    public function register(ActivityPluginInterface $plugin): void
    {
        $this->plugins[$plugin->code()] = $plugin;
    }

    public function get(string $code): ActivityPluginInterface
    {
        if (!isset($this->plugins[$code])) {
            throw new HttpError(404, 'unknown_activity_plugin', "Unknown plugin: {$code}");
        }
        return $this->plugins[$code];
    }

    /** @return list<array<string, mixed>> */
    public function describeAll(): array
    {
        $out = [];
        foreach ($this->plugins as $p) {
            $out[] = [
                'code' => $p->code(),
                'version' => $p->version(),
                'title' => $p->title(),
                'category' => $p->category(),
                'capabilities' => $p->capabilities(),
                'configSchema' => $p->configSchema(),
                'defaultConfig' => $p->defaultConfig(),
            ];
        }
        return $out;
    }
}
