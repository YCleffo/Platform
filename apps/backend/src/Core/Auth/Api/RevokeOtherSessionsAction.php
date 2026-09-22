<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\RevokeOtherSessions;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RevokeOtherSessionsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        RevokeOtherSessions $revoke,
        ResponseFactory $responses,
    ): ResponseInterface {
        $identity = AuthenticatedIdentity::fromRequest($request);
        $count = $revoke->execute(
            $identity->accountId,
            $identity->sessionId,
            ClientContext::fromRequest($request),
        );

        return $responses->success(['revokedCount' => $count]);
    }
}
