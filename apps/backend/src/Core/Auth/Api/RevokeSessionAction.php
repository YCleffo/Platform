<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\RevokeOwnSession;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class RevokeSessionAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        RevokeOwnSession $revoke,
        ResponseFactory $responses,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!Uuid::isValid($id)) {
            return $responses->error(ApiMessage::SESSION_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }
        $identity = AuthenticatedIdentity::fromRequest($request);

        return $revoke->execute($identity->accountId, $id, ClientContext::fromRequest($request))
            ? $responses->success()
            : $responses->notFound(ApiMessage::SESSION_NOT_FOUND);
    }
}
