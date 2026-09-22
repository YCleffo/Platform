<?php

declare(strict_types=1);

namespace App\Core\Client\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Client\Domain\ClientHints;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Application\LocaleResolver;
use App\Core\Geo\Application\GeoIpService;
use App\Core\Time\Application\TimeZoneResolver;
use App\Core\Time\Domain\TimeZone;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ClientContextResolver
{
    public function __construct(
        private IpAddressResolver $ipAddressResolver,
        private LocaleResolver $localeResolver,
        private TimeZoneResolver $timeZoneResolver,
        private RequestIdFactory $requestIdFactory,
        private DeviceDetectorInterface $deviceDetector,
        private FingerprintService $fingerprintService,
        private GeoIpService $geoIpService,
    ) {}

    public function resolve(
        ServerRequestInterface $request,
        ?string $explicitLocale = null,
        ?string $accountLocale = null,
        ?string $accountTimezone = null,
    ): ClientContext {
        $ipAddress = $this->ipAddressResolver->resolve($request);
        $geoLocation = $this->geoIpService->locate($ipAddress);
        $acceptLanguage = $this->header($request, 'Accept-Language', 1000);
        $explicitLocale ??= $this->header($request, 'X-Client-Locale', 35);
        $resolvedLocale = $this->localeResolver->resolve(
            $explicitLocale,
            $accountLocale,
            $acceptLanguage,
        );
        $reportedTimezone = $this->validTimezone($this->header($request, 'X-Client-Timezone', 64));
        $resolvedTimezone = $this->timeZoneResolver->resolve(
            $accountTimezone,
            $reportedTimezone,
            $geoLocation->timezone,
        );

        $clientHints = new ClientHints(
            brands: $this->header($request, 'Sec-CH-UA', 1000),
            platform: $this->header($request, 'Sec-CH-UA-Platform', 120),
            mobile: $this->mobileHint($request->getHeaderLine('Sec-CH-UA-Mobile')),
        );
        $userAgent = $this->header($request, 'User-Agent', 2000);
        $device = $this->deviceDetector->detect($userAgent, $clientHints);
        $clientInstanceId = $this->uuid($this->header($request, 'X-Client-Instance-ID', 36));

        return new ClientContext(
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            acceptLanguage: $acceptLanguage,
            locale: LocaleContext::fromResolved($resolvedLocale),
            reportedTimezone: $reportedTimezone,
            timezone: $resolvedTimezone,
            clientHints: $clientHints,
            device: $device,
            clientInstanceId: $clientInstanceId,
            fingerprintHashHex: $this->fingerprintService->hashHex($device, $clientHints, $clientInstanceId),
            geoLocation: $geoLocation,
            requestId: $this->requestIdFactory->create($request->getHeaderLine('X-Request-ID') ?: null),
        );
    }

    private function header(ServerRequestInterface $request, string $name, int $maxLength): ?string
    {
        $value = trim($request->getHeaderLine($name));
        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function validTimezone(?string $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        try {
            return (new TimeZone($candidate))->identifier;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function mobileHint(string $value): ?bool
    {
        return match (trim($value)) {
            '?1' => true,
            '?0' => false,
            default => null,
        };
    }

    private function uuid(?string $value): ?string
    {
        return $value !== null
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) === 1
            ? strtolower($value)
            : null;
    }
}
