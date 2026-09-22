<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class TouchSessionCommand
{
    public function __construct(
        public string $sessionId,
        public string $accountId,
        public string $touchBefore,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $browserName,
        public ?string $browserVersion,
        public ?string $osName,
        public ?string $osVersion,
        public ?string $deviceType,
        public ?string $deviceModel,
        public ?string $clientLocale,
        public ?string $clientTimezone,
        public ?string $clientInstanceId,
        public ?string $deviceFingerprintHashHex,
        public ?string $geoCountryCode,
        public ?string $geoRegion,
        public ?string $geoCity,
        public ?string $geoTimezone,
    ) {}
}
