<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Client;

use App\Core\Client\Application\ClientContextResolver;
use App\Core\Client\Application\IpAddressResolver;
use App\Core\Client\Application\FingerprintService;
use App\Core\Client\Application\RequestIdFactory;
use App\Core\Client\Application\TrustedProxies;
use App\Core\Geo\Application\GeoIpProviderInterface;
use App\Core\Geo\Application\GeoIpService;
use App\Core\Geo\Domain\GeoLocation;
use App\Core\Internationalization\Application\LocaleNormalizer;
use App\Core\Internationalization\Application\LocaleResolver;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Time\Application\ResolvedTimeZone;
use App\Core\Time\Application\TimeZoneResolver;
use App\Core\Client\Infrastructure\MatomoDeviceDetector;
use Codeception\Test\Unit;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;

final class ClientContextResolverTest extends Unit
{
    public function testResolvesAUnifiedBoundedContext(): void
    {
        $requestId = '018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa';
        $request = new ServerRequest(
            serverParams: ['REMOTE_ADDR' => '192.0.2.10'],
            parsedBody: ['ipAddress' => '198.51.100.99'],
            headers: [
                'Accept-Language' => 'en-GB;q=0.9,ru;q=0.8',
                'User-Agent' => 'Example Browser',
                'X-Client-Timezone' => 'Europe/Helsinki',
                'Sec-CH-UA' => '"Example";v="1"',
                'Sec-CH-UA-Platform' => '"Windows"',
                'Sec-CH-UA-Mobile' => '?0',
                'X-Request-ID' => $requestId,
            ],
        );

        $context = $this->resolver()->resolve($request);

        assertSame('192.0.2.10', $context->ipAddress);
        assertSame('en-US', $context->locale->locale->tag);
        assertSame('Europe/Helsinki', $context->reportedTimezone);
        assertSame(ResolvedTimeZone::CLIENT, $context->timezone->source);
        assertSame('Example Browser', $context->userAgent);
        assertSame('"Windows"', $context->clientHints->platform);
        assertFalse($context->clientHints->mobile);
        assertSame($requestId, $context->requestId);
    }

    public function testInvalidReportedTimezoneFallsBackToDefault(): void
    {
        $request = new ServerRequest(headers: ['X-Client-Timezone' => 'UTC+3']);
        $context = $this->resolver()->resolve($request);

        assertSame(null, $context->reportedTimezone);
        assertSame('UTC', $context->timezone->timezone->identifier);
        assertSame(ResolvedTimeZone::DEFAULT, $context->timezone->source);
    }

    public function testExplicitClientLocaleOverridesBrowserLanguage(): void
    {
        $request = new ServerRequest(headers: [
            'X-Client-Locale' => 'en-US',
            'Accept-Language' => 'ru-RU',
        ]);

        $context = $this->resolver()->resolve($request);

        assertSame('en-US', $context->locale->locale->tag);
        assertSame('explicit', $context->locale->source);
    }

    public function testUsesApproximateGeoIpTimezoneAsNonAuthoritativeFallback(): void
    {
        $request = new ServerRequest(serverParams: ['REMOTE_ADDR' => '8.8.8.8']);
        $context = $this->resolver(new KnownGeoIpProvider())->resolve($request);

        assertSame('FI', $context->geoLocation->countryCode);
        assertSame('Europe/Helsinki', $context->timezone->timezone->identifier);
        assertSame(ResolvedTimeZone::GEOIP, $context->timezone->source);
    }

    private function resolver(?GeoIpProviderInterface $geoIpProvider = null): ClientContextResolver
    {
        $locales = new SupportedLocales(
            new LocaleNormalizer(),
            ['ru-RU', 'en-US'],
            'ru-RU',
        );

        return new ClientContextResolver(
            new IpAddressResolver(new TrustedProxies([])),
            new LocaleResolver($locales),
            new TimeZoneResolver('UTC'),
            new RequestIdFactory(),
            new MatomoDeviceDetector(),
            new FingerprintService(null),
            new GeoIpService($geoIpProvider ?? new KnownGeoIpProvider(GeoLocation::unknown())),
        );
    }
}

final readonly class KnownGeoIpProvider implements GeoIpProviderInterface
{
    public function __construct(
        private GeoLocation $location = new GeoLocation('FI', 'Finland', 'Uusimaa', 'Helsinki', 'Europe/Helsinki'),
    ) {}

    public function locate(string $ipAddress): GeoLocation
    {
        return $this->location;
    }
}
