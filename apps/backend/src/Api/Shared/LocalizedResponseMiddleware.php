<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\DataResponse\DataStream\DataStream;

final readonly class LocalizedResponseMiddleware implements MiddlewareInterface
{
    private const array LOCALIZED_ERROR_STATUSES = [
        400,
        401,
        403,
        404,
        405,
        409,
        413,
        415,
        422,
        429,
        500,
        503,
    ];

    public function __construct(private ResponseFactory $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $status = $response->getStatusCode();

        if (in_array($status, self::LOCALIZED_ERROR_STATUSES, true)
            && !($response->getBody() instanceof DataStream)
        ) {
            $original = $response;
            $response = $this->responses->forStatus($status);
            foreach (['Allow', 'WWW-Authenticate', 'Retry-After'] as $header) {
                if ($original->hasHeader($header)) {
                    $response = $response->withHeader($header, $original->getHeader($header));
                }
            }
        }

        return $this->responses->withLocaleHeaders($response);
    }
}
