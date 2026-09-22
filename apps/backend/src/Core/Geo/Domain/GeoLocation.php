<?php

declare(strict_types=1);

namespace App\Core\Geo\Domain;

final readonly class GeoLocation
{
    public function __construct(
        public ?string $countryCode,
        public ?string $countryName,
        public ?string $region,
        public ?string $city,
        public ?string $timezone,
    ) {}

    public static function unknown(): self
    {
        return new self(null, null, null, null, null);
    }

    public function isKnown(): bool
    {
        return $this->countryCode !== null
            || $this->region !== null
            || $this->city !== null
            || $this->timezone !== null;
    }
}
