<?php

declare(strict_types=1);

namespace App\Education\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PrivateResponse implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $cacheControl = $response->getHeaderLine('Cache-Control');
        $targetCache = str_contains($cacheControl, 'no-transform') ? 'no-store, no-transform' : 'no-store';
        return $response->withHeader('Cache-Control', $targetCache)->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
