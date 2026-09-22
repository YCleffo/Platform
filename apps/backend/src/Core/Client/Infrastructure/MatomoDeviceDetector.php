<?php

declare(strict_types=1);

namespace App\Core\Client\Infrastructure;

use App\Core\Client\Application\DeviceDetectorInterface;
use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Domain\DeviceInfo;
use DeviceDetector\ClientHints as MatomoClientHints;
use DeviceDetector\DeviceDetector;

final readonly class MatomoDeviceDetector implements DeviceDetectorInterface
{
    public function detect(?string $userAgent, ClientHints $hints): DeviceInfo
    {
        $detector = new DeviceDetector(
            $userAgent ?? '',
            new MatomoClientHints(
                platform: $this->clean($hints->platform) ?? '',
                mobile: $hints->mobile ?? false,
            ),
        );
        $detector->parse();

        if ($detector->isBot()) {
            return new DeviceInfo(
                browserName: null,
                browserVersion: null,
                osName: null,
                osVersion: null,
                deviceType: 'bot',
                deviceModel: null,
                clientType: 'bot',
                userAgent: $userAgent,
            );
        }

        $deviceType = $this->clean($detector->getDeviceName());
        if ($deviceType === null && $detector->isDesktop()) {
            $deviceType = 'desktop';
        }
        $brand = $this->clean($detector->getBrandName());
        $model = $this->clean($detector->getModel());

        return new DeviceInfo(
            browserName: $this->attribute($detector->getClient('name')),
            browserVersion: $this->attribute($detector->getClient('version')),
            osName: $this->attribute($detector->getOs('name')),
            osVersion: $this->attribute($detector->getOs('version')),
            deviceType: $deviceType ?? ($hints->mobile === true ? 'smartphone' : 'unknown'),
            deviceModel: $this->model($brand, $model),
            clientType: $this->attribute($detector->getClient('type')) ?? 'unknown',
            userAgent: $userAgent,
        );
    }

    private function attribute(array | string | null $value): ?string
    {
        return is_string($value) ? $this->clean($value) : null;
    }

    private function clean(?string $value): ?string
    {
        $clean = trim((string) $value, " \t\n\r\0\x0B\"");
        return $clean === '' || $clean === DeviceDetector::UNKNOWN ? null : $clean;
    }

    private function model(?string $brand, ?string $model): ?string
    {
        if ($brand === null) {
            return $model;
        }
        if ($model === null || str_contains(strtolower($model), strtolower($brand))) {
            return $model ?? $brand;
        }

        return $brand . ' ' . $model;
    }
}
