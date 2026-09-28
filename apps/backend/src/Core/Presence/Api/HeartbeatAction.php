<?php

declare(strict_types=1);

namespace App\Core\Presence\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Presence\Application\PresenceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HeartbeatAction
{
    public function __invoke(ServerRequestInterface $request, PresenceService $presence, ResponseFactory $responses): ResponseInterface
    {
        $accepted = $presence->heartbeat(
            AuthenticatedIdentity::fromRequest($request)->accountId,
            ClientContext::fromRequest($request)->clientInstanceId,
        );
        return $responses->success(['status' => $accepted ? 'online' : 'unknown']);
    }
}
