<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\MfaRecoveryService;
use App\Core\Auth\Application\MfaVerificationException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class MfaRecoveryConfirmAction
{
    public function __invoke(ServerRequestInterface $request, MfaRecoveryService $recovery, ResponseFactory $responses): ResponseInterface
    {
        $input = AuthInput::mfaRecoveryConfirm($request->getParsedBody());
        try {
            $result = $recovery->confirm($input['token'], $input['password'], ClientContext::fromRequest($request));
        } catch (MfaVerificationException) {
            return $responses->error(ApiMessage::AUTH_MFA_INVALID, httpCode: Status::UNAUTHORIZED);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        }
        return $responses->success($result->toArray());
    }
}
