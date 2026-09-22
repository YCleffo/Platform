<?php

declare(strict_types=1);

use App\Core\Client\Application\TrustedProxies;
use App\Core\Client\Application\DeviceDetectorInterface;
use App\Core\Client\Application\FingerprintService;
use App\Core\Client\Infrastructure\MatomoDeviceDetector;

/** @var array<string, mixed> $params */
$client = $params['client'];

return [
    TrustedProxies::class => [
        '__construct()' => [
            'ranges' => $client['trustedProxies'],
        ],
    ],
    DeviceDetectorInterface::class => ['class' => MatomoDeviceDetector::class],
    FingerprintService::class => [
        '__construct()' => [
            'hmacKey' => $client['fingerprintHmacKey'],
        ],
    ],
];
