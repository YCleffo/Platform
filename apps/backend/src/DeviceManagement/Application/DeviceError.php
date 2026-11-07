<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

use RuntimeException;

final class DeviceError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
    ) {
        parent::__construct('Device management request rejected.');
    }
}
