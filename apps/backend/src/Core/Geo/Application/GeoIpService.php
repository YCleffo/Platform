<?php

declare(strict_types=1);

namespace App\Core\Geo\Application;

use App\Core\Geo\Domain\GeoLocation;
use App\Core\Network\IpRangeMatcher;
use Throwable;

final readonly class GeoIpService
{
    private const NON_PUBLIC_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        '2001:db8::/32',
        'ff00::/8',
    ];

    public function __construct(private GeoIpProviderInterface $provider) {}

    public function locate(?string $ipAddress): GeoLocation
    {
        if (
            $ipAddress === null
            || filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            || $this->isNonPublic($ipAddress)
        ) {
            return GeoLocation::unknown();
        }

        try {
            return $this->provider->locate($ipAddress);
        } catch (Throwable) {
            return GeoLocation::unknown();
        }
    }

    private function isNonPublic(string $ipAddress): bool
    {
        foreach (self::NON_PUBLIC_RANGES as $range) {
            if (IpRangeMatcher::matches($ipAddress, $range)) {
                return true;
            }
        }

        return false;
    }
}
