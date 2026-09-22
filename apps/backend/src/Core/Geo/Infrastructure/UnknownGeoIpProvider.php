<?php

declare(strict_types=1);

namespace App\Core\Geo\Infrastructure;

use App\Core\Geo\Application\GeoIpProviderInterface;
use App\Core\Geo\Domain\GeoLocation;

final readonly class UnknownGeoIpProvider implements GeoIpProviderInterface
{
    public function locate(string $ipAddress): GeoLocation
    {
        return GeoLocation::unknown();
    }
}
