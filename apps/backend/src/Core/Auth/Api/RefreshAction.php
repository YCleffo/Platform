<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\RefreshSession;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class RefreshAction
{
    public function __invoke(
        ServerRequestInterface $request,
        RefreshSession $refresh,
        ResponseFactory $responses,
    ): ResponseInterface {
        $result = $refresh->execute(
            AuthInput::refreshToken($request->getParsedBody()),
            ClientContext::fromRequest($request),
        );

        return $result === null
            ? $responses
                ->error(ApiMessage::AUTH_REFRESH_INVALID, httpCode: Status::UNAUTHORIZED)
                ->withHeader('WWW-Authenticate', 'Bearer')
            : $responses->success($result->toArray());
    }
}
