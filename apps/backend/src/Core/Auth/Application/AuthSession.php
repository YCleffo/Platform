<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/** @internal Token hashes and session security state are never public API data. */
final readonly class AuthSession
{
    public function __construct(
        public string $id,
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
        public string $createdAt,
        public string $lastSeenAt,
        public string $accessExpiresAt,
        public string $expiresAt,
        public ?string $revokedAt,
        public ?string $revokeReason,
    ) {}
}
