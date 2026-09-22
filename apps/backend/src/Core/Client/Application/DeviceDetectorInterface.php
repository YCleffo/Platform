<?php

declare(strict_types=1);

namespace App\Core\Client\Application;

use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Domain\DeviceInfo;

interface DeviceDetectorInterface
{
    public function detect(?string $userAgent, ClientHints $hints): DeviceInfo;
}
