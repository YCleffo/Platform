<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\ExternalAuthService;
use App\Core\Auth\Application\LoginRateLimitedException;
use App\Core\Auth\Application\RecentAuthenticationRequiredException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Shared\Application\ConflictException;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Yiisoft\Http\Status;

final readonly class ExternalIdentityLinkStartAction
{
    public function __invoke(ServerRequestInterface $request, ExternalAuthService $external, ResponseFactory $responses): ResponseInterface
    {
        $identity = AuthenticatedIdentity::fromRequest($request);
        try {
            $result = $external->startLink(
                (string) $request->getAttribute('provider'),
                $identity->accountId,
                $identity->sessionId,
                ClientContext::fromRequest($request),
            );
        } catch (RecentAuthenticationRequiredException) {
            return $responses->error(ApiMessage::AUTH_RECENT_REQUIRED, httpCode: Status::FORBIDDEN);
        } catch (ConflictException) {
            return $responses->error(ApiMessage::AUTH_IDENTITY_CONFLICT, httpCode: Status::CONFLICT);
        } catch (LoginRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        } catch (RuntimeException) {
            return $responses->error(ApiMessage::AUTH_PROVIDER_UNAVAILABLE, httpCode: Status::NOT_FOUND);
        }
        return $responses->success($result->toArray());
    }
}
