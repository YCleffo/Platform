<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const SENSITIVE_API_PATH = '~^/api/(?:auth|core)(?:/|$)~';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

        if (preg_match(self::SENSITIVE_API_PATH, $request->getUri()->getPath()) === 1) {
            $response = $response
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Pragma', 'no-cache');
        }

        return $response;
    }
}
