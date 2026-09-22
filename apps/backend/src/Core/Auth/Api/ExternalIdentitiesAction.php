<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\ExternalAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ExternalIdentitiesAction
{
    public function __invoke(ServerRequestInterface $request, ExternalAuthService $external, ResponseFactory $responses): ResponseInterface
    {
        return $responses->success(['items' => $external->identities(AuthenticatedIdentity::fromRequest($request)->accountId)]);
    }
}
