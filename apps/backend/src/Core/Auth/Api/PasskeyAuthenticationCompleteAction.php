<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\InvalidCredentialsException;
use App\Core\Auth\Application\PasskeyRateLimitedException;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class PasskeyAuthenticationCompleteAction
{
    public function __invoke(
        ServerRequestInterface $request,
        PasskeyService $passkeys,
        ResponseFactory $responses,
    ): ResponseInterface {
        $contentLength = (int) $request->getHeaderLine('Content-Length');
        if ($contentLength > 32_768) {
            return $responses->error(ApiMessage::HTTP_PAYLOAD_TOO_LARGE, httpCode: Status::PAYLOAD_TOO_LARGE);
        }

        $responseJson = AuthInput::passkeyAuthenticate($request->getParsedBody());

        try {
            $result = $passkeys->completeAuthentication(
                $responseJson,
                ClientContext::fromRequest($request),
            );
        } catch (PasskeyRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
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
