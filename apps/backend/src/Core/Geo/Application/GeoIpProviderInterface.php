<?php

declare(strict_types=1);

namespace App\Core\Geo\Application;

use App\Core\Geo\Domain\GeoLocation;

interface GeoIpProviderInterface
{
    public function locate(string $ipAddress): GeoLocation;
}
