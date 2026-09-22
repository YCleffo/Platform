<?php

declare(strict_types=1);

namespace App\Core\Client\Application;

use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Domain\DeviceInfo;

final readonly class FingerprintService
{
    public function __construct(#[\SensitiveParameter] private ?string $hmacKey) {}

    public function hashHex(
        DeviceInfo $device,
        ClientHints $hints,
        ?string $clientInstanceId,
    ): ?string {
        if ($this->hmacKey === null || $this->hmacKey === '') {
            return null;
        }

        $signals = [
            'browser' => $this->normalized($device->browserName),
            'browserMajor' => $this->major($device->browserVersion),
            'os' => $this->normalized($device->osName),
            'osMajor' => $this->major($device->osVersion),
            'deviceType' => $this->normalized($device->deviceType),
            'deviceModel' => $this->normalized($device->deviceModel),
            'clientType' => $this->normalized($device->clientType),
            'hintPlatform' => $this->normalized($hints->platform),
            'hintMobile' => $hints->mobile,
            'clientInstanceId' => strtolower((string) $clientInstanceId),
        ];

        return hash_hmac(
            'sha256',
            json_encode($signals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $this->hmacKey,
        );
    }

    private function normalized(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function major(?string $version): string
    {
        $value = trim((string) $version);
        return explode('.', $value, 2)[0];
    }
}
