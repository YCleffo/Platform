<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Client;

use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Infrastructure\MatomoDeviceDetector;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringStartsWith;

final class DeviceDetectorTest extends Unit
{
    public function testParsesBrowserOperatingSystemAndDeviceLocally(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/131.0.0.0 Safari/537.36';

        $device = (new MatomoDeviceDetector())->detect(
            $userAgent,
            new ClientHints(null, '"Windows"', false),
        );

        assertSame('Chrome', $device->browserName);
        assertStringStartsWith('131.', (string) $device->browserVersion);
        assertSame('Windows', $device->osName);
        assertSame('desktop', $device->deviceType);
        assertSame('browser', $device->clientType);
        assertSame($userAgent, $device->userAgent);
    }
}
