<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\InvalidCredentialsException;
use App\Core\Auth\Application\Login;
use App\Core\Auth\Application\LoginRateLimitedException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class LoginAction
{
    public function __invoke(
        ServerRequestInterface $request,
        Login $login,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $result = $login->execute(
                AuthInput::login($request->getParsedBody()),
                ClientContext::fromRequest($request),
            );
        } catch (LoginRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        } catch (InvalidCredentialsException) {
            return $responses
                ->error(ApiMessage::AUTH_INVALID_CREDENTIALS, httpCode: Status::UNAUTHORIZED)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        return $responses->success($result->toArray());
    }
}
