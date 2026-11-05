<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\ExternalAuthenticationException;
use App\Core\Auth\Application\ExternalAuthService;
use App\Core\Auth\Application\ExternalIdentityLastMethodException;
use App\Core\Auth\Application\RecentAuthenticationRequiredException;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ExternalIdentityUnlinkAction
{
    public function __invoke(ServerRequestInterface $request, ExternalAuthService $external, ResponseFactory $responses, CurrentRoute $route): ResponseInterface
    {
        $identity = AuthenticatedIdentity::fromRequest($request);
        try {
            $external->unlink(
                (string) $route->getArgument('provider'),
                $identity->accountId,
                $identity->sessionId,
                ClientContext::fromRequest($request),
            );
        } catch (RecentAuthenticationRequiredException) {
            return $responses->error(ApiMessage::AUTH_RECENT_REQUIRED, httpCode: Status::FORBIDDEN);
        } catch (ExternalIdentityLastMethodException) {
            return $responses->error(ApiMessage::AUTH_LAST_METHOD, httpCode: Status::CONFLICT);
        } catch (ExternalAuthenticationException) {
            return $responses->error(ApiMessage::AUTH_PROVIDER_NOT_LINKED, httpCode: Status::NOT_FOUND);
        }
        return $responses->success(['status' => 'unlinked']);
    }
}
