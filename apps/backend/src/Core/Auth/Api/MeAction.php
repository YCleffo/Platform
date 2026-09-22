<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MeAction
{
    public function __invoke(ServerRequestInterface $request, ResponseFactory $responses): ResponseInterface
    {
        $identity = AuthenticatedIdentity::fromRequest($request);

        return $responses->success([
            'account' => [
                'id' => $identity->accountId,
                'personId' => $identity->personId,
            ],
            'roles' => $identity->roles,
            'permissions' => $identity->permissions,
            'locale' => $identity->locale,
            'timezone' => $identity->timezone,
            'sessionId' => $identity->sessionId,
        ]);
    }
}
