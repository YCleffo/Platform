<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Shared;

use App\Api\Shared\SecurityHeadersMiddleware;
use Codeception\Test\Unit;
use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddlewareTest extends Unit
{
    public function testAddsSecurityHeadersWithoutEnablingCors(): void
    {
        $response = $this->process('/health');

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame(
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
        $this->assertFalse($response->hasHeader('Cache-Control'));
    }

    /**
     * @dataProvider sensitiveApiPaths
     */
    public function testDisablesCachingForSensitiveApiPaths(string $path): void
    {
        $response = $this->process($path);

        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveApiPaths(): iterable
    {
        yield 'auth root' => ['/api/auth'];
        yield 'login' => ['/api/auth/login'];
        yield 'refresh' => ['/api/auth/refresh'];
        yield 'activation' => ['/api/auth/activate'];
        yield 'password reset request' => ['/api/auth/password-reset/request'];
        yield 'password reset' => ['/api/auth/password-reset/confirm'];
        yield 'current account' => ['/api/auth/me'];
        yield 'own sessions' => ['/api/auth/sessions'];
        yield 'core resource' => ['/api/core/persons'];
    }

    private function process(string $path): ResponseInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(headers: ['Content-Type' => 'application/json']);
            }
        };

        return (new SecurityHeadersMiddleware())->process(
            new ServerRequest(uri: $path),
            $handler,
        );
    }
}
