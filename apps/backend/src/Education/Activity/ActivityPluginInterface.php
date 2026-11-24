<?php

declare(strict_types=1);

namespace App\Education\Activity;

use App\Education\Application\ActivityHandler;

interface ActivityPluginInterface extends ActivityHandler
{
    public function code(): string;
    public function version(): string;
    public function title(): string;
    public function description(): string;
    /** @return array<string,mixed> */
    public function configSchema(): array;
    /** @return array<string,mixed> */
    public function defaultConfig(): array;
}
