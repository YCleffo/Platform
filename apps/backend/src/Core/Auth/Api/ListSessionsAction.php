<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\ListOwnSessions;
use App\Core\Auth\Application\OwnSessionView;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListSessionsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ListOwnSessions $list,
        ResponseFactory $responses,
    ): ResponseInterface {
        $identity = AuthenticatedIdentity::fromRequest($request);

        return $responses->success(array_map(
            static fn(OwnSessionView $session): array => $session->toArray(),
            $list->execute($identity->accountId, $identity->sessionId),
        ));
    }
}
