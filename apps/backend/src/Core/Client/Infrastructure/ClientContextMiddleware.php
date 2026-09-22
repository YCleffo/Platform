<?php

declare(strict_types=1);

namespace App\Core\Client\Infrastructure;

use App\Core\Client\Application\ClientContextResolver;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ClientContextMiddleware implements MiddlewareInterface
{
    public function __construct(private ClientContextResolver $resolver) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return $handler->handle($request->withAttribute(
            ClientContext::ATTRIBUTE,
            $this->resolver->resolve($request),
        ));
    }
}
