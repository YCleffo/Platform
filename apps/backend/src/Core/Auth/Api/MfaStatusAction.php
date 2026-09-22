<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\TotpManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MfaStatusAction
{
    public function __invoke(ServerRequestInterface $request, TotpManager $manager, ResponseFactory $responses): ResponseInterface
    {
        return $responses->success($manager->status(AuthenticatedIdentity::fromRequest($request)->accountId));
    }
}
