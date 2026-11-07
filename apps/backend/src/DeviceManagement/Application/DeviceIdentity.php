<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

final readonly class DeviceIdentity
{
    public const ATTRIBUTE = self::class;

    public function __construct(
        public string $deviceId,
        public string $credentialId,
        public string $organizationId,
    ) {}
}
