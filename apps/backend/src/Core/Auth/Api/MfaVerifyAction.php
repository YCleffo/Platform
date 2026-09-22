<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\MfaVerificationException;
use App\Core\Auth\Application\MfaVerificationService;
use App\Core\Auth\Application\LoginRateLimitedException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class MfaVerifyAction
{
    public function __invoke(
        ServerRequestInterface $request,
        MfaVerificationService $verification,
        ResponseFactory $responses,
    ): ResponseInterface {
        $input = AuthInput::mfaVerification($request->getParsedBody());
        try {
            $result = $verification->verify(
                $input['challengeToken'],
                $input['totpCode'],
                $input['recoveryCode'],
                ClientContext::fromRequest($request),
            );
        } catch (MfaVerificationException) {
            return $responses->error(ApiMessage::AUTH_MFA_INVALID, httpCode: Status::UNAUTHORIZED);
        } catch (LoginRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        }

        return $responses->success(['status' => 'authenticated'] + $result->toArray());
    }
}
