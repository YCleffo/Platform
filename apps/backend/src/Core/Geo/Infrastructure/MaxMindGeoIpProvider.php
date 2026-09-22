<?php

declare(strict_types=1);

namespace App\Core\Geo\Infrastructure;

use App\Core\Geo\Application\GeoIpProviderInterface;
use App\Core\Geo\Domain\GeoLocation;
use App\Core\Time\Domain\TimeZone;
use GeoIp2\Database\Reader;
use InvalidArgumentException;
use Throwable;

final class MaxMindGeoIpProvider implements GeoIpProviderInterface
{
    private ?Reader $reader = null;

    public function __construct(?string $databasePath)
    {
        if ($databasePath === null || !is_file($databasePath) || !is_readable($databasePath)) {
            return;
        }

        try {
            $this->reader = new Reader($databasePath);
        } catch (Throwable) {
            $this->reader = null;
        }
    }

    public function locate(string $ipAddress): GeoLocation
    {
        if ($this->reader === null) {
            return GeoLocation::unknown();
        }

        try {
            $record = $this->reader->city($ipAddress);
            $timezone = $record->location->timeZone;
            if ($timezone !== null) {
                try {
                    $timezone = (new TimeZone($timezone))->identifier;
                } catch (InvalidArgumentException) {
                    $timezone = null;
                }
            }

            return new GeoLocation(
                countryCode: $this->clean($record->country->isoCode, true),
                countryName: $this->clean($record->country->name),
                region: $this->clean($record->mostSpecificSubdivision->name),
                city: $this->clean($record->city->name),
                timezone: $timezone,
            );
        } catch (Throwable) {
            return GeoLocation::unknown();
        }
    }

    private function clean(?string $value, bool $uppercase = false): ?string
    {
        $clean = trim((string) $value);
        if ($clean === '') {
            return null;
        }

        return $uppercase ? strtoupper($clean) : $clean;
    }
}
