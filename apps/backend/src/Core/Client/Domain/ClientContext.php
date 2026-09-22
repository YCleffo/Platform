<?php

declare(strict_types=1);

namespace App\Core\Client\Domain;

use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Geo\Domain\GeoLocation;
use App\Core\Time\Application\ResolvedTimeZone;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class ClientContext
{
    public const ATTRIBUTE = self::class;

    public function __construct(
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $acceptLanguage,
        public LocaleContext $locale,
        public ?string $reportedTimezone,
        public ResolvedTimeZone $timezone,
        public ClientHints $clientHints,
        public DeviceInfo $device,
        public ?string $clientInstanceId,
        public ?string $fingerprintHashHex,
        public GeoLocation $geoLocation,
        public string $requestId,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $context = $request->getAttribute(self::ATTRIBUTE);
        if (!$context instanceof self) {
            throw new RuntimeException('ClientContext middleware was not executed.');
        }

        return $context;
    }

    public function withLocale(LocaleContext $locale): self
    {
        return new self(
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            acceptLanguage: $this->acceptLanguage,
            locale: $locale,
            reportedTimezone: $this->reportedTimezone,
            timezone: $this->timezone,
            clientHints: $this->clientHints,
            device: $this->device,
            clientInstanceId: $this->clientInstanceId,
            fingerprintHashHex: $this->fingerprintHashHex,
            geoLocation: $this->geoLocation,
            requestId: $this->requestId,
        );
    }
}
