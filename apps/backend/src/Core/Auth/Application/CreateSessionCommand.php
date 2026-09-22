<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class CreateSessionCommand
{
    public function __construct(
        public string $accountId,
        public string $sessionTokenHashHex,
        public ?string $refreshTokenHashHex,
        public string $securityStamp,
        public string $authenticationMethod,
        public ?string $mfaVerifiedAt,
        public string $clientType,
        public ?string $deviceName,
        public ?string $ipAddress,
        public ?string $userAgent,
        public string $accessExpiresAt,
        public string $expiresAt,
        public ?string $browserName = null,
        public ?string $browserVersion = null,
        public ?string $osName = null,
        public ?string $osVersion = null,
        public ?string $deviceType = null,
        public ?string $deviceModel = null,
        public ?string $clientLocale = null,
        public ?string $clientTimezone = null,
        public ?string $clientInstanceId = null,
        public ?string $deviceFingerprintHashHex = null,
        public ?string $geoCountryCode = null,
        public ?string $geoRegion = null,
        public ?string $geoCity = null,
        public ?string $geoTimezone = null,
    ) {}
}
