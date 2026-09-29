<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class M2mDeploymentStatusHttpTest extends TestCase
{
    public function testRouteRequiresDeploymentMachineAuthentication(): void
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
        $body = '{"status":"ready","entitlementSequence":1}';
        $request = Request::create('/api/m2m/deployment/status', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertSame(401, $kernel->handle($request)->getStatusCode());
        $bearer = Request::create('/api/m2m/deployment/status', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer human-token'], $body);
        self::assertSame(401, $kernel->handle($bearer)->getStatusCode());
        $kernel->shutdown();
    }
}
