<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\ExternalAuthenticationException;
use App\Core\Auth\Application\ExternalAuthService;
use App\Core\Auth\Application\LoginRateLimitedException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class MfaRecoveryExternalCallbackAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ExternalAuthService $external,
        ResponseFactory $responses,
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $input = AuthInput::externalCallback(['state' => $query['state'] ?? null, 'code' => $query['code'] ?? null]);
        try {
            $external->completeRecoveryReauthentication(
                (string) $request->getAttribute('provider'),
                $input['state'],
                $input['code'],
                ClientContext::fromRequest($request),
            );
        } catch (LoginRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        } catch (ExternalAuthenticationException) {
            return $responses->error(ApiMessage::AUTH_EXTERNAL_INVALID, httpCode: Status::UNAUTHORIZED);
        }
        return $responses->success(['status' => 'completed']);
    }
}
