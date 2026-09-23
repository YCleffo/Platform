<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\PasskeyRateLimitedException;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Auth\Application\RecentAuthenticationRequiredException;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\RateLimiterUnavailableException;
use App\Core\Shared\Application\ConflictException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class PasskeyRegistrationCompleteAction
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

        $identity = AuthenticatedIdentity::fromRequest($request);
        $input = AuthInput::passkeyRegister($request->getParsedBody());

        try {
            $passkey = $passkeys->completeRegistration(
                $identity->accountId,
                $identity->sessionId,
                $input['responseJson'],
                $input['label'],
                ClientContext::fromRequest($request),
            );
        } catch (RecentAuthenticationRequiredException) {
            return $responses->error(ApiMessage::AUTH_RECENT_REQUIRED, httpCode: Status::FORBIDDEN);
        } catch (PasskeyRateLimitedException) {
            return $responses->error(ApiMessage::AUTH_RATE_LIMITED, httpCode: Status::TOO_MANY_REQUESTS);
        } catch (RateLimiterUnavailableException) {
            return $responses->error(ApiMessage::AUTH_LOGIN_UNAVAILABLE, httpCode: Status::SERVICE_UNAVAILABLE);
        } catch (ConflictException) {
            return $responses->error(ApiMessage::AUTH_PASSKEY_CONFLICT, httpCode: Status::CONFLICT);
        } catch (InvalidArgumentException) {
            return $responses->error(ApiMessage::AUTH_PASSKEY_INVALID, httpCode: Status::BAD_REQUEST);
        }

        return $responses->success([
            'id' => $passkey->id,
            'label' => $passkey->label,
            'createdAt' => $passkey->createdAt,
            'transports' => $passkey->transports,
            'backupEligible' => $passkey->backupEligible,
            'backupState' => $passkey->backupState,
        ])->withStatus(Status::CREATED);
    }
}
