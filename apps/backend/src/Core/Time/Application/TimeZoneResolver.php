<?php

declare(strict_types=1);

namespace App\Core\Time\Application;

use App\Core\Time\Domain\TimeZone;
use InvalidArgumentException;

final readonly class TimeZoneResolver
{
    private TimeZone $defaultTimezone;

    public function __construct(string $defaultTimezone = 'UTC')
    {
        $this->defaultTimezone = new TimeZone($defaultTimezone);
    }

    public function resolve(
        ?string $userTimezone,
        ?string $clientTimezone,
        ?string $geoIpTimezone,
    ): ResolvedTimeZone {
        $user = $this->valid($userTimezone);
        if ($user !== null) {
            return new ResolvedTimeZone($user, ResolvedTimeZone::USER);
        }
        $client = $this->valid($clientTimezone);
        if ($client !== null) {
            return new ResolvedTimeZone($client, ResolvedTimeZone::CLIENT);
        }
        $geoIp = $this->valid($geoIpTimezone);
        if ($geoIp !== null) {
            return new ResolvedTimeZone($geoIp, ResolvedTimeZone::GEOIP);
        }

        return new ResolvedTimeZone($this->defaultTimezone, ResolvedTimeZone::DEFAULT);
    }

    private function valid(?string $candidate): ?TimeZone
    {
        if ($candidate === null || trim($candidate) === '') {
            return null;
        }

        try {
            return new TimeZone($candidate);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
