<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

final readonly class RequestSizeLimitMiddleware implements MiddlewareInterface
{
    public const int DEFAULT_MAX_BYTES = 5 * 1024 * 1024; // 5MB

    public function __construct(
        private ResponseFactory $responseFactory,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength !== '' && (int) $contentLength > $this->maxBytes) {
            return $this->responseFactory->error(
                ApiMessage::HTTP_PAYLOAD_TOO_LARGE,
                Status::PAYLOAD_TOO_LARGE,
            );
        }

        $bodySize = $request->getBody()->getSize();
        if ($bodySize !== null && $bodySize > $this->maxBytes) {
            return $this->responseFactory->error(
                ApiMessage::HTTP_PAYLOAD_TOO_LARGE,
                Status::PAYLOAD_TOO_LARGE,
            );
        }

        return $handler->handle($request);
    }
}
