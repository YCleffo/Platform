<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\LoginRateLimitedException;
use App\Core\Auth\Application\RequestPasswordReset;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class RequestPasswordResetAction
{
    public function __invoke(
        ServerRequestInterface $request,
        RequestPasswordReset $reset,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $reset->execute(
                AuthInput::email($request->getParsedBody()),
                ClientContext::fromRequest($request),
            );
        } catch (LoginRateLimitedException) {
            return $responses->error(
                ApiMessage::AUTH_PASSWORD_RESET_RATE_LIMITED,
                httpCode: Status::TOO_MANY_REQUESTS,
            );
        } catch (RateLimiterUnavailableException) {
            return $responses->error(
                ApiMessage::AUTH_PASSWORD_RESET_UNAVAILABLE,
                httpCode: Status::SERVICE_UNAVAILABLE,
            );
        }

        return $responses
            ->success(['accepted' => true])
            ->withStatus(Status::ACCEPTED);
    }
}
