<?php

declare(strict_types=1);

namespace App\Core\Security\Domain;

use DateTimeImmutable;

final readonly class SessionRiskInput
{
    public function __construct(
        public ?string $previousFingerprintHash,
        public ?string $currentFingerprintHash,
        public ?string $previousClientInstanceId,
        public ?string $currentClientInstanceId,
        public ?string $previousBrowserName,
        public ?string $currentBrowserName,
        public ?string $previousOsName,
        public ?string $currentOsName,
        public ?string $previousCountryCode,
        public ?string $currentCountryCode,
        public ?DateTimeImmutable $previousSeenAt,
        public DateTimeImmutable $occurredAt,
        public string $resolvedTimezone,
        public ?string $geoTimezone,
    ) {}
}
