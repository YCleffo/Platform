<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\ExternalAuthenticationException;
use App\Core\Auth\Application\ExternalAuthService;
use App\Core\Auth\Application\RecentAuthenticationRequiredException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Shared\Application\ConflictException;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ExternalIdentityLinkCallbackAction
{
    public function __invoke(ServerRequestInterface $request, ExternalAuthService $external, ResponseFactory $responses, CurrentRoute $route): ResponseInterface
    {
        $identity = AuthenticatedIdentity::fromRequest($request);
        $query = $request->getQueryParams();
        $input = AuthInput::externalCallback(['state' => $query['state'] ?? null, 'code' => $query['code'] ?? null]);
        try {
            $linked = $external->completeLink(
                (string) $route->getArgument('provider'),
                $input['state'],
                $input['code'],
                $identity->accountId,
                $identity->sessionId,
                ClientContext::fromRequest($request),
            );
        } catch (RecentAuthenticationRequiredException) {
            return $responses->error(ApiMessage::AUTH_RECENT_REQUIRED, httpCode: Status::FORBIDDEN);
        } catch (ConflictException) {
            return $responses->error(ApiMessage::AUTH_IDENTITY_CONFLICT, httpCode: Status::CONFLICT);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        } catch (ExternalAuthenticationException) {
            return $responses->error(ApiMessage::AUTH_EXTERNAL_INVALID, httpCode: Status::UNAUTHORIZED);
        }
        return $responses->success($linked->toSafeArray());
    }
}
