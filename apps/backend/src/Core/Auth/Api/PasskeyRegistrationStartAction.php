<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Auth\Application\RecentAuthenticationRequiredException;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class PasskeyRegistrationStartAction
{
    public function __invoke(
        ServerRequestInterface $request,
        PasskeyService $passkeys,
        ResponseFactory $responses,
    ): ResponseInterface {
        $identity = AuthenticatedIdentity::fromRequest($request);
        try {
            $options = $passkeys->startRegistration(
                $identity->accountId,
                $identity->sessionId,
                ClientContext::fromRequest($request),
            );
        } catch (RecentAuthenticationRequiredException) {
            return $responses->error(ApiMessage::AUTH_RECENT_REQUIRED, httpCode: Status::FORBIDDEN);
        }

        return $responses->success($options);
    }
}
