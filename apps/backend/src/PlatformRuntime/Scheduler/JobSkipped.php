<?php

declare(strict_types=1);

namespace App\PlatformRuntime\Scheduler;

final class JobSkipped extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode) { parent::__construct($reasonCode); }
}
