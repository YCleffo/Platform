<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class M2mUsageHttpTest extends TestCase
{
    public function testRouteUsesDedicatedMachineAuthenticationBoundary(): void
    {
        $url = getenv('CONTROL_DATABASE_URL');
        $parts = is_string($url) ? parse_url($url) : false;
        if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
            || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
            || ($parts['path'] ?? '') !== '/platform_commercial_test') {
            throw new \RuntimeException('M2M HTTP tests require the isolated Commercial test database.');
        }
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $body = json_encode(['featureCode' => 'm2m.counter', 'delta' => 1], JSON_THROW_ON_ERROR);
        $missing = $kernel->handle(Request::create('/api/m2m/usage', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'http-missing',
        ], $body));
        self::assertSame(401, $missing->getStatusCode());
        $missingContent = $missing->getContent();
        self::assertIsString($missingContent);
        self::assertSame('authentication_required', json_decode($missingContent, true)['error']['code']);

        $bearer = $kernel->handle(Request::create('/api/m2m/usage', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'http-bearer',
            'HTTP_AUTHORIZATION' => 'Bearer an-existing-human-session-token',
        ], $body));
        self::assertSame(401, $bearer->getStatusCode());
        $bearerContent = $bearer->getContent();
        self::assertIsString($bearerContent);
        self::assertSame('authentication_required', json_decode($bearerContent, true)['error']['code']);
        $kernel->shutdown();
    }
}
