<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Client;

use App\Core\Client\Application\FingerprintService;
use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Domain\DeviceInfo;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class FingerprintServiceTest extends Unit
{
    public function testIsStableAcrossMinorBrowserUpdatesButChangesMeaningfully(): void
    {
        $service = new FingerprintService('unit-test-secret-key');
        $hints = new ClientHints(null, 'Windows', false);
        $first = $service->hashHex($this->device('131.0.1', 'Windows'), $hints, null);
        $minorUpdate = $service->hashHex($this->device('131.0.9', 'Windows'), $hints, null);
        $osChange = $service->hashHex($this->device('131.0.9', 'GNU/Linux'), $hints, null);

        assertSame($first, $minorUpdate);
        assertNotSame($first, $osChange);
    }

    public function testIsDisabledWithoutConfiguredHmacKey(): void
    {
        assertNull((new FingerprintService(null))->hashHex(
            $this->device('131.0.1', 'Windows'),
            new ClientHints(null, null, null),
            null,
        ));
    }

    private function device(string $browserVersion, string $osName): DeviceInfo
    {
        return new DeviceInfo(
            browserName: 'Chrome',
            browserVersion: $browserVersion,
            osName: $osName,
            osVersion: '11.0',
            deviceType: 'desktop',
            deviceModel: null,
            clientType: 'browser',
            userAgent: 'not included in fingerprint',
        );
    }
}
