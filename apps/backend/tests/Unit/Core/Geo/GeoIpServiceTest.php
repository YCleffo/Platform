<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Geo;

use App\Core\Geo\Application\GeoIpProviderInterface;
use App\Core\Geo\Application\GeoIpService;
use App\Core\Geo\Domain\GeoLocation;
use App\Core\Geo\Infrastructure\MaxMindGeoIpProvider;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;

final class GeoIpServiceTest extends Unit
{
    public function testPrivateReservedLoopbackAndInvalidAddressesAreUnknown(): void
    {
        $provider = new CountingGeoIpProvider();
        $service = new GeoIpService($provider);

        assertFalse($service->locate('127.0.0.1')->isKnown());
        assertFalse($service->locate('10.0.0.1')->isKnown());
        assertFalse($service->locate('2001:db8::1')->isKnown());
        assertFalse($service->locate('not-an-ip')->isKnown());
        assertSame(0, $provider->calls);
    }

    public function testMissingDatabaseIsGracefulUnknownFallback(): void
    {
        $service = new GeoIpService(new MaxMindGeoIpProvider('/missing/GeoLite2-City.mmdb'));

        assertFalse($service->locate('8.8.8.8')->isKnown());
    }
}

final class CountingGeoIpProvider implements GeoIpProviderInterface
{
    public int $calls = 0;

    public function locate(string $ipAddress): GeoLocation
    {
        ++$this->calls;
        return new GeoLocation('US', 'United States', null, null, 'America/New_York');
    }
}
