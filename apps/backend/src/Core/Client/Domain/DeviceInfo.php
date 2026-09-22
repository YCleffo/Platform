<?php

declare(strict_types=1);

namespace App\Core\Client\Domain;

final readonly class DeviceInfo
{
    public function __construct(
        public ?string $browserName,
        public ?string $browserVersion,
        public ?string $osName,
        public ?string $osVersion,
        public string $deviceType,
        public ?string $deviceModel,
        public string $clientType,
        public ?string $userAgent,
    ) {}
}
