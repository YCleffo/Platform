<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HttpErrorResponsesTest extends TestCase
{
    #[DataProvider('routingErrors')]
    public function testRoutingErrorsKeepTheirHttpStatus(string $method, string $path, int $status, string $code): void
    {
        $kernel = new Kernel('test', false);
        $request = Request::create($path, $method);
        try {
            $response = $kernel->handle($request);
            self::assertSame($status, $response->getStatusCode());
            self::assertSame('application/json', $response->headers->get('Content-Type'));
            $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame($code, $body['error']['code']);
            self::assertStringNotContainsString('No route found', (string) $response->getContent());
            if ($status === 405) {
                self::assertSame('GET', $response->headers->get('Allow'));
            }
            if (str_starts_with($path, '/api/control/')) {
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function routingErrors(): iterable
    {
        yield 'unknown API route' => ['GET', '/api/unknown', 404, 'resource_not_found'];
        yield 'unknown protected route' => ['GET', '/api/control/accounts/missing/unknown', 404, 'resource_not_found'];
        yield 'unsupported catalog method' => ['DELETE', '/api/control/catalog/plans', 405, 'method_not_allowed'];
    }
}
