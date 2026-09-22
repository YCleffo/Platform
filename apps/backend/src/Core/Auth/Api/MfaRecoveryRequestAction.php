<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\LoginRateLimitedException;
use App\Core\Auth\Application\MfaRecoveryService;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class MfaRecoveryRequestAction
{
    public function __invoke(ServerRequestInterface $request, MfaRecoveryService $recovery, ResponseFactory $responses): ResponseInterface
    {
        try {
            $recovery->request(AuthInput::email($request->getParsedBody()), ClientContext::fromRequest($request));
        } catch (LoginRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        }
        return $responses->success(['status' => 'accepted'])->withStatus(Status::ACCEPTED);
    }
}
