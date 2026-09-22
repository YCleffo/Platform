<?php

declare(strict_types=1);

use App\Core\Geo\Application\GeoIpProviderInterface;
use App\Core\Geo\Infrastructure\MaxMindGeoIpProvider;

/** @var array<string, mixed> $params */
$geoIp = $params['geoIp'];

return [
    GeoIpProviderInterface::class => [
        'class' => MaxMindGeoIpProvider::class,
        '__construct()' => [
            'databasePath' => $geoIp['databasePath'],
        ],
    ],
];
