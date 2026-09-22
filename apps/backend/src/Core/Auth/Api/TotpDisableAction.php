<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\MfaManagementException;
use App\Core\Auth\Application\LoginRateLimitedException;
use App\Core\Auth\Application\RecentAuthenticationRequiredException;
use App\Core\Auth\Application\TotpManager;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class TotpDisableAction
{
    public function __invoke(ServerRequestInterface $request, TotpManager $manager, ResponseFactory $responses): ResponseInterface
    {
        $identity = AuthenticatedIdentity::fromRequest($request);
        $input = AuthInput::totpDisable($request->getParsedBody());
        try {
            $manager->disable(
                $identity->accountId,
                $identity->sessionId,
                $input['password'],
                $input['code'],
                ClientContext::fromRequest($request),
            );
        } catch (RecentAuthenticationRequiredException) {
            return $responses->error(ApiMessage::AUTH_RECENT_REQUIRED, httpCode: Status::FORBIDDEN);
        } catch (MfaManagementException) {
            return $responses->error(ApiMessage::AUTH_MFA_INVALID);
        } catch (LoginRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        }
        return $responses->success(['message' => ApiMessage::AUTH_MFA_UPDATED]);
    }
}
