<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class OwnSessionView
{
    public function __construct(
        public string $id,
        public bool $current,
        public string $clientType,
        public ?string $deviceName,
        public ?string $browserName,
        public ?string $browserVersion,
        public ?string $osName,
        public ?string $osVersion,
        public ?string $deviceType,
        public ?string $deviceModel,
        public ?string $ipAddress,
        public ?string $clientLocale,
        public ?string $clientTimezone,
        public ?string $geoCountryCode,
        public ?string $geoRegion,
        public ?string $geoCity,
        public ?string $geoTimezone,
        public string $createdAt,
        public string $lastSeenAt,
        public string $expiresAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'current' => $this->current,
            'clientType' => $this->clientType,
            'deviceName' => $this->deviceName,
            'browser' => [
                'name' => $this->browserName,
                'version' => $this->browserVersion,
            ],
            'os' => [
                'name' => $this->osName,
                'version' => $this->osVersion,
            ],
            'device' => [
                'type' => $this->deviceType,
                'model' => $this->deviceModel,
            ],
            'ipAddress' => $this->ipAddress,
            'locale' => $this->clientLocale,
            'timezone' => $this->clientTimezone,
            'approximateLocation' => [
                'countryCode' => $this->geoCountryCode,
                'region' => $this->geoRegion,
                'city' => $this->geoCity,
                'timezone' => $this->geoTimezone,
                'approximate' => true,
            ],
            'createdAt' => $this->createdAt,
            'lastSeenAt' => $this->lastSeenAt,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
